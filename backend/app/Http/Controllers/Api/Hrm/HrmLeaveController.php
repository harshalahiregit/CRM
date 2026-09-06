<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeaveType;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\LeaveApplicationService;
use App\Services\Shared\AttachmentService;
use App\Support\Hr\HrmUpload;
use App\Support\Hrm\HrmResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave, holidays and attendance raises, in the app's shapes.
 *
 * "Attendance raise" is their name for what the CRM calls an attendance
 * correction — the same request, so it is backed by the same service rather
 * than a parallel one that could drift.
 *
 * The balance payload is the awkward one. Their model declares twelve fixed
 * fields across exactly four buckets — paid, unpaid, casual, comp-off — while
 * the CRM has an arbitrary number of leave types per tenant. The mapping is by
 * CATEGORY and documented at the method; a type outside those four is real
 * leave the app simply has no box for, so it is summed into `paid` when paid
 * rather than silently dropped.
 */
class HrmLeaveController extends Controller
{
    public function __construct(
        private EmployeeIdentityService $identity,
        private LeaveApplicationService $leave,
        private AttendanceCorrectionService $corrections,
        private AttachmentService $attachments,
    ) {
    }

    /* ── leave ───────────────────────────────────────────────────────── */

    /** Every field LeaveData reads. */
    public function myLeaves(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $rows = HrLeaveApplication::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->get();

        return HrmResponse::ok($rows->map(fn ($l) => [
            'id'               => $l->id,
            'employee_id'      => $l->employee_id,
            'user_id'          => $request->user()->id,
            'leave_type_id'    => $l->leave_type_id,
            // Real column names, checked against the table rather than guessed:
            // it is `days` and `decision_remarks`, not total_days/remarks, and
            // `applied_at` rather than created_at. Guessing here would have sent
            // blanks for data that exists — the app shows a blank, not an error.
            'applied_on'       => $this->date($l->applied_at ?? $l->created_at),
            'start_date'       => $this->date($l->from_date),
            'end_date'         => $this->date($l->to_date),
            'total_leave_days' => $this->number($l->days),
            'leave_reason'     => (string) ($l->reason ?? ''),
            'remark'           => (string) ($l->decision_remarks ?? ''),
            'status'           => (string) $l->status,
            'workspace'        => $employee->tenant_id,
            'created_by'       => $request->user()->id,
        ])->values()->all());
    }

