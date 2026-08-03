<?php

namespace App\Services\Helpdesk;

use App\Exceptions\BusinessException;
use App\Models\Helpdesk\HelpdeskSetting;
use App\Models\Helpdesk\TicketDepartment;
use App\Models\Helpdesk\TicketPriority;
use App\Models\Helpdesk\TicketService;
use App\Models\Helpdesk\TicketStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Freshdesk-style "Support Settings" — tenant-managed priorities, statuses and
 * departments plus the per-tenant helpdesk settings row. All values that used to
 * be hardcoded enums are now driven from these lists.
 *
 * Status/priority `name` doubles as the value stored in tickets.status/priority
 * (kept lowercase to match the values already seeded, e.g. 'open', 'in-progress',
 * 'closed', 'merged') so no data migration is needed and validation is simply
 * "value ∈ tenant's configured names".
 */
class HelpdeskSettingsService
{
    /** Default lists seeded on first access so a fresh tenant is never empty. */
    private const DEFAULT_PRIORITIES = [
        ['name' => 'low',    'color' => '#10b981', 'order' => 0, 'is_default' => false, 'first_response_hours' => 8, 'resolution_hours' => 48],
        ['name' => 'medium', 'color' => '#f59e0b', 'order' => 1, 'is_default' => true,  'first_response_hours' => 4, 'resolution_hours' => 24],
        ['name' => 'high',   'color' => '#ef4444', 'order' => 2, 'is_default' => false, 'first_response_hours' => 2, 'resolution_hours' => 8],
        ['name' => 'urgent', 'color' => '#dc2626', 'order' => 3, 'is_default' => false, 'first_response_hours' => 1, 'resolution_hours' => 4],
    ];

    private const DEFAULT_STATUSES = [
        ['name' => 'open',        'color' => '#3b82f6', 'order' => 0, 'is_closed_status' => false, 'sla_paused' => false],
        ['name' => 'in-progress', 'color' => '#8b5cf6', 'order' => 1, 'is_closed_status' => false, 'sla_paused' => false],
        // Waiting on the customer — the SLA clock pauses here.
        ['name' => 'pending',     'color' => '#f59e0b', 'order' => 2, 'is_closed_status' => false, 'sla_paused' => true],
        ['name' => 'closed',      'color' => '#10b981', 'order' => 3, 'is_closed_status' => true,  'sla_paused' => false],
        // Special status used by the Phase 3 ticket-merge flow.
        ['name' => 'merged',      'color' => '#6b7280', 'order' => 4, 'is_closed_status' => true,  'sla_paused' => false],
    ];

    private const DEFAULT_DEPARTMENTS = [
        ['name' => 'General',   'description' => 'General support requests', 'order' => 0],
        ['name' => 'Billing',   'description' => 'Payments and invoices',    'order' => 1],
        ['name' => 'Technical', 'description' => 'Product and technical issues', 'order' => 2],
    ];

    private const DEFAULT_SERVICES = [
        ['name' => 'Website',  'order' => 0],
        ['name' => 'Software', 'order' => 1],
        ['name' => 'Hardware', 'order' => 2],
        ['name' => 'Other',    'order' => 3],
    ];

    public function __construct(private \App\Services\NotificationService $notifications)
    {
    }

    /**
     * Diff a ticket-manager roster and tell the people who changed. A newly
     * appointed manager gets an in-app notification AND an email; a removed one is
     * told they lost the access. Only real, active, internal users are contacted;
     * the admin who made the change is self-suppressed in-app. Mail is queued (the
     * mailable is ShouldQueue), so appointing managers never blocks the save.
     *
     * @param  array<int>  $oldIds
     * @param  array<int>  $newIds
     */
    private function syncManagerRoster(array $oldIds, array $newIds, int $tenantId, string $scopeLabel): void
    {
        $old = collect($oldIds)->map(fn ($i) => (int) $i)->unique();
        $new = collect($newIds)->map(fn ($i) => (int) $i)->unique();

        $added   = $new->diff($old)->values()->all();
        $removed = $old->diff($new)->values()->all();
        if (! $added && ! $removed) {
            return;
        }

        $users = \App\Models\User::where('tenant_id', $tenantId)
            ->whereIn('id', array_merge($added, $removed))
            ->where('status', 'active')
            ->whereNotIn('role', ['client', 'vendor', 'third_party_vendor'])
            ->get(['id', 'name', 'email'])->keyBy('id');

        $actorId = auth()->id();

        $tell = function (int $uid, bool $appointed) use ($users, $tenantId, $scopeLabel, $actorId) {
            $user = $users->get($uid);
            if (! $user) {
                return;
            }
            $this->notifications->notify(
                userId: $uid,
                tenantId: $tenantId,
                type: $appointed ? 'helpdesk.manager_appointed' : 'helpdesk.manager_removed',
                title: $appointed
                    ? "You're now a ticket manager for {$scopeLabel}"
                    : "You're no longer a ticket manager for {$scopeLabel}",
                message: $appointed
                    ? 'You can now see, assign and manage tickets here.'
                    : 'Your ticket-manager access here has been removed.',
                link: '/app/helpdesk/tickets',
                actorId: $actorId,
            );
            if ($user->email) {
                \Illuminate\Support\Facades\Mail::to($user->email)->send(
                    new \App\Mail\Helpdesk\TicketManagerAppointmentMail($user->name ?: 'there', $scopeLabel, $appointed)
                );
            }
        };

        foreach ($added as $uid) {
            $tell($uid, true);
        }
        foreach ($removed as $uid) {
            $tell($uid, false);
        }
    }

