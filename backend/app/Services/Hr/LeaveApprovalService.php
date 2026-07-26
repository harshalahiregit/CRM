<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrLeaveApplication;
use App\Models\User;
use App\Repositories\Hr\EmployeeLeaveBalanceRepository;
use App\Repositories\Hr\LeaveApplicationRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Leave Approval workflow (Leave Phase 4).
 *
 * Operates on the leave application lifecycle — no separate approval entity.
 * Approve verifies the application is Submitted, that a sufficient active balance
 * exists, then deducts through the existing EmployeeLeaveBalanceService (one
 * immutable ledger transaction). Reject/Cancelled never touch balance. Re-approval
 * is impossible (status guard), so a leave can never be deducted twice.
 */
class LeaveApprovalService
{
    public function __construct(
        private LeaveApplicationRepository $repo,
        private EmployeeLeaveBalanceService $balanceService,
        private EmployeeLeaveBalanceRepository $balances,
    ) {
    }

    /** Approval queue — every application, filterable, plus status counters. */
    public function queue(int $tenantId, array $f): array
    {
        return [
            'data'  => $this->repo->filtered($tenantId, $f)->map(fn ($a) => $this->present($a))->all(),
            'stats' => $this->repo->statusCounts($tenantId),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $app = $this->find($id, $tenantId);
        $app->recordAudit('Leave Approval Viewed', $actor);

        return $this->present($app, true, $tenantId);
    }

    public function history(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($a) => $this->present($a))->all();
    }

    /** Approve a Submitted application and deduct the balance. */
    public function approve(int $id, ?string $remarks, int $tenantId, ?User $actor = null): array
    {
        $app = $this->find($id, $tenantId);
        $this->assertPending($app);

        DB::transaction(function () use ($app, $remarks, $tenantId, $actor) {
            // Deduct via the existing balance service — writes the immutable ledger entry.
            $this->balanceService->recordUsage(
                $app->employee_id, $app->leave_type_id, (float) $app->days,
                "Approved leave #{$app->id}".($remarks ? " — {$remarks}" : ''),
                $tenantId, $actor
            );

            $app->update([
                'status' => HrLeaveApplication::APPROVED,
                'decided_by' => $actor?->id, 'decided_at' => now(),
                'decision_remarks' => $remarks, 'updated_by' => $actor?->id,
            ]);
            $app->recordAudit('Leave Approved', $actor, $remarks, ['days' => (float) $app->days]);
        });
        $this->log('Leave approved', $tenantId, $app->id);

        return $this->present($this->find($id, $tenantId), true, $tenantId);
    }

    /** Reject a Submitted application. No balance change; remains in history. */
    public function reject(int $id, ?string $remarks, int $tenantId, ?User $actor = null): array
    {
        $app = $this->find($id, $tenantId);
        $this->assertPending($app);

        $app->update([
            'status' => HrLeaveApplication::REJECTED,
            'decided_by' => $actor?->id, 'decided_at' => now(),
            'decision_remarks' => $remarks, 'updated_by' => $actor?->id,
        ]);
        $app->recordAudit('Leave Rejected', $actor, $remarks);
        $this->log('Leave rejected', $tenantId, $app->id);

        return $this->present($this->find($id, $tenantId), true, $tenantId);
    }

    /* ── Helpers ──────────────────────────────────────────── */
    private function assertPending(HrLeaveApplication $app): void
    {
        if ($app->status === HrLeaveApplication::APPROVED) {
            throw new BusinessException('This application is already approved.');
        }
        if ($app->status === HrLeaveApplication::REJECTED) {
            throw new BusinessException('A rejected application cannot be actioned.');
        }
        if ($app->status === HrLeaveApplication::CANCELLED) {
            throw new BusinessException('A cancelled application cannot be actioned.');
        }
        if ($app->status !== HrLeaveApplication::SUBMITTED) {
            throw new BusinessException('Only a submitted application can be approved or rejected.');
        }
    }

    private function present(HrLeaveApplication $a, bool $full = false, ?int $tenantId = null): array
    {
        $out = [
            'id' => $a->id, 'employee_id' => $a->employee_id,
            'employee_name' => $a->employee?->name, 'employee_code' => $a->employee?->employee_code,
            'department' => $a->employee?->department, 'designation' => $a->employee?->designation,
            'leave_type_id' => $a->leave_type_id, 'leave_type' => $a->leaveType?->name, 'color' => $a->leaveType?->color,
            'policy_name' => $a->policy?->name,
            'from_date' => optional($a->from_date)->toDateString(), 'to_date' => optional($a->to_date)->toDateString(),
            'days' => (float) $a->days, 'half_day' => $a->half_day, 'reason' => $a->reason,
            'has_attachment' => ! empty($a->attachment_path),
            'status' => $a->status,
            'applied_at' => optional($a->applied_at)->toIso8601String(),
            'decided_at' => optional($a->decided_at)->toIso8601String(),
            'decision_remarks' => $a->decision_remarks,
        ];

        if ($full) {
            // Current available balance for context (read-only).
            $bal = $tenantId ? $this->balances->activeByType($a->employee_id, $a->leave_type_id, $tenantId) : null;
            $out['available_balance'] = $bal ? (float) $bal->available_balance : null;
            $out['balance_id'] = $bal?->id;
            $out['timeline'] = $a->relationLoaded('auditLogs')
                ? $a->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                    'action' => $l->action, 'actor_name' => $l->actor_name,
                    'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                ])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrLeaveApplication
    {
        $app = $this->repo->find($id, $tenantId);
        if (! $app) {
            throw new BusinessException('Leave application not found', 404);
        }

        return $app;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
