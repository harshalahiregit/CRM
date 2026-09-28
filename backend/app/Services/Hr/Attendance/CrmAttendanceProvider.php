<?php

namespace App\Services\Hr\Attendance;

use App\Contracts\Hr\AttendanceProvider;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrHoliday;
use App\Models\Hr\HrLeaveApplication;
use Illuminate\Support\Carbon;

/**
 * Paid days from the CRM's own attendance, rather than a placeholder full month.
 *
 * Until now payroll took every day of the month as payable, so an employee who
 * was absent for a week was paid for it. That was correct while attendance lived
 * in another system and nothing here could see it — but the app now clocks in
 * and out against hr_attendance, leave is approved in hr_leave_applications and
 * holidays are records, so all three are readable.
 *
 * WHAT COUNTS AS PAID, and why:
 *
 *   Present, Late, Half Day, Work From Home, Remote  — worked, so paid. Late is
 *       a discipline matter handled by late marks, not by docking a day.
 *   Weekend, Holiday  — paid. Deducting them would pay a monthly employee for
 *       22 days and call the other 9 absence.
 *   Leave  — paid when the leave type is paid, and only then. This is the one
 *       that has to look past the attendance row to the application behind it.
 *   Absent, and any day with NO record at all  — loss of pay.
 *
 * That last clause is the important one. A month with no attendance rows would
 * otherwise read as "no absences" and pay in full, which is exactly the failure
 * the placeholder had. A day nobody recorded is treated as absent, so a missing
 * import is visible in the payroll rather than silently paid.
 *
 * A month in the FUTURE, or the remainder of the current month, is not counted
 * as absence — nobody can have attended a day that has not happened.
 */
class CrmAttendanceProvider implements AttendanceProvider
{
    /** Statuses that are worked or otherwise paid, whatever the clock says. */
    private const PAID_STATUSES = [
        'Present', 'Late', 'Work From Home', 'Remote', 'Holiday', 'Weekend',
    ];

    private const HALF_DAY = 'Half Day';

    public function isConnected(): bool
    {
        return true;
    }

    public function source(): string
    {
        return 'CRM Attendance';
    }

    public function forPeriod(int $employeeId, int $tenantId, string $period): array
    {
        try {
            [$year, $month] = array_map('intval', explode('-', $period));
            $start = Carbon::create($year, $month, 1)->startOfDay();
        } catch (\Throwable $e) {
            return $this->unreadable($period);
        }

        $end       = $start->copy()->endOfMonth();
        $daysInMonth = $start->daysInMonth;

        // Days that have not happened yet cannot be absences.
        $countUpTo = $end->isFuture() ? Carbon::today() : $end;

        if ($countUpTo->lt($start)) {
            return $this->futurePeriod($period, $daysInMonth);
        }

        $countableDays = (int) $start->diffInDays($countUpTo) + 1;

        $rows = HrAttendance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $countUpTo->toDateString())
            ->get(['date', 'status']);

        $paid     = 0.0;
        $halfDays = 0.0;
        $leave    = 0.0;
        $recorded = [];

        $paidLeaveDates = $this->paidLeaveDates($employeeId, $tenantId, $start, $countUpTo);
        $holidayDates   = $this->holidayDates($tenantId, $start, $countUpTo);

        // NOT TRACKED is not the same as ABSENT.
        //
        // Treating a day with no record as loss of pay is right for somebody whose
        // attendance is being kept — a missing day is then a real absence, and a
        // failed import shows up in the payroll instead of being paid silently.
        //
        // It is catastrophic for a workspace that has not started using attendance
        // at all: every employee reads as absent every day and is paid nothing.
        // So a month with NO attendance and NO approved leave is read as "not
        // tracked" and pays in full, exactly as the placeholder did.
        if ($rows->isEmpty() && $paidLeaveDates === []) {
            return [
                'connected'    => true,
                'source'       => $this->source(),
                'period'       => $period,
                'payable_days' => (float) $daysInMonth,
                'absent_days'  => 0.0,
                'leave_days'   => 0.0,
                'half_days'    => 0.0,
                'message'      => 'No attendance recorded for this period — paid in full. Record attendance to prorate.',
            ];
        }

