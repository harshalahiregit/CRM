<?php

namespace Tests\Unit\Hr;

use App\Support\Hr\FinancialYear;
use PHPUnit\Framework\TestCase;

/**
 * The financial year boundary. Getting January wrong is the classic bug — it
 * belongs to the year that STARTED the previous April.
 */
class FinancialYearTest extends TestCase
{
    public function test_april_starts_a_new_year(): void
    {
        $this->assertSame('2026-2027', FinancialYear::forDate('2026-04-01')->label());
        $this->assertSame('2025-2026', FinancialYear::forDate('2026-03-31')->label());
    }

    public function test_january_belongs_to_the_year_that_started_last_april(): void
    {
        $fy = FinancialYear::forDate('2027-01-15');

        $this->assertSame('2026-2027', $fy->label());
        $this->assertSame('2026-27', $fy->shortLabel());
    }

    public function test_month_index_counts_from_the_start_month(): void
    {
        $fy = FinancialYear::forDate('2026-06-01');

        $this->assertSame(1, $fy->monthIndex(4), 'April is month 1');
        $this->assertSame(3, $fy->monthIndex(6), 'June is month 3');
        $this->assertSame(10, $fy->monthIndex(1), 'January is month 10');
        $this->assertSame(12, $fy->monthIndex(3), 'March is month 12');
    }

    public function test_remaining_months_includes_the_current_one(): void
    {
        $fy = FinancialYear::forDate('2026-04-01');

        $this->assertSame(12, $fy->remainingMonths(4), 'a full year ahead in April');
        $this->assertSame(1, $fy->remainingMonths(3), 'March is the last month');
        $this->assertSame(3, $fy->remainingMonths(1));
    }

    public function test_periods_before_excludes_the_current_month(): void
    {
        $fy = FinancialYear::forDate('2026-07-01');
        $before = $fy->periodsBefore('2026-07');

        $this->assertSame(['2026-04', '2026-05', '2026-06'], $before);
        $this->assertNotContains('2026-07', $before, 'the month being processed is not "already paid"');
    }

    public function test_periods_wrap_across_the_calendar_year(): void
    {
        $periods = FinancialYear::forDate('2026-05-01')->periods();

        $this->assertCount(12, $periods);
        $this->assertSame('2026-04', $periods[0]);
        $this->assertSame('2027-03', $periods[11]);
    }

    public function test_a_non_april_start_month_is_honoured(): void
    {
        // Not every jurisdiction starts in April — the start month is a parameter.
        $fy = FinancialYear::forDate('2026-02-10', startMonth: 1);

        $this->assertSame('2026-2027', $fy->label());
        $this->assertSame(2, $fy->monthIndex(2));
        $this->assertSame('2026-01', $fy->periods()[0]);
    }

    public function test_a_label_round_trips(): void
    {
        $this->assertSame('2026-2027', FinancialYear::fromLabel('2026-2027')->label());
        $this->assertNull(FinancialYear::fromLabel('rubbish'));
        $this->assertNull(FinancialYear::fromLabel(null));
    }

    public function test_contains_covers_the_whole_year_inclusively(): void
    {
        $fy = FinancialYear::forDate('2026-04-01');

        $this->assertTrue($fy->contains('2026-04-01'));
        $this->assertTrue($fy->contains('2027-03-31'));
        $this->assertFalse($fy->contains('2027-04-01'));
    }
}
