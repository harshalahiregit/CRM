<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use App\Models\Hr\HrShift;
use App\Services\Hr\LeaveApplicationService;
use App\Services\Hr\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leave day counting is now driven by the employee's SHIFT, not by Carbon's
 * Saturday/Sunday.
 *
 * The regression test is the important one: an employee with no shift assignment
 * must count days exactly as before, or every existing tenant's leave balances
 * shift the day this ships.
 */
class LeaveShiftAwareTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function service(): LeaveApplicationService
    {
        return app(LeaveApplicationService::class);
    }

    private function shiftService(): ShiftService
    {
        return app(ShiftService::class);
    }

    private function employee(string $code = 'LV-1'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);
    }

    /** Employee + leave type + policy + an active balance to apply against. */
    private function withBalance(HrEmployee $employee, bool $weekendsCount = false): HrLeaveType
    {
        $type = HrLeaveType::create([
            'tenant_id' => $this->tenantId, 'name' => 'Casual '.$employee->employee_code,
            'code' => 'CL'.$employee->id, 'category' => 'Paid', 'paid' => true,
            'yearly_limit' => 24, 'requires_approval' => true, 'is_active' => true,
        ]);
        $policy = HrLeavePolicy::create([
            'tenant_id' => $this->tenantId, 'name' => 'Standard '.$employee->employee_code,
            'applies_to' => 'All', 'weekends_count' => $weekendsCount, 'holidays_count' => false,
            'half_day_allowed' => true, 'negative_balance_allowed' => false, 'is_active' => true,
        ]);
        HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'leave_policy_id' => $policy->id, 'leave_type_id' => $type->id,
            'allocated' => 24, 'opening_balance' => 24, 'used' => 0, 'adjusted' => 0,
            'carried_forward' => 0, 'available_balance' => 24,
            'effective_from' => '2026-01-01', 'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);

        return $type;
    }

    /** A shift whose week off falls on the given weekday numbers. */
    private function shiftWithOffDays(array $offDays, string $name = 'Custom', array $weekNumbers = []): array
    {
        $timings = [];
        for ($d = 0; $d <= 6; $d++) {
            $timings[] = in_array($d, $offDays, true)
                ? ['day_of_week' => $d, 'is_week_off' => true, 'week_numbers' => $weekNumbers]
                : ['day_of_week' => $d, 'start_time' => '09:00', 'end_time' => '18:00'];
        }

        return $this->shiftService()->createShift([
            'name' => $name, 'shift_type' => HrShift::FIXED, 'timings' => $timings,
        ], $this->tenantId);
    }

    private function assign(HrEmployee $employee, int $shiftId, string $from = '2026-01-01'): void
    {
        $this->shiftService()->assign([
            'employee_id' => $employee->id, 'shift_id' => $shiftId, 'effective_from' => $from,
        ], $this->tenantId);
    }

    private function apply(HrEmployee $employee, HrLeaveType $type, string $from, string $to): array
    {
        return $this->service()->apply([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'from_date' => $from, 'to_date' => $to, 'reason' => 'Test',
        ], $this->tenantId);
    }

    /* ── Backward compatibility ───────────────────────────────────────── */

    public function test_an_employee_with_no_shift_still_excludes_saturday_and_sunday(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee);

        // Mon 2026-04-06 → Sun 2026-04-12: 5 working days under the old rule.
        $app = $this->apply($employee, $type, '2026-04-06', '2026-04-12');

        $this->assertEquals(5, $app['days'], 'unchanged for tenants that have not set up shifts');
    }

    public function test_the_preview_reports_the_weekend_fallback_as_its_source(): void
    {
        $employee = $this->employee();
        $this->withBalance($employee);

        $preview = $this->service()->preview($employee->id, $this->tenantId, '2026-04-06', '2026-04-12');

        $this->assertSame('default_weekend', $preview['source']);
        $this->assertEquals(5, $preview['days']);
        $this->assertSame(2, $preview['excluded']);
    }

    /* ── Shift-driven counting ────────────────────────────────────────── */

    public function test_a_shift_whose_week_off_is_tuesday_excludes_tuesday_not_the_weekend(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee);
        $shift = $this->shiftWithOffDays([2], 'Tuesday Off');   // 2 = Tuesday
        $this->assign($employee, $shift['id']);

        // Mon 6 Apr → Sun 12 Apr. Only Tuesday the 7th is off, so 6 days count.
        $app = $this->apply($employee, $type, '2026-04-06', '2026-04-12');

        $this->assertEquals(6, $app['days'],
            'the weekend is a working day for this employee and must be charged');
    }

    public function test_a_six_day_week_charges_saturday(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee);
        $shift = $this->shiftWithOffDays([0], 'Sunday Only');
        $this->assign($employee, $shift['id']);

        $app = $this->apply($employee, $type, '2026-04-06', '2026-04-12');

        $this->assertEquals(6, $app['days']);
    }

    public function test_alternate_saturdays_are_respected(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee);
        // Sunday always off; Saturday off in weeks 2 and 4 only.
        $shift = $this->shiftService()->createShift([
            'name' => 'Alt Sat', 'shift_type' => HrShift::FIXED,
            'timings' => [
                ['day_of_week' => 0, 'is_week_off' => true],
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '18:00'],
                ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '18:00'],
                ['day_of_week' => 3, 'start_time' => '09:00', 'end_time' => '18:00'],
                ['day_of_week' => 4, 'start_time' => '09:00', 'end_time' => '18:00'],
                ['day_of_week' => 5, 'start_time' => '09:00', 'end_time' => '18:00'],
                ['day_of_week' => 6, 'is_week_off' => true, 'week_numbers' => [2, 4]],
            ],
        ], $this->tenantId);
        $this->assign($employee, $shift['id']);

        // Week 1 (Sat 4 Apr is a WORKING day): Fri 3 → Sun 5 = Fri + Sat = 2 days.
        $week1 = $this->service()->preview($employee->id, $this->tenantId, '2026-04-03', '2026-04-05');
        $this->assertEquals(2, $week1['days'], 'first Saturday is worked');

        // Week 2 (Sat 11 Apr IS off): Fri 10 → Sun 12 = Friday only.
        $week2 = $this->service()->preview($employee->id, $this->tenantId, '2026-04-10', '2026-04-12');
        $this->assertEquals(1, $week2['days'], 'second Saturday is off');
    }

    public function test_a_rotation_uses_whichever_shift_covers_each_day(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee);

        $sundayOff = $this->shiftWithOffDays([0], 'Sunday Off');
        $noOff     = $this->shiftWithOffDays([], 'Always On');

        $rotation = $this->shiftService()->saveRotation(null, [
            'name' => 'Weekly swap',
            'steps' => [
                ['shift_id' => $sundayOff['id'], 'duration_days' => 7],
                ['shift_id' => $noOff['id'],     'duration_days' => 7],
            ],
        ], $this->tenantId);

        $this->shiftService()->assign([
            'employee_id' => $employee->id, 'rotation_id' => $rotation['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        // 1–7 Apr is the Sunday-Off leg; 5 Apr is a Sunday → 6 of 7 days count.
        $legOne = $this->service()->preview($employee->id, $this->tenantId, '2026-04-01', '2026-04-07');
        $this->assertEquals(6, $legOne['days']);

        // 8–14 Apr is the Always-On leg; 12 Apr is a Sunday but is worked → 7 days.
        $legTwo = $this->service()->preview($employee->id, $this->tenantId, '2026-04-08', '2026-04-14');
        $this->assertEquals(7, $legTwo['days']);
    }

    public function test_assignment_history_is_honoured_for_a_past_range(): void
    {
        $employee = $this->employee();
        $this->withBalance($employee);

        $sundayOff = $this->shiftWithOffDays([0], 'Sunday Off');
        $tuesdayOff = $this->shiftWithOffDays([2], 'Tuesday Off');

        $this->assign($employee, $sundayOff['id'], '2026-01-01');
        $this->assign($employee, $tuesdayOff['id'], '2026-06-01');

        // April sits under the FIRST assignment even though a later one exists.
        $april = $this->service()->preview($employee->id, $this->tenantId, '2026-04-06', '2026-04-12');
        $this->assertEquals(6, $april['days'], 'Sunday off — the shift in force in April');

        // July sits under the second: Tuesday off, weekend worked.
        $july = $this->service()->preview($employee->id, $this->tenantId, '2026-07-06', '2026-07-12');
        $this->assertEquals(6, $july['days']);
        $this->assertSame('Weekly off (Tuesday Off)',
            collect($july['breakdown'])->firstWhere('date', '2026-07-07')['reason']);
    }

    /* ── The policy rule still wins ───────────────────────────────────── */

    public function test_a_policy_that_counts_non_working_days_counts_every_day(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee, weekendsCount: true);
        $shift = $this->shiftWithOffDays([0, 6], 'Sat Sun Off');
        $this->assign($employee, $shift['id']);

        $app = $this->apply($employee, $type, '2026-04-06', '2026-04-12');

        $this->assertEquals(7, $app['days'], 'weekends_count overrides the shift exclusion');
    }

    /* ── Edge cases ───────────────────────────────────────────────────── */

    public function test_a_range_that_is_entirely_off_days_is_refused_with_a_clear_reason(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee);
        $shift = $this->shiftWithOffDays([0, 6], 'Sat Sun Off');
        $this->assign($employee, $shift['id']);

        $this->expectExceptionMessage('entirely non-working days');
        $this->apply($employee, $type, '2026-04-11', '2026-04-12');   // Sat + Sun
    }

    public function test_a_half_day_is_still_half_a_day_whatever_the_shift(): void
    {
        $employee = $this->employee();
        $type = $this->withBalance($employee);
        $shift = $this->shiftWithOffDays([2], 'Tuesday Off');
        $this->assign($employee, $shift['id']);

        $app = $this->service()->apply([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'from_date' => '2026-04-06', 'to_date' => '2026-04-06', 'half_day' => true,
        ], $this->tenantId);

        $this->assertEquals(0.5, $app['days']);
    }

    public function test_the_breakdown_explains_every_excluded_day(): void
    {
        $employee = $this->employee();
        $this->withBalance($employee);
        $shift = $this->shiftWithOffDays([0], 'Sunday Off');
        $this->assign($employee, $shift['id']);

        $preview = $this->service()->preview($employee->id, $this->tenantId, '2026-04-06', '2026-04-12');

        $this->assertSame('shift', $preview['source']);
        $this->assertCount(7, $preview['breakdown']);

        $sunday = collect($preview['breakdown'])->firstWhere('date', '2026-04-12');
        $this->assertFalse($sunday['counted']);
        $this->assertSame('Weekly off (Sunday Off)', $sunday['reason']);

        $saturday = collect($preview['breakdown'])->firstWhere('date', '2026-04-11');
        $this->assertTrue($saturday['counted'], 'a working Saturday must be charged, and say so');
        $this->assertNull($saturday['reason']);
    }
}