        foreach ($rows as $row) {
            $date = Carbon::parse($row->date)->toDateString();
            $recorded[$date] = true;

            if (in_array($row->status, self::PAID_STATUSES, true)) {
                $paid += 1;

                continue;
            }

            if ($row->status === self::HALF_DAY) {
                $paid     += 0.5;
                $halfDays += 1;

                continue;
            }

            if ($row->status === 'Leave') {
                // Paid only when the approved application says so. Unpaid leave is
                // leave AND loss of pay, and conflating the two overpays.
                $leave += 1;

                if (isset($paidLeaveDates[$date])) {
                    $paid += 1;
                }

                continue;
            }
            // 'Absent' — nothing added.
        }

        // Days with no attendance row at all. A holiday or an approved paid leave
        // still counts as paid even when nobody recorded a row for it, which is
        // the normal case for both.
        for ($d = $start->copy(); $d->lte($countUpTo); $d->addDay()) {
            $date = $d->toDateString();

            if (isset($recorded[$date])) {
                continue;
            }

            if (isset($holidayDates[$date])) {
                $paid += 1;

                continue;
            }

            if (isset($paidLeaveDates[$date])) {
                $paid  += 1;
                $leave += 1;

                continue;
            }
            // No record, no holiday, no leave — loss of pay.
        }

        $absent = round($countableDays - $paid, 2);

        return [
            'connected'    => true,
            'source'       => $this->source(),
            'period'       => $period,
            // Never more than the month itself, whatever the data says.
            'payable_days' => (float) min(round($paid, 2), $daysInMonth),
            'absent_days'  => (float) max($absent, 0),
            'leave_days'   => (float) $leave,
            'half_days'    => (float) $halfDays,
            'message'      => null,
        ];
    }

    /**
     * Dates covered by an APPROVED, PAID leave application.
     *
     * @return array<string, true>
     */
    private function paidLeaveDates(int $employeeId, int $tenantId, Carbon $start, Carbon $end): array
    {
        $applications = HrLeaveApplication::with('leaveType:id,paid')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', 'Approved')
            ->whereDate('from_date', '<=', $end->toDateString())
            ->whereDate('to_date', '>=', $start->toDateString())
            ->get(['id', 'leave_type_id', 'from_date', 'to_date']);

        $dates = [];

        foreach ($applications as $a) {
            if (! ($a->leaveType?->paid ?? false)) {
                continue;   // unpaid leave is loss of pay
            }

            $from = Carbon::parse($a->from_date)->max($start);
            $to   = Carbon::parse($a->to_date)->min($end);

            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $dates[$d->toDateString()] = true;
            }
        }

        return $dates;
    }

    /** @return array<string, true> */
    private function holidayDates(int $tenantId, Carbon $start, Carbon $end): array
    {
        // The column is holiday_date, not date. And an OPTIONAL holiday is not
        // automatically a paid day off — it is one the employee may choose to
        // take, so it only counts when they actually recorded it as a holiday.
        return HrHoliday::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('is_optional')->orWhere('is_optional', false))
            ->whereDate('holiday_date', '>=', $start->toDateString())
            ->whereDate('holiday_date', '<=', $end->toDateString())
            ->pluck('holiday_date')
            ->mapWithKeys(fn ($d) => [Carbon::parse($d)->toDateString() => true])
            ->all();
    }

    /** A period we could not parse pays nothing rather than guessing a month. */
    private function unreadable(string $period): array
    {
        return [
            'connected' => true, 'source' => $this->source(), 'period' => $period,
            'payable_days' => 0.0, 'absent_days' => 0.0, 'leave_days' => 0.0, 'half_days' => 0.0,
            'message' => 'Could not read the payroll period',
        ];
    }

    /** Nothing has happened yet, so nothing is absence. */
    private function futurePeriod(string $period, int $daysInMonth): array
    {
        return [
            'connected' => true, 'source' => $this->source(), 'period' => $period,
            'payable_days' => (float) $daysInMonth, 'absent_days' => 0.0,
            'leave_days' => 0.0, 'half_days' => 0.0,
            'message' => 'This period has not started yet',
        ];
    }
}
