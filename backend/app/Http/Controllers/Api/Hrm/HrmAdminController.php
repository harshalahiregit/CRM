<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrAttendanceCorrection;
use App\Models\Hr\HrDemoRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrReimbursement;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\AttendanceCorrectionService;
use App\Services\Hr\EmployeeIdentityService;
use App\Services\Hr\ReimbursementService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\ReimbursementStatus;
use App\Support\Hrm\HrmResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The admin half of the app: queues, decisions, people and reports.
 *
 * A permission refusal here is 200 with status 0, never 401. The app treats 401
 * as a dead session and wipes local storage — including cached clock-in state —
 * so telling somebody "you cannot approve this" with a 401 would sign them out
 * mid-shift. That is the single most damaging mistake available in this file.
 *
 * Decisions go through the same services the CRM screens use, so an advance
 * approved from a phone climbs the same ladder, with the same tier rules, as one
 * approved in a browser.
 */
class HrmAdminController extends Controller
{
    public function __construct(
        private EmployeeIdentityService $identity,
        private ReimbursementService $claims,
        private AdvanceService $advances,
        private AttendanceCorrectionService $corrections,
    ) {
    }

    /* ── dashboard ───────────────────────────────────────────────────── */

    public function dashboard(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $tenantId = (int) $request->user()->tenant_id;
        $today    = now()->toDateString();

        $employees = HrEmployee::where('tenant_id', $tenantId)->count();

        $rows = HrAttendance::where('tenant_id', $tenantId)
            ->whereDate('date', $today)
            ->get(['status', 'check_in']);

        $present = $rows->whereIn('status', ['Present', 'Work From Home', 'Remote'])->count();
        $late    = $rows->where('status', 'Late')->count();
        $onLeave = $rows->where('status', 'Leave')->count();

        // Everybody with a record today, however it reads.
        $tracked = $rows->count();

        return HrmResponse::ok([
            'total_employees'       => $employees,
            'present'               => $present + $late,
            'absent'                => max(0, $employees - $tracked),
            'late'                  => $late,
            'on_leave'              => $onLeave,
            'tracked'               => $tracked,
            'total_monthly_expense' => $this->monthlyPayrollCost($tenantId),
        ]);
    }

    /** Who is in, who is not, today. */
    public function attendanceDetails(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $tenantId = (int) $request->user()->tenant_id;

        $today = HrAttendance::where('tenant_id', $tenantId)
            ->whereDate('date', now()->toDateString())
            ->get()
            ->keyBy('employee_id');

        $employees = HrEmployee::where('tenant_id', $tenantId)->orderBy('name')->get();

        return HrmResponse::ok($employees->map(function (HrEmployee $e) use ($today) {
            $a = $today->get($e->id);

            return [
                'id'            => $e->id,
                'user_id'       => $e->user_id,
                'employee_name' => (string) $e->name,
                'name'          => (string) $e->name,
                'employee_code' => (string) $e->employee_code,
                'department'    => (string) ($e->department ?? ''),
                'designation'   => (string) ($e->designation ?? ''),
                'avatar'        => '',
                // "Absent" when nothing was recorded — which is the honest reading.
                'status'        => (string) ($a->status ?? 'Absent'),
                'clock_in'      => $a && $a->check_in ? $a->check_in->format('H:i') : '',
                'clock_out'     => $a && $a->check_out ? $a->check_out->format('H:i') : '',
            ];
        })->values()->all());
    }

    /** The four queues, in one call, as the app's dashboard expects. */
    public function pendingApprovals(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $tenantId = (int) $request->user()->tenant_id;

        return HrmResponse::ok([
            'leaves'         => $this->pendingLeaves($tenantId),
            'raises'         => $this->pendingRaises($tenantId),
            'reimbursements' => $this->pendingClaims($tenantId),
            'advances'       => $this->pendingAdvances($tenantId),
        ]);
    }

    /* ── decisions ───────────────────────────────────────────────────── */

