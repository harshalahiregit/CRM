<?php

namespace Tests\Feature\Hr\Attendance;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrHoliday;
use App\Models\Hr\HrLeaveApplication;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Services\Hr\Attendance\CrmAttendanceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Paid days, from the CRM's own attendance.
 *
 * Payroll previously took every day of the month as payable, so somebody absent
 * for a week was paid for it. The danger in fixing that is the opposite error: a
 * workspace not yet using attendance would read as absent all month and be paid
 * NOTHING. Both directions are pinned here.
 */
class CrmAttendanceProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-04';   // 30 days, safely in the past

    private int $tenantId;

    private HrEmployee $employee;

    private CrmAttendanceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = Tenant::create(['name' => 'S', 'slug' => 'att-prov', 'status' => 'active'])->id;

        $this->employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        $this->provider = app(CrmAttendanceProvider::class);
    }

    private function mark(string $date, string $status): void
    {
        HrAttendance::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $this->employee->id,
            'date' => $date, 'status' => $status,
        ]);
    }

    private function read(): array
    {
        return $this->provider->forPeriod($this->employee->id, $this->tenantId, self::PERIOD);
    }

    /** The failure mode that matters most: no attendance must not mean no pay. */
    public function test_a_month_with_no_attendance_at_all_is_paid_in_full(): void
    {
        $r = $this->read();

        $this->assertSame(30.0, $r['payable_days']);
        $this->assertSame(0.0, $r['absent_days']);
        $this->assertStringContainsString('No attendance recorded', (string) $r['message']);
    }

    /** Once attendance IS being kept, a day with no record is loss of pay. */
    public function test_a_missing_day_is_loss_of_pay_once_attendance_exists(): void
    {
        for ($d = 1; $d <= 29; $d++) {
            $this->mark(sprintf('2026-04-%02d', $d), 'Present');
        }

        $r = $this->read();

        $this->assertSame(29.0, $r['payable_days']);
        $this->assertSame(1.0, $r['absent_days'], 'A day nobody recorded is not a paid day');
    }

    public function test_absent_days_are_not_paid(): void
    {
        for ($d = 1; $d <= 30; $d++) {
            $this->mark(sprintf('2026-04-%02d', $d), $d <= 25 ? 'Present' : 'Absent');
        }

        $r = $this->read();

        $this->assertSame(25.0, $r['payable_days']);
        $this->assertSame(5.0, $r['absent_days']);
    }

    /** Weekends and holidays are paid — a monthly salary covers the whole month. */
    public function test_weekends_and_holidays_are_paid(): void
    {
        for ($d = 1; $d <= 30; $d++) {
            $status = match (true) {
                $d % 7 === 0 => 'Weekend',
                $d === 14    => 'Holiday',
                default      => 'Present',
            };
            $this->mark(sprintf('2026-04-%02d', $d), $status);
        }

        $r = $this->read();

        $this->assertSame(30.0, $r['payable_days'], 'Nobody is docked for a Sunday');
        $this->assertSame(0.0, $r['absent_days']);
    }

    public function test_a_half_day_pays_half(): void
    {
        for ($d = 1; $d <= 30; $d++) {
            $this->mark(sprintf('2026-04-%02d', $d), $d === 10 ? 'Half Day' : 'Present');
        }

        $r = $this->read();

        $this->assertSame(29.5, $r['payable_days']);
        $this->assertSame(0.5, $r['absent_days']);
        $this->assertSame(1.0, $r['half_days']);
    }

    /** Paid leave is paid; unpaid leave is leave AND loss of pay. */
    public function test_paid_and_unpaid_leave_are_not_the_same_thing(): void
    {
        $paid = HrLeaveType::create([
            'tenant_id' => $this->tenantId, 'name' => 'Casual', 'code' => 'CL',
            'category' => 'Casual', 'paid' => true, 'is_active' => true,
        ]);

        $unpaid = HrLeaveType::create([
            'tenant_id' => $this->tenantId, 'name' => 'Leave Without Pay', 'code' => 'LWP',
            'category' => 'Other', 'paid' => false, 'is_active' => true,
        ]);

        foreach ([[$paid, '2026-04-05', '2026-04-06'], [$unpaid, '2026-04-10', '2026-04-11']] as [$type, $from, $to]) {
            HrLeaveApplication::create([
                'tenant_id' => $this->tenantId, 'employee_id' => $this->employee->id,
                'leave_type_id' => $type->id, 'from_date' => $from, 'to_date' => $to,
                'days' => 2, 'status' => 'Approved',
            ]);
        }

        for ($d = 1; $d <= 30; $d++) {
            $this->mark(sprintf('2026-04-%02d', $d), in_array($d, [5, 6, 10, 11], true) ? 'Leave' : 'Present');
        }

        $r = $this->read();

        // 26 worked + 2 paid leave = 28. The two unpaid days are loss of pay.
        $this->assertSame(28.0, $r['payable_days']);
        $this->assertSame(2.0, $r['absent_days'], 'Unpaid leave is loss of pay');
        $this->assertSame(4.0, $r['leave_days'], 'but all four are still leave');
    }

    /** A holiday nobody recorded a row for is still a holiday. */
    public function test_a_holiday_with_no_attendance_row_is_still_paid(): void
    {
        HrHoliday::create([
            'tenant_id' => $this->tenantId, 'title' => 'Gudi Padwa',
            'holiday_date' => '2026-04-15', 'holiday_type' => 'Festival',
            'is_optional' => false, 'is_active' => true,
        ]);

        for ($d = 1; $d <= 30; $d++) {
            if ($d === 15) {
                continue;
            }
            $this->mark(sprintf('2026-04-%02d', $d), 'Present');
        }

        $r = $this->read();

        $this->assertSame(30.0, $r['payable_days']);
        $this->assertSame(0.0, $r['absent_days']);
    }

    /** Nobody can be absent on a day that has not happened. */
    public function test_a_future_month_is_not_absence(): void
    {
        $next = Carbon::today()->addMonthNoOverflow()->format('Y-m');

        $r = $this->provider->forPeriod($this->employee->id, $this->tenantId, $next);

        $this->assertSame(0.0, $r['absent_days']);
        $this->assertStringContainsString('has not started', (string) $r['message']);
    }

    /** One employee's absence is not another's. */
    public function test_attendance_is_read_per_employee(): void
    {
        $other = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => 'E2', 'name' => 'Meera',
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);

        for ($d = 1; $d <= 30; $d++) {
            $this->mark(sprintf('2026-04-%02d', $d), $d <= 20 ? 'Present' : 'Absent');
        }

        $mine   = $this->read();
        $theirs = $this->provider->forPeriod($other->id, $this->tenantId, self::PERIOD);

        $this->assertSame(10.0, $mine['absent_days']);
        $this->assertSame(0.0, $theirs['absent_days'], 'Meera has no attendance, so she is not tracked');
    }
}