    /** Everything the ticket form + settings screen need, in one call. */
    public function bundle(int $tenantId): array
    {
        $this->ensureSeeded($tenantId);

        return [
            'priorities'  => TicketPriority::forTenant($tenantId)->orderBy('order')->get(),
            'statuses'    => TicketStatus::forTenant($tenantId)->orderBy('order')->get(),
            'departments' => $this->departmentsWithAgents($tenantId),
            'services'    => TicketService::forTenant($tenantId)->orderBy('order')->get(),
            'settings'    => $this->settings($tenantId),
        ];
    }

    /**
     * Departments, each decorated with the people the admin assigned to it
     * (manager_ids) resolved to {id, name}. This is what lets the ticket form show
     * WHO handles a department the moment it's picked — a ticket raised against a
     * department is already notified to and visible to exactly these people
     * (ticketManagerIds + department-manager visibility), so surfacing them here
     * makes that routing visible instead of implicit. One name lookup for every id
     * across all departments, never one query per department.
     */
    public function departmentsWithAgents(int $tenantId): \Illuminate\Support\Collection
    {
        $departments = TicketDepartment::forTenant($tenantId)->orderBy('order')->get();

        $allIds = $departments
            ->flatMap(fn (TicketDepartment $d) => array_map('intval', $d->manager_ids ?? []))
            ->unique()->values()->all();

        $names = $allIds
            ? \App\Models\User::where('tenant_id', $tenantId)
                ->whereIn('id', $allIds)
                ->where('status', 'active')
                ->whereNotIn('role', ['client', 'vendor', 'third_party_vendor'])
                ->pluck('name', 'id')
            : collect();

        return $departments->each(function (TicketDepartment $d) use ($names) {
            $agents = collect(array_map('intval', $d->manager_ids ?? []))
                ->filter(fn ($id) => isset($names[$id]))
                ->map(fn ($id) => ['id' => $id, 'name' => $names[$id]])
                ->values()->all();
            $d->setAttribute('managers', $agents);
        });
    }

    /**
     * Idempotently create default lists + settings row for a tenant.
     *
     * This was a plain check-then-act: two requests hitting a fresh tenant at the
     * same time (two tabs opening the ticket form) both passed `! exists()` before
     * either inserted, so the tenant ended up with doubled priorities/statuses —
     * which then broke the Rule::in() validation that assumes unique names.
     *
     * An atomic lock makes the whole seed run once; the second caller waits, then
     * sees the rows already there and does nothing. firstOrCreate on each row is a
     * second line of defence if the cache driver ever can't lock.
     */
    public function ensureSeeded(int $tenantId): void
    {
        Cache::lock("helpdesk:seed:{$tenantId}", 10)->block(10, function () use ($tenantId) {
            if (! TicketPriority::forTenant($tenantId)->exists()) {
                foreach (self::DEFAULT_PRIORITIES as $row) {
                    TicketPriority::firstOrCreate(
                        ['tenant_id' => $tenantId, 'name' => $row['name']],
                        [...$row, 'tenant_id' => $tenantId]
                    );
                }
            }
            if (! TicketStatus::forTenant($tenantId)->exists()) {
                foreach (self::DEFAULT_STATUSES as $row) {
                    TicketStatus::firstOrCreate(
                        ['tenant_id' => $tenantId, 'name' => $row['name']],
                        [...$row, 'tenant_id' => $tenantId]
                    );
                }
            }
            if (! TicketDepartment::forTenant($tenantId)->exists()) {
                foreach (self::DEFAULT_DEPARTMENTS as $row) {
                    TicketDepartment::firstOrCreate(
                        ['tenant_id' => $tenantId, 'name' => $row['name']],
                        [...$row, 'tenant_id' => $tenantId]
                    );
                }
            }
            if (! TicketService::forTenant($tenantId)->exists()) {
                foreach (self::DEFAULT_SERVICES as $row) {
                    TicketService::firstOrCreate(
                        ['tenant_id' => $tenantId, 'name' => $row['name']],
                        [...$row, 'tenant_id' => $tenantId]
                    );
                }
            }
            $this->settings($tenantId); // creates the settings row if missing
        });
    }

