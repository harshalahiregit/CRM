<?php

namespace Tests\Feature\Hr\Payroll;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Services\Hr\Payroll\LateMarkDeductionService;
use App\Services\Hr\Payroll\OvertimeService;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The last day of the month counts.
 *
 * `hr_attendance.date` is cast to a datetime and stored as
 * '2026-07-31 00:00:00'. A `whereBetween('date', ['2026-07-01', '2026-07-31'])`
 * compares that against the plain string bound, where the stored value sorts
 * AFTER it — so the 31st fell outside the range and was silently dropped.
 *
 * Both the late-mark counter and the overtime totaliser used that pattern. A
 * late mark on the last day of the month was never counted, overtime worked on
 * the 31st was never paid, and nothing in either result looked wrong: the
 * figures were simply smaller than they should have been.
 *
 * The existing tests all wrote a full month with the interesting days at the
 * START, so none of them touched a month boundary. These do.
 */
class MonthBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    private HrEmployee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'month-edge', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $this->employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => 'EDGE1', 'name' => 'Edge Case',
            'department' => 'Ops', 'designation' => 'Staff',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        app(SettingsService::class)->setGroup($this->tenantId, HrSetting::GROUP, [
            'late_marks_enabled' => true,
            'late_marks_first_penalty_at' => 1,     // one mark is enough to see it
            'late_marks_first_penalty_days' => 1,
            'late_marks_second_penalty_at' => 0,
            'overtime_enabled' => true,
            'overtime_multiplier' => 1,
            'standard_day_hours' => 8,
        ]);
    }

    private function day(string $date, string $status, float $overtime = 0): void
    {
        HrAttendance::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $this->employee->id,
            'date' => $date, 'status' => $status, 'overtime_hours' => $overtime,
        ]);
    }

    /** A late mark on the 31st is a late mark. */
    public function test_a_late_mark_on_the_last_day_of_the_month_is_counted(): void
    {
        $this->day('2026-07-31', 'Late');

        $result = app(LateMarkDeductionService::class)
            ->forEmployee($this->employee->id, $this->tenantId, '2026-07', 31000, 31);

        $this->assertSame(1, $result['late_marks'], 'the 31st was being dropped by the date range');
        $this->assertEquals(1000, $result['amount']);
    }

    /** And on the 1st, the other edge. */
    public function test_a_late_mark_on_the_first_day_is_counted(): void
    {
        $this->day('2026-07-01', 'Late');

        $this->assertSame(1, app(LateMarkDeductionService::class)
            ->forEmployee($this->employee->id, $this->tenantId, '2026-07', 31000, 31)['late_marks']);
    }

    /** Overtime worked on the 31st is paid. */
    public function test_overtime_on_the_last_day_of_the_month_is_paid(): void
    {
        $this->day('2026-07-31', 'Present', 4);

        $result = app(OvertimeService::class)
            ->forEmployee($this->employee->id, $this->tenantId, '2026-07', 31000, 31);

        $this->assertEquals(4, $result['hours'], 'the 31st was being dropped by the date range');
        $this->assertGreaterThan(0, $result['amount']);
    }

    /** A 30-day month has the same edge, one day earlier. */
    public function test_the_last_day_of_a_thirty_day_month_is_counted(): void
    {
        $this->day('2026-06-30', 'Late');

        $this->assertSame(1, app(LateMarkDeductionService::class)
            ->forEmployee($this->employee->id, $this->tenantId, '2026-06', 30000, 30)['late_marks']);
    }

    /** February, because it is the month everything else gets wrong too. */
    public function test_the_last_day_of_february_is_counted(): void
    {
        $this->day('2026-02-28', 'Late');

        $this->assertSame(1, app(LateMarkDeductionService::class)
            ->forEmployee($this->employee->id, $this->tenantId, '2026-02', 28000, 28)['late_marks']);
    }

    /** And a neighbouring month's last day does NOT leak in. */
    public function test_the_previous_months_last_day_is_not_counted(): void
    {
        $this->day('2026-06-30', 'Late');
        $this->day('2026-07-15', 'Late');

        $this->assertSame(1, app(LateMarkDeductionService::class)
            ->forEmployee($this->employee->id, $this->tenantId, '2026-07', 31000, 31)['late_marks']);
    }
}
