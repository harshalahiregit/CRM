<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrPoshCommittee as Committee;
use App\Models\Hr\HrPoshCommitteeMember as Member;
use App\Models\Hr\HrPoshCommitteeRole as Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Configuring a workspace's POSH committee, its seats and who sits in them.
 *
 * NO STATUTORY MINIMUMS. Composition and quorum are whatever a workspace
 * configures. A one-person committee with a quorum of one is valid here. What
 * the law requires is a question for a lawyer, and guessing at it in code would
 * be worse than leaving it configurable.
 *
 * THE INVARIANTS, and why they are refusals rather than warnings.
 *
 * An ACTIVE committee must be able to function: enough active members to meet
 * its own quorum, at least one of them able to open an inquiry. A committee
 * that is switched on and cannot do either is a trap — it looks configured,
 * and the failure only surfaces when somebody is trying to raise a complaint.
 *
 * So an edit that would break an active committee is REFUSED, and every refusal
 * names the way out: deactivate the committee, or appoint somebody. An INACTIVE
 * committee can be edited into any state at all, including an unusable one,
 * because that is how a workspace builds one up in the first place.
 *
 * Every mutation runs inside a transaction and re-checks afterwards, so the
 * check is on the RESULT of the change rather than on a prediction of it. One
 * rule, applied identically whichever field moved.
 */
class PoshCommitteeService
{
    /* ── reading ──────────────────────────────────────────────────────── */

    public function list(int $tenantId): array
    {
        $committees = Committee::where('tenant_id', $tenantId)
            ->with(['roles', 'members.user:id,name,email,status', 'members.role:id,label,can_manage_case'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return [
            'committees' => $committees->map(fn (Committee $c) => $this->present($c))->all(),
            'options'    => [
                'quorum_modes' => [
                    ['value' => Committee::QUORUM_ALL, 'label' => 'All members must agree'],
                    ['value' => Committee::QUORUM_N_OF_M, 'label' => 'A set number must agree'],
                ],
                // Ordinary users. No new role system — eligibility is the rule
                // the rest of HR already applies.
                'users' => User::where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->whereIn('role', ['admin', 'staff'])
                    ->orderBy('name')->limit(300)->get(['id', 'name', 'email'])
                    ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name.' ('.$u->email.')'])->all(),
            ],
        ];
    }

    /* ── committee ────────────────────────────────────────────────────── */

    public function create(int $tenantId, array $data, ?User $actor = null): array
    {
        $name = $this->assertName($tenantId, $data['name'] ?? null);
        [$mode, $quorum] = $this->assertQuorum($data);

        $committee = Committee::create([
            'tenant_id'       => $tenantId,
            'name'            => $name,
            'quorum_mode'     => $mode,
            'quorum_required' => $quorum,
            // Never born active: a committee with no roles and no members
            // could not satisfy its own invariants anyway.
            'is_active'       => false,
            'sort_order'      => (int) Committee::where('tenant_id', $tenantId)->max('sort_order') + 1,
            'created_by'      => $actor?->id,
            'updated_by'      => $actor?->id,
        ]);

        $committee->recordAudit('POSH Committee Created', $actor, $name);

        return $this->present($this->load($committee));
    }

    public function update(int $tenantId, int $id, array $data, ?User $actor = null): array
    {
        $committee = $this->find($tenantId, $id);

        return $this->mutate($committee, $actor, 'POSH Committee Updated', function () use ($committee, $tenantId, $data, $actor) {
            $patch = ['updated_by' => $actor?->id];

            if (array_key_exists('name', $data)) {
                $patch['name'] = $this->assertName($tenantId, $data['name'], $committee->id);
            }

            if (array_key_exists('quorum_mode', $data) || array_key_exists('quorum_required', $data)) {
                [$mode, $quorum] = $this->assertQuorum([
                    'quorum_mode'     => $data['quorum_mode'] ?? $committee->quorum_mode,
                    'quorum_required' => array_key_exists('quorum_required', $data)
                        ? $data['quorum_required'] : $committee->quorum_required,
                ]);
                $patch['quorum_mode'] = $mode;
                $patch['quorum_required'] = $quorum;
            }

            $committee->update($patch);
        });
    }

