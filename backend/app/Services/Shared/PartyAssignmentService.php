<?php

namespace App\Services\Shared;

use App\Exceptions\BusinessException;
use App\Models\Customer\ClientContact;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\PartyAssignee;
use App\Models\Task\Task;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Task\TaskNotifier;
use App\Services\Task\TaskTreeService;
use App\Support\Party\PartyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assigning a task OR a project to a named person at another company — and
 * deciding what that person is then allowed to see.
 *
 * Both halves live together on purpose. "Who is assigned" and "who can see it"
 * are one rule for an external party and they must never drift: the whole point
 * of assigning Rakesh at Southgate is that Rakesh sees this task and nothing
 * else. Splitting the write from the read is how a system ends up assigning work
 * that the assignee cannot open.
 */
class PartyAssignmentService
{
    public function __construct(
        private TaskTreeService $tree,
        private TaskNotifier $notifier,
    ) {
    }

    /* ── The pickers ────────────────────────────────────────────── */

    /**
     * The teams of one kind — the first stage of the picker.
     *
     * @return array<int,array{id:int,name:string,sublabel:string}>
     */
    public function organisations(string $orgType, int $tenantId, ?string $search = null): array
    {
        $partyType = PartyType::ORG_TO_PARTY[$orgType]
            ?? throw new BusinessException('That is not a kind of team tasks can be assigned into.', 422);

        $cfg = PartyType::config($partyType);
        $labelCol = $cfg['org_label'];

        $rows = $cfg['org_model']::query()
            ->where('tenant_id', $tenantId)
            ->when($search, fn ($q) => $q->where($labelCol, 'like', '%'.$search.'%'))
            ->orderBy($labelCol)
            ->limit(200)
            ->get();

        return $rows->map(fn ($org) => [
            'id'       => (int) $org->id,
            'name'     => (string) ($org->{$labelCol} ?: 'Untitled'),
            // Whatever code this module gives its records, if it gives one.
            'sublabel' => (string) ($org->vendor_code ?? $org->purchase_vendor_code ?? $org->email ?? ''),
        ])->values()->all();
    }

