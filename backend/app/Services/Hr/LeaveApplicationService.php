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
        // Nothing told the employee their leave had been submitted, approved or
        // rejected. They had to open the app and look.
        private RequestNotifier $notifier,
    ) {
    }

    /** @param  User|null  $actor  Whose view this is; null is unscoped, as before. */
    public function list(int $tenantId, array $f, ?User $actor = null): array
    {
        return $this->repo->filtered($tenantId, $f, $actor)->map(fn ($a) => $this->present($a))->all();
    }

    public function show(int $id, int $tenantId): array
    {
        return $this->present($this->find($id, $tenantId), true);
    }

    /**
     * One person cannot be on leave twice on the same day.
     *
     * There was no check at all. Reproduced against the running API: an approved
     * 5–6 October, then a second application for 5 October, both accepted. The
     * employee ends up holding two claims on one day, each of which will deduct
     * from the balance when approved, and attendance and payroll then disagree
     * about whether that day was worked.
     *
     * Only applications that still HOLD the day block a new one. A cancelled or
     * rejected request released its dates and must not stand in the way of
     * re-applying — which is the normal thing to do after a rejection.
     *
     * Standard interval overlap: two ranges collide unless one ends before the
     * other starts. Half-days are treated as occupying the day, which is the
     * safe direction — a morning and an afternoon request on one date is a
     * refinement, not a reason to let a full double-booking through.
     */
    private function assertNoOverlap(int $employeeId, int $tenantId, Carbon $from, Carbon $to, ?int $ignoreId = null): void
    {
        $clash = HrLeaveApplication::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['Draft', 'Submitted', 'Approved'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->first();

        if (! $clash) {
            return;
        }

        throw new BusinessException(sprintf(
            'This overlaps leave already %s for %s to %s. Cancel that request first, or choose different dates.',
            strtolower($clash->status),
            Carbon::parse($clash->from_date)->format('d M Y'),
            Carbon::parse($clash->to_date)->format('d M Y'),
        ), 422);
    }

    public function forEmployee(int $employeeId, int $tenantId): array
    {
        return $this->repo->forEmployee($employeeId, $tenantId)->map(fn ($a) => $this->present($a))->all();
    }

    /**
     * Leave only after probation, where the policy says so.
     *
     * HR set this out on 5 Sep: "आफ्टर प्रोबेशन अपना लीव एप्लीकेबल होगा — बिफोर
     * प्रोबेशन अपना जो मैंने लीव लिया डिडक्ट होना चाहिए मेरे सैलरी से". The flag
     * to express it, `probation_allowed`, already existed on the leave policy;
     * it was stored, validated in the controller, cast on the model, and read by
     * nothing. A probationer could apply and be approved exactly like anybody
     * else, which is the same class of failure as the late-mark thresholds.
     *
     * ── Why the FROM date and not today ──
     *
     * Somebody two days from confirmation applying for leave the following month
     * is asking for leave they will be entitled to. Refusing on today's date
     * would make them wait and reapply for something already permissible.
     *
     * ── Why a missing probation_end_date permits ──
     *
     * A blank date means nobody recorded a probation period, not that it runs
     * forever. Treating absence as an active probation would block leave for
     * every employee predating the field.
     */
    private function assertProbationAllows(HrEmployee $employee, $policy, Carbon $from, int $tenantId): void
    {
        // The workspace-wide switch. Off means probation never blocks leave,
        // whatever the individual policies say — some businesses grant leave
        // from day one and should not have to edit every policy to express it.
        $enabled = (bool) app(\App\Services\Settings\SettingsService::class)
            ->get($tenantId, 'payroll', 'probation_blocks_leave', true);

        if (! $enabled) {
            return;
        }

        if ($policy && (bool) ($policy->probation_allowed ?? false)) {
            return;   // this policy explicitly permits leave during probation
        }

        $end = $employee->probation_end_date;
        if (! $end) {
            return;
        }

        // Confirmed early? Then probation is over whatever the original end date said.
        if ($employee->confirmation_date && Carbon::parse($employee->confirmation_date)->lte($from)) {
            return;
        }

        if ($from->lte(Carbon::parse($end))) {
            throw new BusinessException(
                'Leave is available after probation ends on '.Carbon::parse($end)->format('d M Y').
                '. Leave taken before then is unpaid and comes off the salary.'
            );
        }
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
        $this->assertProbationAllows($employee, $policy, $from, $tenantId);
        $this->assertNoOverlap($employee->id, $tenantId, $from, $to, $data['id'] ?? null);

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

        $this->notifier->tell($app->employee, 'Leave', 'submitted',
            'Your leave from '.$app->from_date.' to '.$app->to_date.' is with your approver.',
            $actor);

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
