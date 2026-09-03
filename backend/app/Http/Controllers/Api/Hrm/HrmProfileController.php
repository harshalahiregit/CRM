<?php

namespace App\Http\Controllers\Api\Hrm;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrDemoRequest;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\Notification;
use App\Models\User;
use App\Services\Hr\EmployeeIdentityService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\ReimbursementStatus;
use App\Support\Hrm\HrmResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;

/**
 * Profile, salary, events, notifications and the odds and ends.
 *
 * Two shapes here are richer than what the CRM can currently fill:
 *
 *   SalaryData reads twenty-three fields, most of which belong to the payroll
 *   module that is deliberately not built yet. Every key is still sent, with
 *   real figures where the CRM has them — base salary, and the advance history
 *   that already exists — and zeros elsewhere. Sending zeros rather than
 *   omitting keys means the screen renders correctly and simply shows nothing
 *   under the parts that do not exist, instead of blank rows the app cannot
 *   explain.
 *
 *   NotificationPrefs has eight switches and no table behind them. They live in
 *   users.meta, which is where the permission grid already lives, so no new
 *   table is created for eight booleans.
 */
class HrmProfileController extends Controller
{
    /** The switches their model reads, and the default each takes. */
    private const PREF_DEFAULTS = [
        'push_enabled'            => true,
        'email_enabled'           => true,
        'whatsapp_enabled'        => false,
        'notify_leave'            => true,
        'notify_reimbursement'    => true,
        'notify_attendance_raise' => true,
        'notify_clock_reminder'   => true,
        'notify_advance'          => true,
    ];

    public function __construct(private EmployeeIdentityService $identity)
    {
    }

    /* ── profile ─────────────────────────────────────────────────────── */

