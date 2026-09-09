<?php

namespace App\Services\Hr\Payroll;

use App\Models\Hr\HrAttendance;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Support\Carbon;

/**
 * The late-mark deduction the settings screen has been promising.
 *
 * HR set this policy out on 5 Sep and it matches the registry exactly: a 15
 * minute grace on a 09:30 start, half a day off at the third late mark, a
 * further deduction at the fifth. The settings landed at the time; the comment
 * above them in HrSetting said "Nothing enforces these yet", and that stayed
 * true — nothing outside the registry read the keys, so a workspace could turn
 * the policy on, watch late marks accumulate, and never see a rupee deducted.
 *
 * ── What counts as a late mark ──
 *
 * A day already stamped 'Late' by AttendanceService, which compares check-in
 * against the workspace's own shift start plus its own grace period. The
 * decision is NOT re-derived here: doing that would give payroll a second
 * opinion about what "late" means, and the two would drift the first time
 * somebody changed the grace period.
 *
 * ── Why the thresholds are cumulative rather than exclusive ──
 *
 * Six late marks with the defaults costs a full day (0.5 at the third, another
 * 0.5 at the fifth), not half of one. That is what "थ्री लेट आफ्टर थ्री हाफ डे
 * कट होगा, फिफ्थ लेट मार्क्स के लिए सेकंड हाफ डे" describes — the second
 * penalty is *a further* deduction, not a replacement for the first.
 *
 * ── Why it deducts nothing by default ──
 *
 * `late_marks_enabled` is false out of the box. Switching on a deduction
 * silently, for a workspace that had been recording late marks with no
 * consequence, would take money off people who were never told the rule had
 * started applying.
 */
class LateMarkDeductionService
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * What this employee loses to late marks this period.
     *
     * @param  string  $period  'YYYY-MM'
     * @return array{late_marks:int, penalty_days:float, amount:float, reason:?string}
     */
    public function forEmployee(int $employeeId, int $tenantId, string $period, float $monthlyGross, float $payableDays): array
    {
        $none = ['late_marks' => 0, 'penalty_days' => 0.0, 'amount' => 0.0, 'reason' => null];

        $s = $this->settings->getGroup($tenantId, HrSetting::GROUP);

        if (! ($s['late_marks_enabled'] ?? false)) {
            return $none;
        }

        $count = $this->countLateMarks($employeeId, $tenantId, $period, (bool) ($s['late_marks_reset_monthly'] ?? true));

        if ($count === 0) {
            return $none;
        }

        $days = $this->penaltyDays($count, $s);

        if ($days <= 0) {
            return ['late_marks' => $count, 'penalty_days' => 0.0, 'amount' => 0.0, 'reason' => null];
        }

        // A day is worth gross / payable days. Using the payable days of THIS
        // month rather than a flat 30 matters in February and in any month a
        // joiner or leaver is paid part of — a fixed divisor quietly overcharges
        // them for the same lateness.
        $perDay = $payableDays > 0 ? $monthlyGross / $payableDays : 0.0;
        $amount = round($perDay * $days, 2);

        return [
            'late_marks'   => $count,
            'penalty_days' => $days,
            'amount'       => $amount,
            // Carried with the figure so the payslip can answer "why is my
            // salary short?" without anybody re-running payroll.
            'reason'       => sprintf(
                '%d late mark%s in %s — %s day%s deducted',
                $count, $count === 1 ? '' : 's', $period,
                rtrim(rtrim(number_format($days, 2, '.', ''), '0'), '.'),
                $days === 1.0 ? '' : 's'
            ),
        ];
    }

    /**
     * Late days in the period.
     *
     * When the count does NOT reset monthly the window runs from the start of
     * the financial year to the end of this period, so a persistent pattern is
     * not wiped clean every four weeks.
     */
    private function countLateMarks(int $employeeId, int $tenantId, string $period, bool $resetMonthly): int
    {
        $end = Carbon::parse($period.'-01')->endOfMonth();
        $start = $resetMonthly
            ? Carbon::parse($period.'-01')->startOfMonth()
            : $this->financialYearStart($end, $tenantId);

        // whereDate, not whereBetween on date strings.
        //
        // `date` is cast to a datetime and stored as '2026-07-31 00:00:00',
        // which sorts AFTER the plain bound '2026-07-31' — so a between-range
        // silently dropped the last day of every month. A late mark on the 31st
        // was never counted, and nothing about the result looked wrong.
        return HrAttendance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->where('status', 'Late')
            ->count();
    }

    private function financialYearStart(Carbon $end, int $tenantId): Carbon
    {
        $fyStart = (int) $this->settings->get($tenantId, 'payroll', 'fy_start_month', 4);
        $year = $end->month >= $fyStart ? $end->year : $end->year - 1;

        return Carbon::create($year, $fyStart, 1)->startOfDay();
    }

    /** Cumulative: crossing the second threshold adds to the first, never replaces it. */
    private function penaltyDays(int $count, array $s): float
    {
        $days = 0.0;

        $firstAt = (int) ($s['late_marks_first_penalty_at'] ?? 0);
        if ($firstAt > 0 && $count >= $firstAt) {
            $days += (float) ($s['late_marks_first_penalty_days'] ?? 0);
        }

        // 0 turns the second threshold off, as the setting's own help text says.
        $secondAt = (int) ($s['late_marks_second_penalty_at'] ?? 0);
        if ($secondAt > 0 && $count >= $secondAt) {
            $days += (float) ($s['late_marks_second_penalty_days'] ?? 0);
        }

        return round($days, 2);
    }
}