    /**
     * Switch a committee on or off.
     *
     * Activation is where the invariants are met for the first time, so the
     * same check that guards an edit guards this — with the reasons listed, so
     * the screen can say what is missing rather than just refusing.
     */
    public function setActive(int $tenantId, int $id, bool $active, ?User $actor = null): array
    {
        $committee = $this->find($tenantId, $id);

        return $this->mutate(
            $committee, $actor,
            $active ? 'POSH Committee Activated' : 'POSH Committee Deactivated',
            function () use ($committee, $active, $actor) {
                $committee->update(['is_active' => $active, 'updated_by' => $actor?->id]);
            }
        );
    }

    /** Allowed while nothing references a committee. A case never will in this phase. */
    public function delete(int $tenantId, int $id, ?User $actor = null): void
    {
        $committee = $this->find($tenantId, $id);

        $committee->recordAudit('POSH Committee Removed', $actor, $committee->name);

        DB::transaction(function () use ($committee) {
            // The committee owns its roles and seats outright, so they go with
            // it. Nothing outside points at any of the three.
            Member::where('committee_id', $committee->id)->delete();
            Role::where('committee_id', $committee->id)->delete();
            $committee->delete();
        });
    }

    /* ── roles ────────────────────────────────────────────────────────── */

    public function addRole(int $tenantId, int $committeeId, array $data, ?User $actor = null): array
    {
        $committee = $this->find($tenantId, $committeeId);

        return $this->mutate($committee, $actor, 'POSH Committee Role Added', function () use ($committee, $tenantId, $data) {
            [$key, $label] = $this->assertRole($committee, $data);

            Role::create([
                'tenant_id'       => $tenantId,
                'committee_id'    => $committee->id,
                'key'             => $key,
                'label'           => $label,
                'can_manage_case' => filter_var($data['can_manage_case'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'sort_order'      => (int) Role::where('committee_id', $committee->id)->max('sort_order') + 1,
                'is_active'       => filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ]);
        });
    }

    public function updateRole(int $tenantId, int $committeeId, int $roleId, array $data, ?User $actor = null): array
    {
        $committee = $this->find($tenantId, $committeeId);
        $role = $this->findRole($committee, $roleId);

        return $this->mutate($committee, $actor, 'POSH Committee Role Updated', function () use ($committee, $role, $data) {
            $patch = [];

            if (array_key_exists('key', $data) || array_key_exists('label', $data)) {
                [$key, $label] = $this->assertRole($committee, [
                    'key'   => $data['key'] ?? $role->key,
                    'label' => $data['label'] ?? $role->label,
                ], $role->id);
                $patch['key'] = $key;
                $patch['label'] = $label;
            }

            if (array_key_exists('can_manage_case', $data)) {
                $patch['can_manage_case'] = filter_var($data['can_manage_case'], FILTER_VALIDATE_BOOLEAN);
            }

            if (array_key_exists('is_active', $data)) {
                $patch['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
            }

            $role->update($patch);
        });
    }

    public function deleteRole(int $tenantId, int $committeeId, int $roleId, ?User $actor = null): array
    {
        $committee = $this->find($tenantId, $committeeId);
        $role = $this->findRole($committee, $roleId);

        return $this->mutate($committee, $actor, 'POSH Committee Role Removed', function () use ($role) {
            if (Member::where('role_id', $role->id)->exists()) {
                // Removing a seat title while somebody sits in it would leave a
                // member pointing at nothing.
                throw new BusinessException(
                    "Members are still assigned to \"{$role->label}\". Move them to another role first.",
                    422
                );
            }

            $role->delete();
        });
    }

    /* ── members ──────────────────────────────────────────────────────── */

    /**
     * Replace the whole membership.
     *
     * The payload IS the committee. A partial merge would leave a removed
     * member in place, which on a body that decides complaints is the wrong
     * default. Every entry is validated and an invalid one is REFUSED — never
     * skipped, or the screen would show a committee the server did not store.
     */
    public function setMembers(int $tenantId, int $committeeId, array $rows, ?User $actor = null): array
    {
        $committee = $this->find($tenantId, $committeeId);

        return $this->mutate($committee, $actor, 'POSH Committee Members Updated', function () use ($committee, $tenantId, $rows, $actor) {
            $seen = [];

            foreach ($rows as $row) {
                $userId = (int) ($row['user_id'] ?? 0);
                $roleId = (int) ($row['role_id'] ?? 0);

                if (isset($seen[$userId])) {
                    throw new BusinessException(
                        'One person cannot hold two seats on the same committee.', 422
                    );
                }
                $seen[$userId] = true;

                $this->assertEligibleUser($tenantId, $userId);
                $this->assertRoleBelongs($committee, $roleId);
            }

            Member::where('committee_id', $committee->id)->delete();

            foreach ($rows as $row) {
                Member::create([
                    'tenant_id'    => $tenantId,
                    'committee_id' => $committee->id,
                    'role_id'      => (int) $row['role_id'],
                    'user_id'      => (int) $row['user_id'],
                    'is_active'    => filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'created_by'   => $actor?->id,
                    'updated_by'   => $actor?->id,
                ]);
            }
        });
    }

    /* ── the invariants ───────────────────────────────────────────────── */

    /**
     * Run a change, then hold the result to the rules.
     *
     * Checking afterwards rather than predicting means one rule covers every
     * path into this class — a role deactivated, a member removed, a quorum
     * raised, a committee switched on. The transaction unwinds the change when
     * the result would not stand.
     */
    private function mutate(Committee $committee, ?User $actor, string $auditAction, callable $change): array
    {
        DB::transaction(function () use ($committee, $change, $auditAction, $actor) {
            $change();

            $fresh = $this->load($committee->fresh());

            if ($fresh->is_active) {
                $this->assertUsable($fresh);
            }

            $fresh->recordAudit($auditAction, $actor, $fresh->name);
        });

        return $this->present($this->load($committee->fresh()));
    }

    /**
     * What an ACTIVE committee must be able to do.
     *
     * Each refusal says the way out, because "invalid" on its own leaves
     * somebody guessing at which of three rules they broke.
     */
    private function assertUsable(Committee $committee): void
    {
        $active = $committee->activeMembers()->count();

        // V6
        if ($active === 0) {
            throw new BusinessException(
                "An active committee needs at least one active member. Deactivate \"{$committee->name}\" first, or add a member.",
                422
            );
        }

        // V5
        if (! $committee->hasCaseManager()) {
            throw new BusinessException(
                "An active committee needs at least one active member in a role that can manage cases. "
                ."Deactivate \"{$committee->name}\" first, or appoint another member who can manage cases.",
                422
            );
        }

        // V4
        $quorum = $committee->effectiveQuorum();
        if ($quorum > $active) {
            throw new BusinessException(
                "The quorum of {$quorum} cannot be met by {$active} active member(s). "
                .'Lower the quorum, add members, or deactivate the committee first.',
                422
            );
        }
    }

    /** The same reasons, as a list, so the screen can show what is missing. */
    private function blockers(Committee $committee): array
    {
        try {
            $this->assertUsable($committee);
        } catch (BusinessException $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    /* ── validation helpers ───────────────────────────────────────────── */

    private function assertName(int $tenantId, $name, ?int $exceptId = null): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new BusinessException('A committee needs a name.', 422);
        }

        $clash = Committee::where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($clash) {
            throw new BusinessException('A committee with that name already exists.', 422);
        }

        return mb_substr($name, 0, 150);
    }

    /** @return array{0:string, 1:?int} */
    private function assertQuorum(array $data): array
    {
        $mode = $data['quorum_mode'] ?? Committee::QUORUM_ALL;

        if (! in_array($mode, Committee::QUORUM_MODES, true)) {
            throw new BusinessException('That is not a quorum mode.', 422);
        }

        if ($mode === Committee::QUORUM_ALL) {
            // "Everybody" is not a number, and storing one alongside it would
            // let the two disagree.
            return [$mode, null];
        }

        $required = (int) ($data['quorum_required'] ?? 0);

        if ($required < 1) {
            throw new BusinessException('A set-number quorum needs to be at least one.', 422);
        }

        return [$mode, $required];
    }

    /** @return array{0:string, 1:string} */
    private function assertRole(Committee $committee, array $data, ?int $exceptId = null): array
    {
        $key = trim((string) ($data['key'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));

        if ($label === '') {
            throw new BusinessException('A role needs a label.', 422);
        }

        if ($key === '') {
            $key = \Illuminate\Support\Str::slug($label, '_');
        }

        $clash = Role::where('committee_id', $committee->id)
            ->where('key', $key)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($clash) {
            throw new BusinessException('That role already exists on this committee.', 422);
        }

        return [mb_substr($key, 0, 60), mb_substr($label, 0, 150)];
    }

    /**
     * A member must be somebody who could actually sit on a committee.
     *
     * The eligibility rule the rest of HR already applies: an active account of
     * staff or admin type, in this workspace. internal_role is not consulted —
     * it is a free string every account carries, clients and vendors included.
     */
    private function assertEligibleUser(int $tenantId, int $userId): void
    {
        $ok = User::whereKey($userId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'staff'])
            ->exists();

        if (! $ok) {
            // One message for all three failures on purpose: telling somebody
            // which workspace an id belongs to is more than they asked.
            throw new BusinessException(
                'A committee member must be an active staff account in this workspace.', 422
            );
        }
    }

    private function assertRoleBelongs(Committee $committee, int $roleId): void
    {
        $ok = Role::whereKey($roleId)
            ->where('committee_id', $committee->id)
            ->where('tenant_id', $committee->tenant_id)
            ->exists();

        if (! $ok) {
            throw new BusinessException('That role does not belong to this committee.', 422);
        }
    }

    private function find(int $tenantId, int $id): Committee
    {
        $committee = Committee::where('tenant_id', $tenantId)->find($id);

        if (! $committee) {
            throw new BusinessException('Committee not found.', 404);
        }

        return $this->load($committee);
    }

    private function findRole(Committee $committee, int $roleId): Role
    {
        $role = Role::whereKey($roleId)
            ->where('committee_id', $committee->id)
            ->where('tenant_id', $committee->tenant_id)
            ->first();

        if (! $role) {
            throw new BusinessException('Role not found on this committee.', 404);
        }

        return $role;
    }

    private function load(Committee $committee): Committee
    {
        return $committee->load(['roles', 'members.user:id,name,email,status', 'members.role:id,label,can_manage_case']);
    }

    private function present(Committee $c): array
    {
        return [
            'id'              => $c->id,
            'name'            => $c->name,
            'quorum_mode'     => $c->quorum_mode,
            'quorum_required' => $c->quorum_required,
            'is_active'       => (bool) $c->is_active,
            'sort_order'      => (int) $c->sort_order,
            'active_members'  => $c->activeMembers()->count(),
            'effective_quorum'=> $c->effectiveQuorum(),
            // What stands between this committee and being switched on. Empty
            // when it is ready, so the screen can say so rather than waiting
            // for a failed save to explain it.
            'blockers'        => $this->blockers($c),
            'roles'           => $c->roles->map(fn (Role $r) => [
                'id' => $r->id, 'key' => $r->key, 'label' => $r->label,
                'can_manage_case' => (bool) $r->can_manage_case,
                'is_active' => (bool) $r->is_active, 'sort_order' => (int) $r->sort_order,
            ])->values()->all(),
            'members'         => $c->members->map(fn (Member $m) => [
                'id' => $m->id, 'user_id' => $m->user_id, 'role_id' => $m->role_id,
                'name' => $m->user?->name, 'email' => $m->user?->email,
                'account_status' => $m->user?->status,
                'role_label' => $m->role?->label,
                'can_manage_case' => (bool) ($m->role?->can_manage_case),
                'is_active' => (bool) $m->is_active,
            ])->values()->all(),
        ];
    }
}
