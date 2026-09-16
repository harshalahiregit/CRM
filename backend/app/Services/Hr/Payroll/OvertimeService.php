<?php

namespace App\Services\Hr\Payroll;

use App\Models\Hr\HrAttendance;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Support\Carbon;

/**
 * Overtime, as an earning rather than a number on a report.
 *
 * Attendance has stamped `overtime_hours` on every day since it was built, and
 * payroll never read the column — so the hours were visible on the attendance
 * screen, counted in reports, and worth nothing on the payslip. It was named as
 * an allowance head on 5 Sep alongside the deduction heads.
 *
 * ── The hourly rate ──
 *
 * gross ÷ (payable days × standard day hours). The standard day comes from the
 * workspace's own working-day settings, the same ones AttendanceService uses to
 * decide what counted as overtime in the first place — deriving it separately
 * here would let payroll and attendance disagree about the length of a day.
 *
 * ── The daily cap ──
 *
 * Applied PER DAY, not to the month's total, which is the only way it means
 * anything: a cap on the monthly figure lets one twelve-hour Sunday through
 * untouched while trimming a month of ordinary half-hours. Capped hours are
 * still recorded on attendance; they are simply not paid.
 */
class OvertimeService
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * Payable overtime for one employee this period.
     *
     * @param  string  $period  'YYYY-MM'
     * @return array{hours:float, amount:float, rate:float}
     */
    public function forEmployee(int $employeeId, int $tenantId, string $period, float $monthlyGross, float $payableDays): array
    {
        $none = ['hours' => 0.0, 'amount' => 0.0, 'rate' => 0.0];

        $s = $this->settings->getGroup($tenantId, HrSetting::GROUP);

        if (! ($s['overtime_enabled'] ?? false)) {
            return $none;
        }

        $start = Carbon::parse($period.'-01')->startOfMonth();
        $end   = Carbon::parse($period.'-01')->endOfMonth();

        $cap = (float) ($s['overtime_daily_cap_hours'] ?? 0);

        $daily = HrAttendance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            // whereDate, not whereBetween — `date` is stored as a datetime, so
            // '2026-07-31 00:00:00' sorts after the bound '2026-07-31' and the
            // last day of every month was silently excluded.
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->where('overtime_hours', '>', 0)
            ->pluck('overtime_hours');

        // Capped day by day — see the docblock.
        $hours = $daily->reduce(function ($carry, $h) use ($cap) {
            $h = (float) $h;

            return $carry + ($cap > 0 ? min($h, $cap) : $h);
        }, 0.0);

        if ($hours <= 0) {
            return $none;
        }

        $standardHours = (float) ($s['standard_day_hours'] ?: 8);
        $divisor = $payableDays * $standardHours;

        if ($divisor <= 0) {
            return $none;
        }

        $rate = ($monthlyGross / $divisor) * (float) ($s['overtime_multiplier'] ?? 1);

        return [
            'hours'  => round($hours, 2),
            'amount' => round($rate * $hours, 2),
            // Surfaced so the payslip can show what the hours were paid at,
            // which is the first thing anybody queries about overtime.
            'rate'   => round($rate, 2),
        ];
    }
}