    /**
     * The types this person can actually take.
     *
     * `is_disable` is theirs and inverted: the app keeps a type only when
     * is_disable == 0, so a type with no balance is sent disabled rather than
     * hidden — the app then shows it greyed instead of pretending it does not
     * exist, which is the more honest thing for somebody wondering where their
     * sick leave went.
     */
    public function leaveTypes(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $balances = HrEmployeeLeaveBalance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->get()
            ->keyBy('leave_type_id');

        $types = HrLeaveType::where('tenant_id', $employee->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return HrmResponse::ok($types->map(function ($t) use ($balances) {
            $b = $balances->get($t->id);

            return [
                'id'         => $t->id,
                'title'      => $t->name,
                // INTEGERS, not the formatted strings the rest of this file uses:
                // their LeaveType model declares `int? days` and `int? used`, so a
                // string throws _TypeError inside fromJson and the whole leave-type
                // list fails to parse — the app then offers no leave types at all.
                //
                // Half-days cannot be represented in an int. Available is FLOORED
                // rather than rounded, because showing 11 when 10.5 remain invites
                // somebody to book a day they do not have.
                'days'       => (int) floor((float) ($b->available_balance ?? 0)),
                'used'       => (int) round((float) ($b->used ?? 0)),
                // 0 means selectable. No balance assigned means they cannot take it.
                'is_disable' => $b ? 0 : 1,
            ];
        })->values()->all());
    }

    public function applyLeave(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate([
            'leave_type_id' => 'required',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'leave_reason'  => 'nullable|string|max:2000',
            'remark'        => 'nullable|string|max:2000',
            // Sick leave asks for a medical certificate and there was nowhere to
            // put one, so people applied on the app and mailed the certificate
            // separately — which HR then had to marry up by hand.
            ...HrmUpload::rules('attachments'),
        ]);

        try {
            // The employee is the caller. Any user_id the app sent is ignored —
            // it comes from its own prefs and must never decide whose leave this is.
            $leave = $this->leave->apply([
                'employee_id'   => $employee->id,
                'leave_type_id' => (int) $data['leave_type_id'],
                'from_date'     => $data['start_date'],
                'to_date'       => $data['end_date'],
                'reason'        => trim(($data['leave_reason'] ?? '').' '.($data['remark'] ?? '')) ?: null,
                'status'        => 'Submitted',
            ], (int) $employee->tenant_id, $request->user());
        } catch (BusinessException $e) {
            // Their refusals are 200 with status 0, so the person sees the reason
            // rather than "something went wrong".
            return HrmResponse::fail($e->getMessage());
        }

        // apply() hands back the presented array, so the id comes from there.
        foreach ((array) $request->file('attachments', []) as $file) {
            $this->attachments->upload(
                HrLeaveApplication::class, (int) $leave['id'], (int) $employee->tenant_id,
                $file, null, [], $request->user()
            );
        }

        return HrmResponse::ok([], 'Leave applied for.');
    }

    /**
     * Twelve fixed fields across four buckets.
     *
     * The CRM has any number of leave types; their app has paid, unpaid, casual
     * and comp-off. Mapped by the type's CATEGORY. A paid type outside those
     * buckets — Sick, Maternity — is added to `paid` rather than dropped, so the
     * totals still describe the person's real entitlement even though the app
     * cannot name every part of it.
     */
    public function leaveBalance(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $rows = HrEmployeeLeaveBalance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->with('leaveType:id,name,category,paid')
            ->get();

        $bucket = ['paid' => [0, 0], 'unpaid' => [0, 0], 'casual' => [0, 0], 'comp_off' => [0, 0]];

        foreach ($rows as $b) {
            $category = strtolower((string) ($b->leaveType->category ?? ''));
            $name     = strtolower((string) ($b->leaveType->name ?? ''));

            $key = match (true) {
                str_contains($name, 'comp') || str_contains($category, 'comp') => 'comp_off',
                $category === 'casual' => 'casual',
                $category === 'unpaid' => 'unpaid',
                default => ($b->leaveType->paid ?? true) ? 'paid' : 'unpaid',
            };

            $bucket[$key][0] += (float) $b->allocated;
            $bucket[$key][1] += (float) $b->used;
        }

        $out = [];
        foreach ($bucket as $k => [$allocated, $used]) {
            $out["{$k}_leaves"]    = $allocated;
            $out["{$k}_used"]      = $used;
            $out["{$k}_remaining"] = round($allocated - $used, 2);
        }

        return HrmResponse::ok($out);
    }

    /**
     * Holidays, in the calendar shape their model reads.
     *
     * title / start / end / className — className is a CSS class their calendar
     * widget colours by, so an optional holiday is visibly different from a
     * mandatory one.
     */
    public function holidays(Request $request)
    {
        $employee = $this->employee($request);
        $tenantId = $employee?->tenant_id ?? $request->user()->tenant_id;

        if (! Schema::hasTable('hr_holidays')) {
            return HrmResponse::ok([]);
        }

        // Through the model, not DB::table, so the visibility scope and the
        // type-aware class name are the SAME rule the calendar endpoint uses.
        $rows = \App\Models\Hr\HrHoliday::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->visibleTo($employee)
            ->orderBy('holiday_date')
            ->get();

        return HrmResponse::ok($rows->map(fn (\App\Models\Hr\HrHoliday $h) => [
            'title'     => (string) $h->title,
            // Y-m-d for the same reason the calendar needs it: the app compares
            // these as plain strings and parses them for the day/month chips,
            // never as a datetime.
            'start'     => $h->holiday_date->toDateString(),
            'end'       => $h->holiday_date->toDateString(),
            'className' => $h->appClassName(),
        ])->values()->all());
    }

    /* ── attendance raises (the CRM's corrections) ───────────────────── */

    public function raises(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $rows = HrAttendanceCorrection::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->get();

        return HrmResponse::ok($rows->map(fn (HrAttendanceCorrection $c) => [
            'id'              => $c->id,
            'attendance_date' => $this->date($c->attendance_date),
            'login_time'      => $this->clock($c->requested_check_in),
            'logout_time'     => $this->clock($c->requested_check_out),
            'reason'          => (string) $c->reason,
            'status'          => (string) $c->status,
            'admin_remarks'   => (string) ($c->admin_remarks ?? ''),
            'created_at'      => $this->date($c->created_at),
        ])->values()->all());
    }

    public function submitRaise(Request $request)
    {
        $employee = $this->employee($request);

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $data = $request->validate([
            'attendance_date' => 'required|date',
            'login_time'      => 'nullable|string|max:8',
            'logout_time'     => 'nullable|string|max:8',
            'reason'          => 'required|string|max:2000',
            // A correction is an argument about what happened on a day. A
            // photograph of the register or the gate pass is the evidence for
            // it, and there was no way to send one.
            ...HrmUpload::rules('attachments'),
        ]);

        try {
            $correction = $this->corrections->request($employee, [
                'attendance_date'     => $data['attendance_date'],
                // Their times may arrive as HH:mm or HH:mm:ss; the service wants HH:mm.
                'requested_check_in'  => $this->normaliseTime($data['login_time'] ?? null),
                'requested_check_out' => $this->normaliseTime($data['logout_time'] ?? null),
                'reason'              => $data['reason'],
            ], $request->user());
        } catch (BusinessException $e) {
            return HrmResponse::fail($e->getMessage());
        }

        foreach ((array) $request->file('attachments', []) as $file) {
            $this->attachments->upload(
                HrAttendanceCorrection::class, $correction->id, (int) $employee->tenant_id,
                $file, null, [], $request->user()
            );
        }

        return HrmResponse::ok([], 'Submitted successfully');
    }

    /* ── internals ───────────────────────────────────────────────────── */

    private function employee(Request $request): ?HrEmployee
    {
        return $this->identity->employeeFor($request->user());
    }

    /** Strings, never null: their models read String? and print blanks. */
    private function date($value): string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '';
    }

    private function clock($value): string
    {
        return $value ? substr((string) $value, 0, 5) : '';
    }

    /**
     * A number a person reads, not a decimal cast.
     *
     * These columns are decimal, so a plain string cast gives "2.0" and the app
     * shows "2.0 days". Half-days are real, so 0.5 must survive — only the
     * pointless trailing zeros go.
     */
    private function number($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $f = (float) $value;

        return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function normaliseTime(?string $value): ?string
    {
        if (! $value || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 5);
    }
}
