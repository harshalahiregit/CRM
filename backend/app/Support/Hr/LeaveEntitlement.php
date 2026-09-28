<?php

namespace App\Support\Hr;

use Illuminate\Support\Carbon;

/**
 * How many days of a leave type an employee receives for THIS leave year.
 *
 * ONE PLACE, because two allocation paths ask the same question and must not
 * answer it differently: the hr:allocate-leave command works from the leave
 * TYPE's yearly_limit, and assignPolicy() works from the POLICY mapping's
 * yearly_allocation. Both are "the configured annual entitlement", which is why
 * this class takes that figure as an argument rather than reading either
 * column itself — the caller already knows which one governs.
 *
 * THE PROBLEM IT SOLVES. Entitlement was front-loaded in full regardless of
 * when somebody joined, so a 1 December starter received a whole year's leave
 * on their first day. That is not merely generous: ExitSettlementService
 * encashes available_balance at per-day basic, so unearned days were paid out
 * in cash on exit.
 *
 * OFF BY DEFAULT. leave_prorate_first_year is false unless a workspace turns it
 * on, and with it off every figure here is exactly what it was before — this
 * class returns the configured entitlement unchanged. Nothing about an existing
 * deployment moves until somebody decides it should.
 *
 * FIRST YEAR ONLY. Proration applies when, and only when, the employee's
 * joining date falls inside the leave year being allocated. Somebody who joined
 * in a previous year is present for all twelve months of this one and receives
 * the full entitlement — which also means reassigning a policy to a long-serving
 * employee behaves exactly as it always has.
 *
 * WHAT IT IS NOT. This calculates the CURRENT year's allocation. Carry-forward
 * from the previous year is LeaveCarryForward's job, separately, and the two
 * must never call each other: carrying forward a prorated balance is correct,
 * but prorating the carried-forward days themselves would apply the fraction
 * twice to the same leave.
 */
final class LeaveEntitlement
{
    /**
     * The leave year is the CALENDAR year. January to December.
     *
     * A decision, recorded here rather than made configurable. The product had
     * no leave-year concept at all beyond one `now()->startOfYear()` in the
     * allocation command, and payroll's separate April–March financial year is
     * a tax boundary rather than a leave one. Adding a setting for something
     * with a single agreed answer would be configuration nobody asked for.
     */
    private const MONTHS_IN_YEAR = 12;

    /**
     * Days to allocate for one leave type, for one employee, for one year.
     *
     * @param  float       $annualEntitlement  the configured figure — yearly_limit
     *                                         or the policy's yearly_allocation
     * @param  string|null $joiningDate        hr_employees.joining_date
     * @param  bool        $prorate            the tenant's leave_prorate_first_year
     * @param  mixed       $leaveYearAnchor    any date inside the leave year being
     *                                         allocated; defaults to today
     */
    public static function forAllocation(
        float $annualEntitlement,
        ?string $joiningDate,
        bool $prorate,
        $leaveYearAnchor = null
    ): float {
        // The setting is off, or there is nothing to divide, or we do not know
        // when they started — in all three the configured figure stands. A
        // missing joining date is not a reason to reduce somebody's leave.
        if (! $prorate || $annualEntitlement <= 0 || ! $joiningDate) {
            return $annualEntitlement;
        }

        $leaveYear = ($leaveYearAnchor ? Carbon::parse($leaveYearAnchor) : Carbon::now())->year;
        $joined    = Carbon::parse($joiningDate);

        // Joined in an earlier year: present for the whole of this one.
        if ($joined->year < $leaveYear) {
            return $annualEntitlement;
        }

        // Joins in a LATER year — they are not employed during this leave year
        // at all, so they earn nothing in it. Zero rather than a negative
        // month count, which is what the arithmetic below would otherwise
        // produce for a future-dated hire.
        if ($joined->year > $leaveYear) {
            return 0.0;
        }

        return self::forMonths($annualEntitlement, self::eligibleMonths($joined));
    }

    /**
     * Months of this leave year the employee is present for, joining month
     * INCLUDED.
     *
     * A January joiner gets 12 and a December joiner gets 1, whatever day of
     * the month they started — someone who joins on the 15th is treated as
     * having that month. The alternative, counting the joining month only when
     * somebody starts in its first half, is a second rule to explain for a
     * difference of a few hours' entitlement.
     */
    private static function eligibleMonths(Carbon $joined): int
    {
        return self::MONTHS_IN_YEAR - $joined->month + 1;
    }

    /**
     * entitlement × months ÷ 12, rounded DOWN to the nearest half day.
     *
     * DOWN, because this figure is encashable. Rounding a fraction up hands
     * somebody leave they have not earned and, on exit, money with it; rounding
     * down at worst leaves half a day that HR can grant explicitly.
     *
     * HALF a day, because half-days are the smallest unit leave is consumed in
     * — LeaveApplicationService books 0.5 — so an entitlement of 11.3 could
     * never be fully spent.
     *
     * INTEGER ARITHMETIC THROUGHOUT. The obvious floor($raw * 2) / 2 is at the
     * mercy of binary floating point: a value that should be exactly 7.5 can
     * arrive as 7.4999999999999991 and floor away a whole half day. So the
     * entitlement is taken to tenths (the column is decimal(6,1), so tenths
     * lose nothing), and a single intdiv by 60 — twelve months × five tenths
     * per half day — does the division and the rounding in one exact step.
     */
    private static function forMonths(float $annualEntitlement, int $months): float
    {
        $tenths    = (int) round($annualEntitlement * 10);
        $halfDays  = intdiv($tenths * $months, self::MONTHS_IN_YEAR * 5);

        return $halfDays * 0.5;
    }
}