    public function settings(int $tenantId): HelpdeskSetting
    {
        return HelpdeskSetting::firstOrCreate(['tenant_id' => $tenantId]);
    }

    public function updateSettings(int $tenantId, array $data): HelpdeskSetting
    {
        $settings = $this->settings($tenantId);

        // Appointing/removing tenant-wide ticket managers → tell whoever changed.
        $rosterChanged = array_key_exists('ticket_manager_ids', $data);
        $oldIds = $rosterChanged ? array_map('intval', $settings->ticket_manager_ids ?? []) : [];

        $settings->fill($data)->save();

        if ($rosterChanged) {
            $this->syncManagerRoster($oldIds, array_map('intval', $data['ticket_manager_ids'] ?? []), $tenantId, 'the whole helpdesk');
        }

        return $settings->fresh('defaultDepartment');
    }

    /** Allowed value sets used by ticket FormRequests (never a hardcoded in:). */
    public function priorityNames(int $tenantId): array
    {
        $this->ensureSeeded($tenantId);

        return TicketPriority::forTenant($tenantId)->pluck('name')->all();
    }

    public function statusNames(int $tenantId): array
    {
        $this->ensureSeeded($tenantId);

        return TicketStatus::forTenant($tenantId)->pluck('name')->all();
    }

    /* ── Generic list CRUD (priorities | statuses | departments) ── */

    private const MODELS = [
        'priorities'  => TicketPriority::class,
        'statuses'    => TicketStatus::class,
        'departments' => TicketDepartment::class,
        'services'    => TicketService::class,
    ];

    private function modelFor(string $type): string
    {
        if (! isset(self::MODELS[$type])) {
            throw new BusinessException("Unknown settings list: {$type}", 404);
        }

        return self::MODELS[$type];
    }

    public function createItem(string $type, array $data, int $tenantId)
    {
        $model = $this->modelFor($type);
        $data['order'] = $data['order'] ?? ((int) $model::forTenant($tenantId)->max('order') + 1);

        return $model::create([...$data, 'tenant_id' => $tenantId]);
    }

    public function updateItem(string $type, int $id, array $data, int $tenantId)
    {
        $item = $this->findItem($type, $id, $tenantId);

        // Appointing/removing a department's ticket managers → tell whoever changed.
        $rosterChanged = $type === 'departments' && array_key_exists('manager_ids', $data);
        $oldIds = $rosterChanged ? array_map('intval', $item->manager_ids ?? []) : [];

        $item->fill($data)->save();

        if ($rosterChanged) {
            $this->syncManagerRoster($oldIds, array_map('intval', $data['manager_ids'] ?? []), $tenantId, "the {$item->name} department");
        }

        return $item->fresh();
    }

    public function deleteItem(string $type, int $id, int $tenantId): void
    {
        $this->findItem($type, $id, $tenantId)->delete();
    }

    /** Persist a new ordering: [id, id, id, ...] → order index. */
    public function reorder(string $type, array $orderedIds, int $tenantId): void
    {
        $model = $this->modelFor($type);
        DB::transaction(function () use ($model, $orderedIds, $tenantId) {
            foreach (array_values($orderedIds) as $i => $id) {
                $model::forTenant($tenantId)->where('id', $id)->update(['order' => $i]);
            }
        });
    }

    private function findItem(string $type, int $id, int $tenantId)
    {
        $item = $this->modelFor($type)::forTenant($tenantId)->find($id);
        if (! $item) {
            throw new BusinessException(ucfirst($type).' item not found.', 404);
        }

        return $item;
    }
}
