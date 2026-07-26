<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeaveType;
use App\Models\User;
use App\Repositories\Hr\EmployeeLeaveBalanceRepository;
use App\Repositories\Hr\LeaveApplicationRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Leave Applications (Leave Phase 3). Employees apply for leave against their
 * active balance; days are computed (half-day or working days, honouring the
 * policy's weekends rule). Applications reach Submitted for HR approval (Phase 4).
 * No balance is deducted here — that happens only on approval.
 */
class LeaveApplicationService
{
    public function __construct(
        private LeaveApplicationRepository $repo,
        private EmployeeLeaveBalanceRepository $balances,
    ) {
    }

    public function list(int $tenantId, array $f): array
    {
        return $this->repo->filtered($tenantId, $f)->map(fn ($a) => $this->present($a))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), true);
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($a) => $this->present($a))->all();
    }

    public function apply(array $data, int $tenantId, ?User $actor = null): array
    {
        $employee = $this->employee((int) $data['employee_id'], $tenantId);
        $this->leaveType((int) $data['leave_type_id'], $tenantId);

        $balance = $this->balances->activeByType($employee->id, (int) $data['leave_type_id'], $tenantId);
        if (! $balance) {
            throw new BusinessException('This employee has no active balance for that leave type. Assign a policy first.');
        }
        $balance->loadMissing('policy');
        $policy = $balance->policy;

        $from = Carbon::parse($data['from_date']);
        $to   = Carbon::parse($data['to_date']);
        if ($to->lt($from)) {
            throw new BusinessException('The end date cannot be before the start date.');
        }
        $halfDay = (bool) ($data['half_day'] ?? false);
        $days = $this->computeDays($from, $to, $halfDay, (bool) ($policy->weekends_count ?? false));
        if ($days <= 0) {
            throw new BusinessException('The selected range has no leave days.');
        }

        $negativeAllowed = (bool) ($policy->negative_balance_allowed ?? false);
        if ($days > (float) $balance->available_balance && ! $negativeAllowed) {
            throw new BusinessException("Insufficient balance: {$days} day(s) requested, {$balance->available_balance} available.");
        }

        $status = ($data['status'] ?? HrLeaveApplication::SUBMITTED) === HrLeaveApplication::DRAFT
            ? HrLeaveApplication::DRAFT : HrLeaveApplication::SUBMITTED;

        $app = HrLeaveApplication::create([
            'tenant_id' => $tenantId, 'employee_id' => $employee->id,
            'leave_type_id' => (int) $data['leave_type_id'], 'leave_policy_id' => $balance->leave_policy_id,
            'employee_leave_balance_id' => $balance->id,
            'from_date' => $from->toDateString(), 'to_date' => $to->toDateString(),
            'days' => $days, 'half_day' => $halfDay, 'reason' => $data['reason'] ?? null,
            'attachment_path' => $data['attachment_path'] ?? null,
            'status' => $status, 'applied_at' => $status === HrLeaveApplication::SUBMITTED ? now() : null,
            'created_by' => $actor?->id, 'updated_by' => $actor?->id,
        ]);
        $app->recordAudit($status === HrLeaveApplication::SUBMITTED ? 'Leave Submitted' : 'Leave Application Drafted', $actor, null, ['days' => $days, 'type' => $balance->leaveType?->name]);
        $this->log('Leave applied', $tenantId, $app->id);

        return $this->show($app->id, $tenantId);
    }

    public function submit(int $id, int $tenantId, ?User $actor = null): array
    {
        $app = $this->find($id, $tenantId);
        if ($app->status !== HrLeaveApplication::DRAFT) {
            throw new BusinessException('Only a draft application can be submitted.');
        }
        $app->update(['status' => HrLeaveApplication::SUBMITTED, 'applied_at' => now(), 'updated_by' => $actor?->id]);
        $app->recordAudit('Leave Submitted', $actor);

        return $this->show($id, $tenantId);
    }

    public function cancel(int $id, int $tenantId, ?User $actor = null): array
    {
        $app = $this->find($id, $tenantId);
        if (! in_array($app->status, [HrLeaveApplication::DRAFT, HrLeaveApplication::SUBMITTED], true)) {
            throw new BusinessException('Only a draft or submitted application can be cancelled.');
        }
        $app->update(['status' => HrLeaveApplication::CANCELLED, 'updated_by' => $actor?->id]);
        $app->recordAudit('Leave Cancelled', $actor);

        return $this->show($id, $tenantId);
    }

    /* ── Helpers ──────────────────────────────────────────── */

    /** Half day = 0.5; otherwise inclusive days, skipping weekends unless the policy counts them. */
    private function computeDays(Carbon $from, Carbon $to, bool $halfDay, bool $weekendsCount): float
    {
        if ($halfDay) {
            return 0.5;
        }
        $days = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            if ($weekendsCount || ! $d->isWeekend()) {
                $days++;
            }
        }

        return (float) $days;
    }

    private function present(HrLeaveApplication $a, bool $full = false): array
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

    private function employee(int $employeeId, int $tenantId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function leaveType(int $id, int $tenantId): void
    {
        if (! HrLeaveType::where('tenant_id', $tenantId)->where('id', $id)->exists()) {
            throw new BusinessException('Leave type is invalid for this tenant.');
        }
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