    /**
     * The people inside one team — the second stage.
     *
     * Contacts that can no longer be given work are left out rather than shown
     * greyed: this list exists to be picked from, and an entry that cannot be
     * picked is only ever a misread.
     *
     * @return array<int,array<string,mixed>>
     */
    public function contacts(string $orgType, int $orgId, int $tenantId, ?string $search = null): array
    {
        $partyType = PartyType::ORG_TO_PARTY[$orgType]
            ?? throw new BusinessException('That is not a kind of team tasks can be assigned into.', 422);

        $cfg = PartyType::config($partyType);

        $rows = $cfg['model']::query()
            ->where('tenant_id', $tenantId)
            ->where($cfg['org_fk'], $orgId)
            ->get();

        return $rows
            ->filter(fn ($c) => PartyType::isAssignable($c))
            ->map(fn ($c) => [
                'party_type' => $partyType,
                'party_id'   => (int) $c->id,
                'org_type'   => $orgType,
                'org_id'     => (int) $orgId,
                'name'       => PartyType::nameOf($c),
                'email'      => (string) ($c->email ?? ''),
                'role'       => PartyType::roleOf($c),
            ])
            ->filter(function ($row) use ($search) {
                if (! $search) {
                    return true;
                }

                return str_contains(
                    strtolower($row['name'].' '.$row['email'].' '.$row['role']),
                    strtolower($search)
                );
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    /* ── Writing ────────────────────────────────────────────────── */

    /**
     * Replace the whole party-assignee set on one subject — a task or a project.
     *
     * Replace rather than add, to match syncAssignees(): the screen sends the
     * list it wants to end up with, so removing a chip is the same call as
     * adding one and there is no way for the two to disagree.
     *
     * @param  string  $subjectType  PartyAssignee::SUBJECT_TASK | SUBJECT_PROJECT
     * @param  array<int,array{party_type:string,party_id:int}>  $parties
     */
    public function sync(string $subjectType, int $subjectId, array $parties, int $tenantId, ?int $actorId = null): Collection
    {
        $noun = $subjectType === PartyAssignee::SUBJECT_PROJECT ? 'project' : 'task';
        $resolved = [];

        foreach ($parties as $raw) {
            $type = (string) ($raw['party_type'] ?? '');
            $id   = (int) ($raw['party_id'] ?? 0);

            if (! PartyType::isValidType($type) || $id <= 0) {
                throw new BusinessException("One of the selected people is not a kind of contact a {$noun} can be assigned to.", 422);
            }

            $cfg = PartyType::config($type);

            // Re-read the contact rather than trusting the payload. The name and
            // the company on the request are whatever the browser last saw; the
            // tenant check is the one that matters, because without it an id from
            // another tenant's contact table would be written verbatim.
            $contact = $cfg['model']::query()
                ->where('tenant_id', $tenantId)
                ->find($id);

            if (! $contact) {
                throw new BusinessException('One of the selected people no longer exists.', 422);
            }

            if (! PartyType::isAssignable($contact)) {
                throw new BusinessException(
                    sprintf('%s is no longer active, so work cannot be assigned to them.', PartyType::nameOf($contact)),
                    422
                );
            }

            $resolved[$type.':'.$id] = [
                'tenant_id'    => $tenantId,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'party_type'   => $type,
                'party_id'     => $id,
                'org_type'     => $cfg['org_type'],
                'org_id'       => (int) $contact->{$cfg['org_fk']},
                'name'         => PartyType::nameOf($contact),
                'email'        => ((string) ($contact->email ?? '')) ?: null,
                'assigned_by'  => $actorId,
            ];
        }

        $added = [];

        DB::transaction(function () use ($subjectType, $subjectId, $resolved, &$added) {
            $existing = $this->rowsFor($subjectType, [$subjectId])->get();

            foreach ($existing as $row) {
                $key = $row->party_type.':'.$row->party_id;
                if (! isset($resolved[$key])) {
                    $row->delete();

                    continue;
                }
                // Still assigned — refresh the snapshot, since the contact may
                // have been renamed since this row was written.
                $row->forceFill([
                    'name'  => $resolved[$key]['name'],
                    'email' => $resolved[$key]['email'],
                ])->save();
                unset($resolved[$key]);
            }

            // Whatever is left over is new.
            foreach ($resolved as $attrs) {
                $added[] = PartyAssignee::create($attrs);
            }
        });

        // Only tasks carry a notification today. A project assignment is a
        // standing engagement rather than a piece of work with a due date, and
        // mailing "you are on Project X" through the task mailer would say the
        // wrong thing. Deliberately silent until projects have their own.
        if ($added && $subjectType === PartyAssignee::SUBJECT_TASK) {
            $task = Task::forTenant($tenantId)->find($subjectId);
            if ($task) {
                $this->notifier->partyAssigned($task, $added, $actorId);
            }
        }

        return $this->rowsFor($subjectType, [$subjectId])->orderBy('name')->get();
    }

    /** The chips for one subject, ready for the UI. */
    public function forSubject(string $subjectType, int $subjectId): array
    {
        return $this->rowsFor($subjectType, [$subjectId])
            ->orderBy('name')
            ->get()
            ->map(fn (PartyAssignee $r) => $r->toChip())
            ->all();
    }

    /**
     * The chips for many subjects at once, keyed by id — one query.
     * A board of fifty tasks must not become fifty queries.
     *
     * @param  int[]  $subjectIds
     */
    public function forSubjects(string $subjectType, array $subjectIds): array
    {
        if (! $subjectIds) {
            return [];
        }

        return $this->rowsFor($subjectType, $subjectIds)
            ->orderBy('name')
            ->get()
            ->groupBy('subject_id')
            ->map(fn ($rows) => $rows->map(fn (PartyAssignee $r) => $r->toChip())->values()->all())
            ->all();
    }

    /**
     * Rows for a subject type and a set of ids.
     *
     * Every read goes through here so the subject_type filter can never be
     * forgotten — without it a project and a task that happen to share an id
     * would show each other's people.
     *
     * @param  int[]  $subjectIds
     */
    private function rowsFor(string $subjectType, array $subjectIds)
    {
        return PartyAssignee::where('subject_type', $subjectType)
            ->whereIn('subject_id', $subjectIds);
    }

    /* ── Reading, from the party's own side ─────────────────────── */

    /**
     * Which party (or parties) is this logged-in identity?
     *
     * The three portals authenticate as three different things, and only one of
     * them is the contact record itself:
     *
     *  • a client contact signs in AS the ClientContact — one person, one party;
     *  • a purchase vendor signs in as the PurchaseVendor COMPANY, because the
     *    Purchase portal has no per-contact login. Its identity is therefore
     *    every contact under it;
     *  • a TPV signs in as a User the Vendor record points at — same story.
     *
     * That asymmetry is real and is not papered over: where the system cannot
     * tell two people at a vendor apart, it must not pretend it can. A task
     * assigned to one of that vendor's contacts is visible to that vendor's
     * login, and the row says whose it is. A TPV *employee* who has their own
     * login is assigned through task_assignees like any other user, and already
     * sees only their own.
     *
     * @return array{party:array<int,array{type:string,id:int}>,orgs:array<int,array{type:string,id:int}>}|null
     */
    public function identify(?object $identity): ?array
    {
        if (! $identity) {
            return null;
        }

        if ($identity instanceof ClientContact) {
            return [
                'party' => [['type' => PartyType::CLIENT_CONTACT, 'id' => (int) $identity->id]],
                'orgs'  => [],
            ];
        }

        if ($identity instanceof PurchaseVendor) {
            return [
                'party' => [],
                'orgs'  => [['type' => PartyType::ORG_PURCHASE_VENDOR, 'id' => (int) $identity->id]],
            ];
        }

        if ($identity instanceof User && $identity->role === 'third_party_vendor') {
            $vendorId = Vendor::where('tenant_id', $identity->tenant_id)
                ->where('user_id', $identity->id)
                ->value('id');

            return $vendorId
                ? ['party' => [], 'orgs' => [['type' => PartyType::ORG_TPV_VENDOR, 'id' => (int) $vendorId]]]
                : null;
        }

        return null;
    }

    /**
     * Narrow a task query to what an external party may see: the tasks assigned
     * to them, and everything nested underneath those.
     *
     * The subtree is included because a task assigned to somebody IS the work
     * they were given, and its subtasks are that work broken down — handing
     * someone a parent whose children are invisible gives them a percentage they
     * cannot account for. Nothing else opens: not public tasks, not the rest of
     * their company's board, and not the tree ABOVE what they were handed.
     */
    public function scopeForParty(Builder $query, array $identity, int $tenantId): Builder
    {
        $ids = $this->assignedTaskIds($identity, $tenantId);

        if (! $ids) {
            // Nothing assigned means nothing visible. Returning the query
            // untouched here would hand them the entire tenant.
            return $query->whereRaw('1 = 0');
        }

        $visible = $ids;
        foreach ($ids as $id) {
            $visible = array_merge($visible, $this->tree->descendantIds($id, $tenantId));
        }

        return $query->whereIn('tasks.id', array_values(array_unique($visible)));
    }

    /** The task ids assigned directly to this identity. */
    public function assignedTaskIds(array $identity, int $tenantId): array
    {
        $parties = $identity['party'] ?? [];
        $orgs    = $identity['orgs'] ?? [];

        // An identity that matches nothing must match NOTHING. An empty
        // where(function () {}) is a no-op in Eloquent, which would quietly
        // return every party assignment in the tenant.
        if (! $parties && ! $orgs) {
            return [];
        }

        return PartyAssignee::where('tenant_id', $tenantId)
            // TASKS only. Without this a project assignment contributes its
            // project id to a list that is then used as task ids — and a
            // project and a task can share an id, so the portal would show
            // somebody a task they were never given.
            ->where('subject_type', PartyAssignee::SUBJECT_TASK)
            ->where(function ($outer) use ($parties, $orgs) {
                foreach ($parties as $p) {
                    $outer->orWhere(fn ($x) => $x->where('party_type', $p['type'])->where('party_id', $p['id']));
                }
                foreach ($orgs as $o) {
                    $outer->orWhere(fn ($x) => $x->where('org_type', $o['type'])->where('org_id', $o['id']));
                }
            })
            ->pluck('subject_id')
            ->map(fn ($i) => (int) $i)
            ->unique()
            ->values()
            ->all();
    }

    /** May this identity open this one task? */
    public function canSee(Task $task, array $identity, int $tenantId): bool
    {
        $query = Task::forTenant($tenantId)->where('tasks.id', $task->id);

        return $this->scopeForParty($query, $identity, $tenantId)->exists();
    }
}
