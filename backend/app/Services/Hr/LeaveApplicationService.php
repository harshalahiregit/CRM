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
 *
 * Non-working days come from ShiftService, not from a hardcoded Saturday/Sunday.
 * Employees with no shift assignment still fall back to Sat/Sun, so the day count
 * is unchanged for any tenant that has not set shifts up.
 */
class LeaveApplicationService
{
    public function __construct(
        private LeaveApplicationRepository $repo,
        private EmployeeLeaveBalanceRepository $balances,
        private ShiftService $shifts,
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
        $days = $this->computeDays($from, $to, $halfDay, (bool) ($policy->weekends_count ?? false), $employee->id, $tenantId);
        if ($days <= 0) {
            // Every day in the range is a non-working day for this employee, which
            // is a different problem from an invalid range — say which.
            throw new BusinessException('The selected range is entirely non-working days for this employee.');
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

    /**
     * Upsert a leave that originated in an external HRM (SangoeTrack).
     *
     * Deliberately not apply(): that models an employee *requesting* leave, so it
     * hard-requires an active balance and refuses when the balance is short. A
     * synced leave is a decision that has already been taken elsewhere — refusing
     * it would not stop the employee being on leave, it would just hide the fact
     * from this CRM. Missing balance is therefore reported, not fatal.
     *
     * Day count still comes from computeDays(), so the weekend rule stays defined
     * in exactly one place. Status transitions are NOT applied here: approval must
     * go through LeaveApprovalService so the balance ledger is written by the code
     * that owns it. The caller drives that.
     *
     * Idempotent on (tenant_id, sangoetrack_leave_id): re-running writes nothing
     * when the remote row has not moved.
     *
     * @param  array{sangoetrack_leave_id:int, leave_type_id:int, from_date:string, to_date:string, half_day?:bool, reason?:?string}  $data
     * @return array{application:HrLeaveApplication, created:bool, changed:bool, balance_missing:bool}
     */
    public function syncExternal(HrEmployee $employee, array $data): array
    {
        $tenantId = (int) $employee->tenant_id;

        $balance = $this->balances->activeByType($employee->id, (int) $data['leave_type_id'], $tenantId);
        $balance?->loadMissing('policy');
        $policy = $balance?->policy;

        $from = Carbon::parse($data['from_date']);
        $to   = Carbon::parse($data['to_date']);
        if ($to->lt($from)) {
            throw new BusinessException('Leave end date is before its start date.');
        }

        $halfDay = (bool) ($data['half_day'] ?? false);
        $days    = $this->computeDays($from, $to, $halfDay, (bool) ($policy->weekends_count ?? false), $employee->id, $tenantId);

        $app = HrLeaveApplication::where('tenant_id', $tenantId)
            ->where('sangoetrack_leave_id', (int) $data['sangoetrack_leave_id'])
            ->first();

        $attributes = [
            'leave_type_id'             => (int) $data['leave_type_id'],
            'leave_policy_id'           => $balance?->leave_policy_id,
            'employee_leave_balance_id' => $balance?->id,
            'from_date'                 => $from->toDateString(),
            'to_date'                   => $to->toDateString(),
            'days'                      => $days,
            'half_day'                  => $halfDay,
            'reason'                    => $data['reason'] ?? null,
        ];

        if (! $app) {
            $app = HrLeaveApplication::create($attributes + [
                'tenant_id'            => $tenantId,
                'employee_id'          => $employee->id,
                'sangoetrack_leave_id' => (int) $data['sangoetrack_leave_id'],
                'status'               => HrLeaveApplication::SUBMITTED,
                'applied_at'           => now(),
            ]);
            $app->recordAudit('Leave Synced (SangoeTrack)', null, null, ['days' => $days], 'SangoeTrack');

            return ['application' => $app, 'created' => true, 'changed' => true, 'balance_missing' => $balance === null];
        }

        $app->fill($attributes);

        if (! $app->isDirty()) {
            return ['application' => $app, 'created' => false, 'changed' => false, 'balance_missing' => $balance === null];
        }

        $app->save();
        $app->recordAudit('Leave Updated (SangoeTrack)', null, null, ['days' => $days], 'SangoeTrack');

        return ['application' => $app, 'created' => false, 'changed' => true, 'balance_missing' => $balance === null];
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
    /**
     * Leave days in a range, excluding the employee's non-working days.
     *
     * "Non-working" is resolved by ShiftService, NOT by Carbon's Saturday/Sunday.
     * An employee on a shift gets that shift's weekly off — including alternate
     * Saturdays and whichever leg of a rotation covers the day — so someone whose
     * week off is Tuesday no longer loses a leave day for it.
     *
     * An employee with NO shift assignment falls back to Saturday/Sunday inside
     * ShiftService::offDaysBetween(), which is exactly what this method did before,
     * so existing tenants see no change until they assign shifts.
     *
     * `weekends_count` on the policy still wins: a policy that counts non-working
     * days counts every calendar day, as it always did.
     */
    private function computeDays(Carbon $from, Carbon $to, bool $halfDay, bool $weekendsCount, ?int $employeeId = null, ?int $tenantId = null): float
    {
        return $this->dayBreakdown($from, $to, $halfDay, $weekendsCount, $employeeId, $tenantId)['days'];
    }

    /**
     * The day count PLUS the per-day working. Callers that only need the number
     * use computeDays(); the preview endpoint surfaces the breakdown so "why is
     * this 3 days and not 5?" is answerable before the application is submitted.
     *
     * @return array{days: float, breakdown: array, excluded: int, source: string}
     */
    public function dayBreakdown(Carbon $from, Carbon $to, bool $halfDay, bool $weekendsCount, ?int $employeeId = null, ?int $tenantId = null): array
    {
        if ($halfDay) {
            return ['days' => 0.5, 'breakdown' => [], 'excluded' => 0, 'source' => 'half_day'];
        }

        // No employee context (a caller written before shifts existed) keeps the
        // original behaviour rather than silently counting differently.
        $offDays = ($employeeId && $tenantId)
            ? $this->shifts->offDaysBetween($employeeId, $tenantId, $from, $to)
            : [];

        $days = 0;
        $excluded = 0;
        $breakdown = [];
        $source = 'default_weekend';

        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            $info = $offDays[$key] ?? ['off' => $d->isWeekend(), 'source' => 'default_weekend', 'shift_name' => null];
            $isOff = ! $weekendsCount && $info['off'];

            if ($info['source'] === 'shift') {
                $source = 'shift';
            }
            if ($isOff) {
                $excluded++;
            } else {
                $days++;
            }

            $breakdown[] = [
                'date'       => $key,
                'day'        => $d->format('D'),
                'counted'    => ! $isOff,
                'off'        => (bool) $info['off'],
                'reason'     => $isOff
                    ? ($info['source'] === 'shift' ? 'Weekly off ('.$info['shift_name'].')' : 'Weekend')
                    : null,
                'shift_name' => $info['shift_name'],
            ];
        }

        return ['days' => (float) $days, 'breakdown' => $breakdown, 'excluded' => $excluded, 'source' => $source];
    }

    /** Preview the day count for a range before applying. Read-only. */
    public function preview(int $employeeId, int $tenantId, string $fromDate, string $toDate, ?int $leaveTypeId = null, bool $halfDay = false): array
    {
        $employee = $this->employee($employeeId, $tenantId);

        $from = Carbon::parse($fromDate);
        $to   = Carbon::parse($toDate);
        if ($to->lt($from)) {
            throw new BusinessException('The end date cannot be before the start date.');
        }

        // The policy's weekend rule matters to the count, so resolve it when a
        // leave type is given — otherwise preview a plain working-day count.
        $weekendsCount = false;
        $policyName = null;
        if ($leaveTypeId) {
            $balance = $this->balances->activeByType($employee->id, $leaveTypeId, $tenantId);
            $balance?->loadMissing('policy');
            $weekendsCount = (bool) ($balance?->policy?->weekends_count ?? false);
            $policyName = $balance?->policy?->name;
        }

        $result = $this->dayBreakdown($from, $to, $halfDay, $weekendsCount, $employee->id, $tenantId);

        return $result + [
            'from_date'      => $from->toDateString(),
            'to_date'        => $to->toDateString(),
            'total_days'     => (int) $from->diffInDays($to) + 1,
            'weekends_count' => $weekendsCount,
            'policy_name'    => $policyName,
        ];
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
