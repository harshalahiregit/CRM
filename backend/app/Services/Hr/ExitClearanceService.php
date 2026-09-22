<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrClearanceDepartment;
use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitClearanceItem;
use App\Models\Hr\HrExitRequest;
use App\Models\User;
use App\Repositories\Hr\ClearanceRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Exit Clearance Management (Exit Phase 4). Only Approved exit requests enter
 * clearance; each gets a parent record plus a departmental checklist (HR / IT /
 * Admin / Finance / Reporting Manager). Every department moves independently
 * (Pending → In Progress → Cleared / Rejected); the overall clearance completes
 * only when all mandatory departments are Cleared, and is Rejected if any
 * mandatory department is Rejected. Tenant-scoped, audited. Clearance records are
 * lazily created for newly-approved exits when the queue is read — Phase 3 is
 * left untouched.
 */
class ExitClearanceService
{
    public function __construct(
        private ClearanceRepository $repo,
        // Who may act for a given department. One canManageHrQueue() check
        // used to cover all five, so anybody on the HR queue could clear IT.
        private \App\Services\Hr\Clearance\ClearanceAuthorityResolver $authority,
    ) {
    }

    public function queue(int $tenantId, array $f, ?User $actor = null): array
    {
        $this->ensureForApproved($tenantId, $actor);

        return [
            'stats' => $this->repo->stats($tenantId, $actor),
            'rows'  => $this->repo->queue($tenantId, $f, $actor)->map(fn ($c) => $this->present($c))->all(),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $clearance = $this->find($id, $tenantId, $actor);
        $clearance->recordAudit('Clearance Viewed', $actor);

        return $this->present($clearance, true);
    }

    public function history(int $tenantId, array $f, ?User $actor = null): array
    {
        return $this->repo->history($tenantId, $f, $actor)->map(fn ($c) => $this->present($c))->all();
    }

    public function forEmployee(int $employeeId, int $tenantId, ?User $actor = null): ?array
    {
        $clearance = $this->repo->findByEmployee($employeeId, $tenantId, $actor);

        return $clearance ? $this->present($clearance, true) : null;
    }

    /* ── Departmental actions ─────────────────────────────── */

    public function startItem(int $clearanceId, int $itemId, array $data, int $tenantId, ?User $actor = null): array
    {
        $clearance = $this->find($clearanceId, $tenantId, $actor);
        $this->assertNotReadOnly($clearance);
        $item = $this->item($clearance, $itemId, $actor);

        if ($item->status !== HrExitClearanceItem::PENDING) {
            throw new BusinessException("{$item->department} clearance has already been started.");
        }
        $item->update([
            'status'      => HrExitClearanceItem::IN_PROGRESS,
            'started_at'  => now(),
            'assigned_to' => $data['assigned_to'] ?? $item->assigned_to,
            'remarks'     => $data['remarks'] ?? $item->remarks,
            'updated_by'  => $actor?->id,
        ]);
        $clearance->recordAudit('Clearance Started', $actor, $data['remarks'] ?? null, ['department' => $item->department]);
        $this->recompute($clearance, $actor);
        $this->log('Clearance started', $tenantId, $clearance->id);

        return $this->present($this->find($clearanceId, $tenantId, $actor), true);
    }

    public function clearItem(int $clearanceId, int $itemId, array $data, int $tenantId, ?User $actor = null): array
    {
        $clearance = $this->find($clearanceId, $tenantId, $actor);
        $this->assertNotReadOnly($clearance);
        $item = $this->item($clearance, $itemId, $actor);
        $this->assertDecidable($item);

        $item->update([
            'status'     => HrExitClearanceItem::CLEARED,
            'decided_at' => now(),
            'remarks'    => $data['remarks'] ?? $item->remarks,
            'updated_by' => $actor?->id,
        ]);
        $clearance->recordAudit('Department Cleared', $actor, $data['remarks'] ?? null, ['department' => $item->department]);
        $this->recompute($clearance, $actor);
        $this->log('Department cleared', $tenantId, $clearance->id);

        return $this->present($this->find($clearanceId, $tenantId, $actor), true);
    }

    public function rejectItem(int $clearanceId, int $itemId, array $data, int $tenantId, ?User $actor = null): array
    {
        $clearance = $this->find($clearanceId, $tenantId, $actor);
        $this->assertNotReadOnly($clearance);
        $item = $this->item($clearance, $itemId, $actor);
        $this->assertDecidable($item);

        $item->update([
            'status'     => HrExitClearanceItem::REJECTED,
            'decided_at' => now(),
            'remarks'    => $data['remarks'] ?? $item->remarks,
            'updated_by' => $actor?->id,
        ]);
        $clearance->recordAudit('Department Rejected', $actor, $data['remarks'] ?? null, ['department' => $item->department]);
        $this->recompute($clearance, $actor);
        $this->log('Department rejected', $tenantId, $clearance->id);

        return $this->present($this->find($clearanceId, $tenantId, $actor), true);
    }

    public function updateItemRemarks(int $clearanceId, int $itemId, array $data, int $tenantId, ?User $actor = null): array
    {
        $clearance = $this->find($clearanceId, $tenantId, $actor);
        $this->assertNotReadOnly($clearance);
        $item = $this->item($clearance, $itemId, $actor);
        if (in_array($item->status, [HrExitClearanceItem::CLEARED, HrExitClearanceItem::REJECTED], true)) {
            throw new BusinessException("{$item->department} clearance is already decided and cannot be edited.");
        }
        $item->update(['remarks' => $data['remarks'] ?? null, 'assigned_to' => $data['assigned_to'] ?? $item->assigned_to, 'updated_by' => $actor?->id]);
        $clearance->recordAudit('Clearance Updated', $actor, $data['remarks'] ?? null, ['department' => $item->department]);

        return $this->present($this->find($clearanceId, $tenantId, $actor), true);
    }

    /* ── Lazy initialisation ──────────────────────────────── */

    private function ensureForApproved(int $tenantId, ?User $actor): void
    {
        $pending = $this->repo->approvedExitsNeedingClearance($tenantId);
        foreach ($pending as $exit) {
            $exit->loadMissing('employee');
            DB::transaction(function () use ($exit, $tenantId, $actor) {
                $clearance = HrExitClearance::create([
                    'tenant_id' => $tenantId,
                    'exit_request_id' => $exit->id,
                    'employee_id' => $exit->employee_id,
                    'status' => HrExitClearance::PENDING,
                    'created_by' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
                foreach ($this->departmentsFor($tenantId) as $dept) {
                    HrExitClearanceItem::create([
                        'tenant_id' => $tenantId,
                        'clearance_id' => $clearance->id,
                        'department' => $dept['name'],
                        'is_mandatory' => $dept['is_mandatory'],
                        'status' => HrExitClearanceItem::PENDING,
                        'assigned_to' => $dept['name'] === 'Reporting Manager' ? ($exit->employee?->reporting_manager_name ?: null) : null,
                        'created_by' => $actor?->id,
                        'updated_by' => $actor?->id,
                    ]);
                }
                $clearance->recordAudit('Clearance Initiated', $actor, null, ['employee' => $exit->employee?->name]);
            });
        }
    }

    /* ── Overall status derivation ────────────────────────── */

    private function recompute(HrExitClearance $clearance, ?User $actor): void
    {
        $items = $clearance->items()->get();
        $mandatory = $items->where('is_mandatory', true);

        $new = HrExitClearance::PENDING;
        if ($mandatory->contains(fn ($i) => $i->status === HrExitClearanceItem::REJECTED)) {
            $new = HrExitClearance::REJECTED;
        } elseif ($mandatory->isNotEmpty() && $mandatory->every(fn ($i) => $i->status === HrExitClearanceItem::CLEARED)) {
            $new = HrExitClearance::COMPLETED;
        } elseif ($items->contains(fn ($i) => $i->status !== HrExitClearanceItem::PENDING)) {
            $new = HrExitClearance::IN_PROGRESS;
        }

        $was = $clearance->status;
        $patch = ['status' => $new, 'updated_by' => $actor?->id];
        if ($new !== HrExitClearance::PENDING && ! $clearance->started_at) {
            $patch['started_at'] = now();
        }
        if ($new === HrExitClearance::COMPLETED && $was !== HrExitClearance::COMPLETED) {
            $patch['completed_at'] = now();
        }
        $clearance->update($patch);

        if ($new === HrExitClearance::COMPLETED && $was !== HrExitClearance::COMPLETED) {
            $clearance->recordAudit('Overall Clearance Completed', $actor, null, ['employee' => $clearance->employee?->name]);
        }
    }

    /* ── Guards + helpers ─────────────────────────────────── */

    private function assertNotReadOnly(HrExitClearance $clearance): void
    {
        if ($clearance->status === HrExitClearance::COMPLETED) {
            throw new BusinessException('This clearance is complete and is now read-only.');
        }
    }

    private function assertDecidable(HrExitClearanceItem $item): void
    {
        if ($item->status === HrExitClearanceItem::CLEARED) {
            throw new BusinessException("{$item->department} has already been cleared.");
        }
        if ($item->status === HrExitClearanceItem::REJECTED) {
            throw new BusinessException("{$item->department} clearance has already been rejected.");
        }
        if ($item->status !== HrExitClearanceItem::IN_PROGRESS) {
            throw new BusinessException("Start the {$item->department} clearance before recording a decision.");
        }
    }

    private function present(HrExitClearance $c, bool $full = false): array
    {
        // Three tiers: the workspace's configured order, then the original
        // five for a legacy value the master no longer carries, then the name.
        // array_search returning false for an unknown department is why this
        // is not a one-liner any more — false sorts as 0 and would silently
        // put a retired department first.
        $order = $this->departmentOrder((int) $c->tenant_id);
        $items = $c->items->sortBy(function ($i) use ($order) {
            $at = array_search($i->department, $order, true);
            if ($at !== false) {
                return sprintf('1%06d', $at);
            }

            $legacy = array_search($i->department, HrExitClearanceItem::DEPARTMENTS, true);

            return $legacy !== false ? sprintf('2%06d', $legacy) : '3'.$i->department;
        })->values();
        $mandatory = $items->where('is_mandatory', true);
        $clearedCount = $mandatory->where('status', HrExitClearanceItem::CLEARED)->count();
        $current = $items->first(fn ($i) => $i->status !== HrExitClearanceItem::CLEARED);
        $lastUpdated = $items->max('updated_at') ?: $c->updated_at;
        $exit = $c->exitRequest;

        $out = [
            'id' => $c->id,
            'exit_request_id' => $c->exit_request_id,
            'employee_id' => $c->employee_id,
            'employee_name' => $c->employee?->name,
            'employee_code' => $c->employee?->employee_code,
            'department' => $c->employee?->department,
            'designation' => $c->employee?->designation,
            'exit_type' => $exit?->exitType?->name,
            'approval_status' => $exit?->status,
            'notice_days' => $exit?->notice_days,
            'notice_start_date' => optional($exit?->notice_start_date)->toDateString(),
            'notice_end_date' => optional($exit?->notice_end_date)->toDateString(),
            'last_working_date' => optional($exit?->last_working_date)->toDateString(),
            'status' => $c->status,
            'progress' => ['cleared' => $clearedCount, 'total' => $mandatory->count()],
            'current_stage' => $c->status === HrExitClearance::COMPLETED ? 'Completed' : ($current?->department ?? '—'),
            'assigned_to' => $current?->assigned_to,
            'started_at' => optional($c->started_at)->toIso8601String(),
            'completed_at' => optional($c->completed_at)->toIso8601String(),
            'last_updated' => optional($lastUpdated)->toIso8601String(),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id, 'department' => $i->department, 'is_mandatory' => $i->is_mandatory,
                'status' => $i->status, 'assigned_to' => $i->assigned_to, 'remarks' => $i->remarks,
                'started_at' => optional($i->started_at)->toIso8601String(),
                'decided_at' => optional($i->decided_at)->toIso8601String(),
                // How this department is authorised, so the screen can say
                // "HR queue fallback" or "nobody is configured" without a
                // second call. The MODE only — never the user list, which is
                // configuration and has no business in a clearance payload.
                'authorization' => $this->authority->mode((int) $c->tenant_id, (string) $i->department),
            ])->all(),
        ];

        if ($full) {
            $out['timeline'] = $c->relationLoaded('auditLogs')
                ? $c->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                    'action' => $l->action, 'actor_name' => $l->actor_name,
                    'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                ])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId, ?User $actor = null): HrExitClearance
    {
        $clearance = $this->repo->find($id, $tenantId, $actor);
        if (! $clearance) {
            throw new BusinessException('Clearance not found', 404);
        }

        return $clearance;
    }

    /**
     * The departments a NEW clearance is opened with.
     *
     * From the workspace's own master, falling back to the original five when
     * it has configured none — a tenant created after the master arrived, or
     * one restored from a soft delete, still opens a usable clearance rather
     * than an empty one. The same shape the onboarding checklist uses.
     *
     * Only the ACTIVE departments are seeded: deactivating one stops it
     * appearing on new clearances without touching any that already exist.
     *
     * @return array<int, array{name:string, is_mandatory:bool}>
     */
    private function departmentsFor(int $tenantId): array
    {
        $configured = HrClearanceDepartment::where('tenant_id', $tenantId)
            ->active()
            ->orderBy('sort_order')->orderBy('id')
            ->get(['name', 'is_mandatory']);

        if ($configured->isNotEmpty()) {
            return $configured->map(fn ($d) => [
                'name' => $d->name, 'is_mandatory' => (bool) $d->is_mandatory,
            ])->all();
        }

        return array_map(
            fn ($name) => ['name' => $name, 'is_mandatory' => true],
            HrExitClearanceItem::DEPARTMENTS
        );
    }

    /**
     * Where an item sits in the list.
     *
     * Three tiers, because a clearance can outlive the configuration it was
     * opened under. The workspace's own order first; then the original five,
     * so a legacy item keeps the position it has always had; then the name, so
     * a department retired long ago still sorts somewhere predictable instead
     * of wherever array_search's `false` happens to land it.
     */
    private function departmentOrder(int $tenantId): array
    {
        $configured = HrClearanceDepartment::where('tenant_id', $tenantId)
            ->orderBy('sort_order')->orderBy('id')
            ->pluck('name')->all();

        return $configured ?: HrExitClearanceItem::DEPARTMENTS;
    }

    /**
     * The one door every clearance mutation goes through.
     *
     * start, clear, reject and remarks all arrive here, so the departmental
     * authority check sits here rather than in four places — and a fifth
     * mutation added later inherits it instead of being forgotten.
     *
     * The order is deliberate. Tenancy and employee data scope have already
     * run in find(), and answer "may you see this clearance at all" with a 404.
     * This answers the separate question "may you act for THIS department",
     * and answers it with a 403: the clearance is legitimately visible to this
     * person, so pretending it is absent would be the wrong story.
     */
    private function item(HrExitClearance $clearance, int $itemId, ?User $actor = null): HrExitClearanceItem
    {
        $item = $clearance->items->firstWhere('id', $itemId);
        if (! $item) {
            throw new BusinessException('Clearance department not found for this exit.', 404);
        }

        if ($actor) {
            $this->authority->assertMayAct($actor, (string) $item->department);
        }

        return $item;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
