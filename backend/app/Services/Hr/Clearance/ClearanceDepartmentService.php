<?php

namespace App\Services\Hr\Clearance;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrClearanceDepartment as Department;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Editing the exit-clearance departments and the people who may act for them.
 *
 * Separate from ClearanceAuthorityResolver on purpose: that one answers "may
 * this person act", is consulted on every clearance action, and must never
 * write. This one writes configuration and never authorises anything.
 *
 * DELETION IS SAFE and needs no guard against history, for the same reason it
 * does on the onboarding checklist: a clearance item carries its own copy of
 * the department NAME and holds no reference back here. Removing a department
 * stops it appearing on new clearances and leaves every existing item exactly
 * as it was. Deactivation is offered for the commoner case — retiring a step
 * while keeping the record of it.
 */
class ClearanceDepartmentService
{
    public function __construct(private ClearanceAuthorityResolver $authority)
    {
    }

    /** Every department in order, with its authorities and how it resolves. */
    public function list(int $tenantId): array
    {
        $departments = Department::where('tenant_id', $tenantId)
            ->with(['users:id,name,email,status', 'roles:id,name,slug'])
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        return [
            'departments' => $departments->map(fn (Department $d) => $this->present($d))->all(),
            'options'     => [
                // The workspace's own staff roles, including the ones that
                // carry no permissions: a vocabulary-only role still names a
                // real group of people, which is all this needs it to do.
                'roles' => StaffRole::where('tenant_id', $tenantId)
                    ->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($r) => ['value' => $r->id, 'label' => $r->name])->all(),
                'users' => User::where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->whereIn('role', ['admin', 'staff'])
                    ->orderBy('name')->limit(300)->get(['id', 'name', 'email'])
                    ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name.' ('.$u->email.')'])->all(),
            ],
        ];
    }

    public function create(int $tenantId, array $data, ?User $actor = null): array
    {
        $name = $this->assertName($tenantId, $data['name'] ?? null);

        $department = Department::create([
            'tenant_id'    => $tenantId,
            'name'         => $name,
            'is_mandatory' => filter_var($data['is_mandatory'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'sort_order'   => (int) Department::where('tenant_id', $tenantId)->max('sort_order') + 1,
            'is_active'    => true,
            'created_by'   => $actor?->id,
            'updated_by'   => $actor?->id,
        ]);

        $department->recordAudit('Clearance Department Added', $actor, $name);

        return $this->present($department->fresh(['users', 'roles']));
    }

    public function update(int $tenantId, int $id, array $data, ?User $actor = null): array
    {
        $department = $this->find($tenantId, $id);
        $patch = ['updated_by' => $actor?->id];

        if (array_key_exists('name', $data)) {
            // Renaming does NOT touch existing items: they hold their own copy
            // of the old name and keep it. The rename applies from here on.
            $patch['name'] = $this->assertName($tenantId, $data['name'], $department->id);
        }

        if (array_key_exists('is_mandatory', $data)) {
            $patch['is_mandatory'] = filter_var($data['is_mandatory'], FILTER_VALIDATE_BOOLEAN);
        }

        $department->update($patch);
        $department->recordAudit('Clearance Department Updated', $actor, $department->name);

        return $this->present($department->fresh(['users', 'roles']));
    }

    public function setActive(int $tenantId, int $id, bool $active, ?User $actor = null): array
    {
        $department = $this->find($tenantId, $id);

        $department->update(['is_active' => $active, 'updated_by' => $actor?->id]);
        $department->recordAudit(
            $active ? 'Clearance Department Enabled' : 'Clearance Department Disabled',
            $actor, $department->name
        );

        return $this->present($department->fresh(['users', 'roles']));
    }

    public function delete(int $tenantId, int $id, ?User $actor = null): void
    {
        $department = $this->find($tenantId, $id);

        $department->recordAudit('Clearance Department Removed', $actor, $department->name);
        // The authority rows go with it; the clearance items do not, because
        // nothing links them here.
        $department->users()->detach();
        $department->roles()->detach();
        $department->delete();
    }

    public function reorder(int $tenantId, array $ids, ?User $actor = null): array
    {
        $owned = Department::where('tenant_id', $tenantId)->pluck('id')->map(fn ($i) => (int) $i)->all();

        foreach ($ids as $id) {
            if (! in_array((int) $id, $owned, true)) {
                throw new BusinessException('That department does not belong to this workspace.', 422);
            }
        }

        DB::transaction(function () use ($tenantId, $ids, $actor) {
            $order = 0;
            foreach ($ids as $id) {
                Department::where('tenant_id', $tenantId)->whereKey((int) $id)
                    ->update(['sort_order' => $order++, 'updated_by' => $actor?->id]);
            }
        });

        return $this->list($tenantId);
    }

    /**
     * Replace this department's authorities wholesale.
     *
     * The payload IS the configuration — a partial merge would leave a removed
     * authority in place, which on an authorisation surface is the wrong
     * default. Cross-tenant ids are refused rather than skipped, so the screen
     * cannot show an order the server did not store.
     */
    public function setAuthorities(int $tenantId, int $id, array $data, ?User $actor = null): array
    {
        $department = $this->find($tenantId, $id);

        $userIds = array_map('intval', $data['user_ids'] ?? []);
        $roleIds = array_map('intval', $data['staff_role_ids'] ?? []);

        if ($userIds !== []) {
            $valid = User::where('tenant_id', $tenantId)->whereIn('id', $userIds)->pluck('id')
                ->map(fn ($i) => (int) $i)->all();
            if (count($valid) !== count(array_unique($userIds))) {
                throw new BusinessException('One of those users does not belong to this workspace.', 422);
            }
        }

        if ($roleIds !== []) {
            $valid = StaffRole::where('tenant_id', $tenantId)->whereIn('id', $roleIds)->pluck('id')
                ->map(fn ($i) => (int) $i)->all();
            if (count($valid) !== count(array_unique($roleIds))) {
                throw new BusinessException('One of those roles does not belong to this workspace.', 422);
            }
        }

        DB::transaction(function () use ($department, $userIds, $roleIds, $tenantId, $actor) {
            $department->users()->sync($this->withTenant($userIds, $tenantId));
            $department->roles()->sync($this->withTenant($roleIds, $tenantId));
            $department->update(['updated_by' => $actor?->id]);
        });

        $department->recordAudit('Clearance Authorities Updated', $actor, $department->name, [
            'users' => count($userIds), 'roles' => count($roleIds),
        ]);

        return $this->present($department->fresh(['users', 'roles']));
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function withTenant(array $ids, int $tenantId): array
    {
        return collect($ids)->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $tenantId]])->all();
    }

    private function find(int $tenantId, int $id): Department
    {
        $department = Department::where('tenant_id', $tenantId)->find($id);

        if (! $department) {
            throw new BusinessException('Clearance department not found.', 404);
        }

        return $department;
    }

    private function assertName(int $tenantId, $name, ?int $exceptId = null): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new BusinessException('A clearance department needs a name.', 422);
        }

        $clash = Department::where('tenant_id', $tenantId)
            ->where('name', $name)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($clash) {
            // The name is what a clearance item stores, so two departments
            // sharing one would make an item's department ambiguous.
            throw new BusinessException('A department with that name already exists.', 422);
        }

        return mb_substr($name, 0, 100);
    }

    private function present(Department $d): array
    {
        $state = $this->authority->describe((int) $d->tenant_id, $d->name);

        return [
            'id'            => $d->id,
            'name'          => $d->name,
            'is_mandatory'  => (bool) $d->is_mandatory,
            'is_active'     => (bool) $d->is_active,
            'sort_order'    => (int) $d->sort_order,
            'users'         => $d->users->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
            ])->values()->all(),
            'staff_roles'   => $d->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all(),
            // fallback | configured | misconfigured — what the screen needs to
            // tell an administrator whether this department is enforcing, still
            // on the HR queue, or configured with nobody who can actually act.
            'authorization' => $state['mode'],
            'eligible_count'=> count($state['user_ids']),
        ];
    }
}