    public function decideLeave(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'leave_id' => 'required|integer',
            'status'   => 'required|in:approved,rejected',
            'remark'   => 'nullable|string|max:1000',
        ]);

        $leave = HrLeaveApplication::where('tenant_id', $request->user()->tenant_id)
            ->find($data['leave_id']);

        if (! $leave) {
            return HrmResponse::fail('That leave request could not be found.');
        }

        if (! in_array($leave->status, ['Submitted', 'Draft', 'Pending'], true)) {
            return HrmResponse::fail('That leave request has already been decided.');
        }

        $leave->update([
            'status'            => $data['status'] === 'approved' ? 'Approved' : 'Rejected',
            'decision_remarks'  => $data['remark'] ?? null,
            'decided_by'        => $request->user()->id,
            'decided_at'        => now(),
        ]);

        return HrmResponse::ok([], $data['status'] === 'approved' ? 'Leave approved.' : 'Leave rejected.');
    }

    public function decideRaise(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'raise_id' => 'required|integer',
            'status'   => 'required|in:approved,rejected',
            'remark'   => 'nullable|string|max:1000',
        ]);

        $c = HrAttendanceCorrection::where('tenant_id', $request->user()->tenant_id)->find($data['raise_id']);

        if (! $c) {
            return HrmResponse::fail('That correction could not be found.');
        }

        try {
            // Approving WRITES the day, through the same service the CRM uses.
            if ($data['status'] === 'approved') {
                $this->corrections->approve($c, $request->user(), $data['remark'] ?? null);
            } else {
                $this->corrections->reject($c, $request->user(), $data['remark'] ?: 'Rejected.');
            }
        } catch (BusinessException $e) {
            return HrmResponse::fail($e->getMessage());
        }

        return HrmResponse::ok([], $data['status'] === 'approved' ? 'Approved, and the day has been updated.' : 'Rejected.');
    }

    public function decideReimbursement(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'reimbursement_id' => 'required|integer',
            'status'           => 'required|in:approved,rejected',
            'remark'           => 'nullable|string|max:1000',
        ]);

        $claim = HrReimbursement::where('tenant_id', $request->user()->tenant_id)->find($data['reimbursement_id']);

        if (! $claim) {
            return HrmResponse::fail('That claim could not be found.');
        }

        try {
            if ($data['status'] === 'approved') {
                $this->claims->approve($claim, $request->user(), null, $data['remark'] ?? null);
            } else {
                $this->claims->decline($claim, $request->user(), $data['remark'] ?: 'Declined.');
            }
        } catch (BusinessException $e) {
            return HrmResponse::fail($e->getMessage());
        }

        return HrmResponse::ok([], $data['status'] === 'approved' ? 'Claim approved.' : 'Claim declined.');
    }

    /**
     * An advance decision from the app.
     *
     * The app sends only approved/rejected; WHICH RUNG that satisfies is decided
     * by the CRM from who is calling, so a phone approval climbs exactly the
     * same ladder as a browser one — including the rule that one person cannot
     * approve at two rungs.
     */
    public function decideAdvance(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'advance_id' => 'required|integer',
            'status'     => 'required|in:approved,rejected',
            'remark'     => 'nullable|string|max:1000',
        ]);

        $advance = HrAdvance::where('tenant_id', $request->user()->tenant_id)->find($data['advance_id']);

        if (! $advance) {
            return HrmResponse::fail('That advance could not be found.');
        }

        try {
            if ($data['status'] === 'approved') {
                $this->advances->approve($advance, $request->user(), null, $data['remark'] ?? null);
            } else {
                $this->advances->decline($advance, $request->user(), $data['remark'] ?: 'Declined.');
            }
        } catch (BusinessException $e) {
            // Includes "it is not your turn" and "you already approved this at an
            // earlier stage" — both are things the approver needs to read.
            return HrmResponse::fail($e->getMessage());
        }

        return HrmResponse::ok([], 'Decision recorded.');
    }

    public function disburseAdvance(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'advance_id'    => 'required|integer',
            'payment_mode'  => 'required|in:cash,upi,bank_transfer,cheque',
            'utr_reference' => 'nullable|string|max:100',
            'disbursed_on'  => 'nullable|date',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $advance = HrAdvance::where('tenant_id', $request->user()->tenant_id)->find($data['advance_id']);

        if (! $advance) {
            return HrmResponse::fail('That advance could not be found.');
        }

        try {
            $this->advances->disburse(
                $advance, $request->user(), $data['payment_mode'], $data['utr_reference'] ?? null
            );
        } catch (BusinessException $e) {
            return HrmResponse::fail($e->getMessage());
        }

        return HrmResponse::ok([], 'Disbursement recorded.');
    }

    public function pendingSettlements(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $rows = HrAdvanceSettlement::where('tenant_id', $request->user()->tenant_id)
            ->where('status', HrAdvanceSettlement::PENDING)
            ->with(['advance.employee:id,name', 'attachments'])
            ->orderBy('id')
            ->get();

        return HrmResponse::ok($rows->map(fn (HrAdvanceSettlement $s) => [
            'id'                => $s->id,
            'settlement_id'     => $s->id,
            'advance_id'        => $s->advance?->reference ?: (string) $s->advance_id,
            'employee_name'     => (string) ($s->advance?->employee?->name ?? ''),
            'advance_type'      => (string) ($s->advance?->advance_type ?? ''),
            'disbursed_amount'  => $this->money($s->advance?->disbursed_amount),
            'actual_expense'    => $this->money($s->actual_expense),
            'balance_return'    => $this->money($s->balance_return),
            'extra_due'         => $this->money($s->extra_due),
            'case_label'        => (string) $s->case_label,
            'notes'             => (string) ($s->notes ?? ''),
            'status'            => (string) $s->status,
            // The app renders these as a gallery, so URLs, signed like the rest.
            'bills'             => $s->attachments->map(fn ($f) => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'hrm.file', now()->addDays(7), ['attachment' => $f->id]
            ))->values()->all(),
        ])->values()->all());
    }

    public function reviewSettlement(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'settlement_id' => 'required|integer',
            'status'        => 'required|in:approved,rejected',
            'remark'        => 'nullable|string|max:1000',
        ]);

        $s = HrAdvanceSettlement::where('tenant_id', $request->user()->tenant_id)->find($data['settlement_id']);

        if (! $s) {
            return HrmResponse::fail('That settlement could not be found.');
        }

        try {
            if ($data['status'] === 'approved') {
                $this->advances->acceptSettlement($s, $request->user(), $data['remark'] ?? null);
            } else {
                $this->advances->rejectSettlement($s, $request->user(), $data['remark'] ?: 'Sent back.');
            }
        } catch (BusinessException $e) {
            return HrmResponse::fail($e->getMessage());
        }

        return HrmResponse::ok([], 'Settlement reviewed.');
    }

    /* ── people ──────────────────────────────────────────────────────── */

    public function employees(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $rows = HrEmployee::where('tenant_id', $request->user()->tenant_id)
            ->orderBy('name')
            ->get();

        return HrmResponse::ok($rows->map(fn (HrEmployee $e) => [
            'id'            => $e->id,
            'user_id'       => $e->user_id,
            'name'          => (string) $e->name,
            'employee_code' => (string) $e->employee_code,
            'email'         => (string) ($e->email ?? ''),
            'phone'         => (string) ($e->phone ?? ''),
            'department'    => (string) ($e->department ?? ''),
            'designation'   => (string) ($e->designation ?? ''),
            'status'        => (string) $e->status,
            'avatar'        => '',
        ])->values()->all());
    }

    /** The roles this admin may hand out. */
    public function assignableRoles(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $roles = app(\App\Services\Auth\StaffRoleService::class)
            ->forTenant((int) $request->user()->tenant_id);

        return HrmResponse::ok($roles->map(fn ($r) => [
            'label' => (string) $r->name,
            'value' => (string) $r->slug,
        ])->values()->all());
    }

    public function createEmployee(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'email'     => 'required|email|max:191|unique:users,email',
            'mobile_no' => 'nullable|string|max:40',
            'password'  => 'nullable|string|min:8',
            'role'      => 'nullable|string|max:60',
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        // Generated when none is given, and returned once so it can be passed on.
        $password = ($data['password'] ?? null) ?: Str::password(12);

        [$user, $employee] = DB::transaction(function () use ($data, $tenantId, $password, $request) {
            $user = User::create([
                'tenant_id'     => $tenantId,
                'name'          => $data['name'],
                'email'         => $data['email'],
                'phone'         => $data['mobile_no'] ?? null,
                'password'      => Hash::make($password),
                // Never from the request: the app must not be able to mint admins.
                'role'          => 'staff',
                'internal_role' => $data['role'] ?? null,
                'status'        => 'active',
            ]);

            $employee = $this->identity->provisionEmployeeFor($user, [
                'phone' => $data['mobile_no'] ?? null,
            ], $request->user());

            return [$user, $employee];
        });

        return HrmResponse::ok([
            'id'            => $user->id,
            'user_id'       => $user->id,
            'employee_code' => (string) $employee->employee_code,
            'temp_password' => $password,
        ], 'Employee created.');
    }

    public function resetPassword(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate(['employee_user_id' => 'required|integer']);

        $user = User::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('role', ['staff', 'admin'])
            ->find($data['employee_user_id']);

        if (! $user) {
            return HrmResponse::fail('That person could not be found.');
        }

        $password = Str::password(12);
        $user->forceFill(['password' => Hash::make($password)])->save();
        // Every existing session ends: a reset password nobody is signed out of
        // is not a reset.
        $user->tokens()->delete();

        return HrmResponse::ok(['temp_password' => $password], 'Password reset.');
    }

    /* ── demo requests ───────────────────────────────────────────────── */

    public function demoRequests(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $tenantId = (int) $request->user()->tenant_id;

        $rows = HrDemoRequest::where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByDesc('id')
            ->get();

        return HrmResponse::ok($rows->map(fn (HrDemoRequest $d) => [
            'id'            => $d->id,
            'name'          => (string) $d->name,
            'company_name'  => (string) ($d->company_name ?? ''),
            'email'         => (string) ($d->email ?? ''),
            'phone'         => (string) ($d->phone ?? ''),
            'address'       => (string) ($d->address ?? ''),
            'num_employees' => $d->num_employees,
            'message'       => (string) ($d->message ?? ''),
            'notes'         => (string) ($d->notes ?? ''),
            'status'        => (string) $d->status,
            'created_at'    => $d->created_at ? $d->created_at->format('Y-m-d') : '',
        ])->values()->all());
    }

    public function updateDemoRequest(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'id'     => 'required|integer',
            // 'closed' is theirs; the CRM calls it declined.
            'status' => 'nullable|in:new,contacted,scheduled,converted,declined,closed',
            'notes'  => 'nullable|string|max:5000',
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        $row = HrDemoRequest::where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->find($data['id']);

        if (! $row) {
            return HrmResponse::fail('That request could not be found.');
        }

        $status = $data['status'] ?? null;

        $row->update(array_filter([
            'status'     => $status === 'closed' ? 'declined' : $status,
            'notes'      => $data['notes'] ?? null,
            'updated_by' => $request->user()->id,
            'tenant_id'  => $row->tenant_id ?? $tenantId,
        ], fn ($v) => $v !== null));

        return HrmResponse::ok([], 'Updated.');
    }

    /* ── payroll and reports ─────────────────────────────────────────── */

    /**
     * What each person is paid, and what that costs.
     *
     * The payroll module is not built. What exists — the salary on record — is
     * reported honestly, and `salary_missing` counts the people who have none,
     * which is the number an admin actually wants before running anything.
     */
    public function payrollOverview(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $tenantId = (int) $request->user()->tenant_id;

        $salaries = $this->salariesByEmployee($tenantId);

        $employees = HrEmployee::where('tenant_id', $tenantId)->orderBy('name')->get();

        $rows = $employees->map(function (HrEmployee $e) use ($salaries) {
            $s = $salaries[$e->id] ?? null;

            return [
                'id'                => $e->id,
                'name'              => (string) $e->name,
                'employee_code'     => (string) $e->employee_code,
                'department'        => (string) ($e->department ?? ''),
                'designation'       => (string) ($e->designation ?? ''),
                'salary'            => $this->money($s->monthly_ctc ?? null),
                'annual_salary'     => $this->money($s->annual_ctc ?? null),
                'salary_type'       => $s ? 'monthly' : '',
                'salary_type_name'  => $s ? 'Monthly' : '',
                'salary_set'        => (bool) $s,
            ];
        })->values();

        return HrmResponse::ok([
            'summary' => [
                'total_employees'      => $employees->count(),
                'salary_set'           => $rows->where('salary_set', true)->count(),
                'salary_missing'       => $rows->where('salary_set', false)->count(),
                'monthly_payroll_cost' => $this->money($rows->sum(fn ($r) => (float) $r['salary'])),
            ],
            'employees'     => $rows->all(),
            // Payslips belong to the payroll module. An empty list renders an
            // empty section; an absent key renders a broken one.
            'payslip_types' => [],
        ]);
    }

    public function setEmployeeSalary(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $data = $request->validate([
            'employee_id' => 'required|integer',
            'salary'      => 'required|numeric|min:0',
            'salary_type' => 'nullable|string|max:20',
        ]);

        if (! Schema::hasTable('hr_employee_salaries')) {
            return HrmResponse::fail('Salaries are not available in this workspace.');
        }

        $tenantId = (int) $request->user()->tenant_id;

        $employee = HrEmployee::where('tenant_id', $tenantId)->find($data['employee_id']);

        if (! $employee) {
            return HrmResponse::fail('That employee could not be found.');
        }

        $monthly = (float) $data['salary'];

        // Superseded rather than overwritten: what somebody was paid last month
        // is a fact, and a raise must not rewrite it.
        DB::table('hr_employee_salaries')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->whereNull('effective_to')
            ->update(['effective_to' => now()->toDateString(), 'updated_at' => now()]);

        DB::table('hr_employee_salaries')->insert([
            'tenant_id'      => $tenantId,
            'employee_id'    => $employee->id,
            'effective_from' => now()->toDateString(),
            'monthly_ctc'    => $monthly,
            'annual_ctc'     => $monthly * 12,
            'gross_salary'   => $monthly,
            'net_salary'     => $monthly,
            'status'         => 'active',
            'assigned_by'    => $request->user()->id,
            'created_by'     => $request->user()->id,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return HrmResponse::ok([], 'Salary saved.');
    }

    /**
     * The reports screen: a list of tables, each with its own columns.
     *
     * Their screen renders whatever it is given — title, subtitle, columns and
     * rows — so the shape is generic and the CRM decides what is worth showing.
     */
    public function reports(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $tenantId = (int) $request->user()->tenant_id;
        $month    = (string) $request->input('month', now()->format('Y-m'));

        try {
            $from = \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            $from = now()->startOfMonth();
        }

        $summary = app(\App\Services\Hr\AttendanceReportService::class)
            ->monthly($tenantId, $from->format('Y-m'));

        $attendanceRows = collect($summary['rows'])->map(fn ($r) => [
            'name'       => $r['name'],
            'department' => $r['department'] ?? '',
            'present'    => $r['present_days'],
            'absent'     => $r['absent_days'],
            'leave'      => $r['leave_days'],
            'payable'    => $r['payable_days'],
            'hours'      => $r['working_hours'],
        ])->values()->all();

        return HrmResponse::ok([
            'reports' => [
                [
                    'title'    => 'Attendance',
                    'subtitle' => $from->format('F Y'),
                    'columns'  => [
                        ['key' => 'name',       'label' => 'Employee',   'align' => 'left'],
                        ['key' => 'department', 'label' => 'Department', 'align' => 'left'],
                        ['key' => 'present',    'label' => 'Present',    'align' => 'right'],
                        ['key' => 'absent',     'label' => 'Absent',     'align' => 'right'],
                        ['key' => 'leave',      'label' => 'Leave',      'align' => 'right'],
                        ['key' => 'payable',    'label' => 'Payable',    'align' => 'right'],
                        ['key' => 'hours',      'label' => 'Hours',      'align' => 'right'],
                    ],
                    'rows'   => $attendanceRows,
                    'totals' => [
                        'name'    => 'Total',
                        'present' => $summary['totals']['present_days'],
                        'absent'  => $summary['totals']['absent_days'],
                        'leave'   => $summary['totals']['leave_days'],
                        'payable' => $summary['totals']['payable_days'],
                        'hours'   => $summary['totals']['working_hours'],
                    ],
                ],
            ],
        ]);
    }

    /** The dashboard's compact version of the same figures. */
    public function reportsSummary(Request $request)
    {
        if ($deny = $this->deny($request)) {
            return $deny;
        }

        $tenantId = (int) $request->user()->tenant_id;

        $summary = app(\App\Services\Hr\AttendanceReportService::class)
            ->monthly($tenantId, now()->format('Y-m'));

        return HrmResponse::ok([
            'summary' => $summary['totals'],
            'month'   => now()->format('Y-m'),
        ]);
    }

    /* ── internals ───────────────────────────────────────────────────── */

    /**
     * A refusal, in the app's shape.
     *
     * NOT 401. The app treats that as a dead session and clears local storage,
     * so a permission problem answered that way signs somebody out mid-shift.
     */
    private function deny(Request $request): ?\Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return HrmResponse::fail('You do not have access to this.');
        }

        // Admins and HR oversee; accounts and directors hold rungs on the advance
        // ladder and are NOT admins, so gating on admin alone would lock the
        // people who disburse money out of the screen that disburses it.
        if ($user->isAdmin() || $user->canManageHrQueue() || app(\App\Services\Hr\AdvanceTierService::class)->holdsAnyTierRole($user)) {
            return null;
        }

        return HrmResponse::fail('You do not have access to this.');
    }

    private function pendingLeaves(int $tenantId): array
    {
        return HrLeaveApplication::where('tenant_id', $tenantId)
            ->whereIn('status', ['Submitted', 'Pending'])
            ->with(['employee:id,name', 'leaveType:id,name'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (HrLeaveApplication $l) => [
                'id'               => $l->id,
                'leave_id'         => $l->id,
                'employee_name'    => (string) ($l->employee->name ?? ''),
                'leave_type'       => (string) ($l->leaveType->name ?? ''),
                'start_date'       => $l->from_date ? $l->from_date->format('Y-m-d') : '',
                'end_date'         => $l->to_date ? $l->to_date->format('Y-m-d') : '',
                'total_leave_days' => $this->money($l->days),
                'leave_reason'     => (string) ($l->reason ?? ''),
                'status'           => (string) $l->status,
            ])->values()->all();
    }

    private function pendingRaises(int $tenantId): array
    {
        return HrAttendanceCorrection::where('tenant_id', $tenantId)
            ->whereIn('status', [HrAttendanceCorrection::PENDING, HrAttendanceCorrection::ON_HOLD])
            ->with('employee:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (HrAttendanceCorrection $c) => [
                'id'              => $c->id,
                'raise_id'        => $c->id,
                'employee_name'   => (string) ($c->employee->name ?? ''),
                'attendance_date' => $c->attendance_date ? $c->attendance_date->format('Y-m-d') : '',
                'login_time'      => $c->requested_check_in ? substr((string) $c->requested_check_in, 0, 5) : '',
                'logout_time'     => $c->requested_check_out ? substr((string) $c->requested_check_out, 0, 5) : '',
                'reason'          => (string) $c->reason,
                'status'          => (string) $c->status,
            ])->values()->all();
    }

    private function pendingClaims(int $tenantId): array
    {
        return HrReimbursement::where('tenant_id', $tenantId)
            ->whereIn('status', [ReimbursementStatus::PENDING, ReimbursementStatus::ON_HOLD])
            ->with(['employee:id,name', 'attachments'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (HrReimbursement $c) => [
                'id'               => $c->id,
                'reimbursement_id' => $c->id,
                'employee_name'    => (string) ($c->employee->name ?? ''),
                'title'            => (string) $c->title,
                'amount'           => $this->money($c->amount_claimed),
                'expense_date'     => $c->expense_date ? $c->expense_date->format('Y-m-d') : '',
                'status'           => (string) $c->status,
                'receipt'          => $c->attachments->first()
                    ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'hrm.file', now()->addDays(7), ['attachment' => $c->attachments->first()->id])
                    : '',
            ])->values()->all();
    }

    private function pendingAdvances(int $tenantId): array
    {
        return HrAdvance::where('tenant_id', $tenantId)
            ->whereIn('status', [
                AdvanceStage::PENDING, AdvanceStage::MANAGER_APPROVED,
                AdvanceStage::ACCOUNTS_APPROVED, AdvanceStage::APPROVED, AdvanceStage::ON_HOLD,
            ])
            ->with('employee:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (HrAdvance $a) => [
                'id'                    => $a->id,
                'advance_id'            => $a->reference ?: (string) $a->id,
                'employee_name'         => (string) ($a->employee->name ?? ''),
                'advance_type'          => (string) ($a->advance_type ?? ''),
                'purpose'               => (string) $a->purpose,
                'amount'                => $this->money($a->amount_approved ?? $a->amount_requested),
                'amount_requested'      => $this->money($a->amount_requested),
                'amount_approved'       => $this->money($a->amount_approved),
                'status'                => (string) $a->status,
                // Their screen switches on this to show Disburse instead of Approve.
                'awaiting_disbursement' => $a->status === AdvanceStage::APPROVED,
            ])->values()->all();
    }

    /** Latest salary per employee, keyed for lookup. */
    private function salariesByEmployee(int $tenantId): array
    {
        if (! Schema::hasTable('hr_employee_salaries')) {
            return [];
        }

        return DB::table('hr_employee_salaries')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('effective_from')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id')
            ->all();
    }

    private function monthlyPayrollCost(int $tenantId): string
    {
        $total = collect($this->salariesByEmployee($tenantId))->sum(fn ($s) => (float) ($s->monthly_ctc ?? 0));

        return $this->money($total);
    }

    private function money($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $trimmed = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