    public function editProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'      => 'sometimes|string|max:120',
            'email'     => 'sometimes|email|max:191|unique:users,email,'.$user->id,
            'mobile_no' => 'nullable|string|max:40',
        ]);

        $user->forceFill(array_filter([
            'name'  => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['mobile_no'] ?? null,
        ], fn ($v) => $v !== null))->save();

        // The employee record carries the same person's name; letting the two
        // drift is how somebody appears twice under different spellings.
        if (($employee = $this->identity->employeeFor($user)) && isset($data['name'])) {
            $employee->update(['name' => $data['name']]);
        }

        return HrmResponse::ok([
            'name'      => (string) $user->name,
            'email'     => (string) $user->email,
            'mobile_no' => (string) ($user->phone ?? ''),
            'avatar'    => '',
        ], 'Profile updated.');
    }

    /**
     * Deleting your own account.
     *
     * Deactivates rather than erases. Attendance, leave and money records refer
     * to this person, and destroying the row would orphan every one of them —
     * so the login stops working and the history stays intact.
     */
    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        $user->forceFill(['status' => 'inactive'])->save();
        $user->tokens()->delete();

        if ($employee = $this->identity->employeeFor($user)) {
            $employee->update(['app_login_enabled' => false]);
        }

        return HrmResponse::ok([], 'Your account has been closed.');
    }

    /* ── salary ──────────────────────────────────────────────────────── */

    public function salaryDetails(Request $request)
    {
        $employee = $this->identity->employeeFor($request->user());

        if (! $employee) {
            return HrmResponse::fail('Your login is not linked to an employee record. Contact HR.');
        }

        $salary = Schema::hasTable('hr_employee_salaries')
            ? DB::table('hr_employee_salaries')
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->orderByDesc('effective_from')
                ->first()
            : null;

        $advances = HrAdvance::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->with('settlements')
            ->get();

        $outstanding = $advances
            ->whereIn('status', [AdvanceStage::DISBURSED, AdvanceStage::SETTLEMENT_SUBMITTED])
            ->sum('disbursed_amount');

        // What the company owes back, from settlements where more was spent.
        $owedBack = $advances->flatMap->settlements
            ->where('status', \App\Models\Hr\HrAdvanceSettlement::ACCEPTED)
            ->sum('extra_due');

        $claims = HrReimbursement::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', ReimbursementStatus::APPROVED)
            ->get();

        return HrmResponse::ok([
            'employee_name'  => (string) $employee->name,
            'employee_id'    => (string) $employee->employee_code,
            'designation'    => (string) ($employee->designation ?? ''),
            'department'     => (string) ($employee->department ?? ''),
            'salary_type'    => $salary ? 'monthly' : '',
            'base_salary'    => $this->money($salary->monthly_ctc ?? null),

            // Payroll components. The module is not built, so these are empty
            // ARRAYS rather than absent keys — the app iterates them.
            'allowances'     => [],
            'commissions'    => [],
            'loans'          => [],
            'deductions'     => [],
            'other_payments' => [],
            'overtimes'      => [],

            'reimbursements' => $claims->map(fn (HrReimbursement $c) => [
                'id'           => $c->id,
                'title'        => (string) $c->title,
                'amount'       => $this->money($c->amount_approved ?? $c->amount_claimed),
                'expense_date' => $c->expense_date ? $c->expense_date->format('Y-m-d') : '',
            ])->values()->all(),

            'total_allowances'      => '0',
            'total_commissions'     => '0',
            'total_deductions'      => $this->money($salary->total_deductions ?? 0),
            'total_other_payments'  => '0',
            'total_overtime_pay'    => '0',
            'total_reimbursements'  => $this->money($claims->sum(fn ($c) => (float) ($c->amount_approved ?? $c->amount_claimed))),

            // These the CRM genuinely knows.
            'advance_deduction' => $this->money($outstanding),
            'advance_addition'  => $this->money($owedBack),
            'advance_history'   => $this->advanceHistory($advances),

            'net_salary' => $this->money($salary->net_salary ?? null),
        ]);
    }

    /* ── calendar ────────────────────────────────────────────────────── */

    /** Holidays as calendar events. GET, unlike almost everything else here. */
    public function events(Request $request)
    {
        $employee = $this->identity->employeeFor($request->user());
        $tenantId = $employee?->tenant_id ?? $request->user()->tenant_id;

        if (! Schema::hasTable('hr_holidays')) {
            return HrmResponse::ok([]);
        }

        $month = (int) $request->query('month', now()->month);
        $year  = (int) $request->query('year', now()->year);

        $from = \Illuminate\Support\Carbon::createFromDate($year, $month, 1)->startOfMonth();

        // Same rule as /holidays-list: only what this employee actually observes.
        $rows = \App\Models\Hr\HrHoliday::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->visibleTo($employee)
            ->whereDate('holiday_date', '>=', $from->toDateString())
            ->whereDate('holiday_date', '<=', $from->copy()->endOfMonth()->toDateString())
            ->orderBy('holiday_date')
            ->get();

        return HrmResponse::ok($rows->map(fn ($h) => [
            'id'          => $h->id,
            'title'       => (string) $h->title,
            // Y-m-d, not a datetime. The calendar matches a tapped day with
            // `e.startDate == getDateFormmatted(date)` — plain string equality
            // against 'yyyy-MM-dd' — so a trailing ' 00:00:00' meant no day ever
            // matched and the day's event list was always empty.
            'start_date'  => $h->holiday_date->toDateString(),
            'end_date'    => $h->holiday_date->toDateString(),
            // Colour follows the TYPE, not a yes/no, so National, Festival and
            // Company holidays are told apart on the calendar as the admin screen
            // implies they are.
            'color'       => $h->appColor(),
            'description' => (string) ($h->description ?? ''),
        ])->values()->all());
    }

    /* ── notifications ───────────────────────────────────────────────── */

    public function notifications(Request $request)
    {
        $user    = $request->user();
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(50, max(1, (int) $request->input('per_page', 20)));

        $query = Notification::where('tenant_id', $user->tenant_id)->where('user_id', $user->id);

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        return HrmResponse::ok([
            'data' => $rows->map(fn (Notification $n) => [
                // Their model declares `final int id` with no fallback — a null
                // here crashes the app rather than rendering blank.
                'id'         => $n->id,
                'type'       => (string) ($n->type ?? ''),
                'title'      => (string) ($n->title ?? ''),
                'body'       => (string) ($n->message ?? ''),
                'is_read'    => $n->read_at !== null,
                'created_at' => $n->created_at ? $n->created_at->toDateTimeString() : '',
            ])->values()->all(),
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'has_more'     => $total > $page * $perPage,
        ]);
    }

    public function markNotificationsRead(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'ids'   => 'nullable|array',
            'ids.*' => 'integer',
        ]);

        $query = Notification::where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereNull('read_at');

        // No ids means "all of them", which is what the app's Mark all does.
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }

        $n = $query->update(['read_at' => now()]);

        return HrmResponse::ok(['marked' => $n], 'Marked as read.');
    }

    public function notificationPreferences(Request $request)
    {
        $user = $request->user();

        // POST with values saves; POST without reads. Their app uses one route
        // for both, so the presence of a known key is what distinguishes them.
        $sent = array_intersect_key($request->all(), self::PREF_DEFAULTS);

        if ($sent) {
            $meta = $user->meta ?? [];
            $meta['notification_prefs'] = array_merge(
                $meta['notification_prefs'] ?? [],
                array_map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN), $sent)
            );
            $user->forceFill(['meta' => $meta])->save();
        }

        return HrmResponse::ok(array_merge(
            self::PREF_DEFAULTS,
            $user->fresh()->meta['notification_prefs'] ?? []
        ));
    }

    /**
     * The device token for push.
     *
     * Stored so the wiring is ready, though nothing sends push yet — there are
     * no FCM credentials. Recording it now means the app's existing call
     * succeeds instead of erroring on every launch.
     */
    public function fcmToken(Request $request)
    {
        $data = $request->validate(['fcm_token' => 'required|string|max:500']);

        $user = $request->user();
        $meta = $user->meta ?? [];
        $meta['fcm_token'] = $data['fcm_token'];
        $user->forceFill(['meta' => $meta])->save();

        return HrmResponse::ok([], 'Token registered.');
    }

    /* ── open endpoints ──────────────────────────────────────────────── */

    /**
     * Forgotten password.
     *
     * Always reports success. Saying whether an address is registered turns this
     * into a way of discovering who works here.
     */
    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email']);

        try {
            Password::sendResetLink(['email' => $data['email']]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Reset link failed', ['error' => $e->getMessage()]);
        }

        return HrmResponse::ok([], 'If that address is registered, a reset link is on its way.');
    }

    /** Somebody asking for a demo from the app's sign-in screen. */
    public function demoRequest(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:120',
            'company_name'  => 'nullable|string|max:160',
            'email'         => 'nullable|email|max:191',
            'phone'         => 'nullable|string|max:40',
            'address'       => 'nullable|string|max:500',
            'num_employees' => 'nullable|integer|min:0',
            'message'       => 'nullable|string|max:2000',
        ]);

        HrDemoRequest::create($data + ['status' => 'new', 'source' => 'app']);

        return HrmResponse::ok([], 'Thanks — we will be in touch.');
    }

    /* ── internals ───────────────────────────────────────────────────── */

    /** The advance ledger their salary screen shows. */
    private function advanceHistory($advances): array
    {
        $out = [];

        foreach ($advances as $a) {
            if ($a->disbursed_at) {
                $out[] = [
                    'entry_date'  => $a->disbursed_at->format('Y-m-d'),
                    'type_label'  => 'Advance paid',
                    'debit'       => $this->money($a->disbursed_amount),
                    'credit'      => '0',
                    'balance'     => $this->money($a->disbursed_amount),
                    'description' => (string) $a->purpose,
                ];
            }

            foreach ($a->settlements as $s) {
                if ($s->status !== \App\Models\Hr\HrAdvanceSettlement::ACCEPTED) {
                    continue;
                }

                $out[] = [
                    'entry_date'  => optional($s->reviewed_at)->format('Y-m-d') ?? '',
                    'type_label'  => 'Settled',
                    'debit'       => '0',
                    'credit'      => $this->money($s->actual_expense),
                    'balance'     => $this->money(max(0, (float) $a->disbursed_amount - (float) $s->actual_expense)),
                    'description' => (string) $s->case_label,
                ];
            }
        }

        return $out;
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
