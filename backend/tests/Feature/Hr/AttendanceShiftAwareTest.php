<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrShift;
use App\Services\Hr\AttendanceService;
use App\Services\Hr\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Attendance takes its shift timing — and whether a day is a weekly off — from the
 * employee's assignment rather than the five hardcoded presets.
 *
 * The regression test matters most: an employee with no assignment must still open
 * on the 'General' preset as Absent, including on a Saturday, exactly as before.
 */
class AttendanceShiftAwareTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function attendance(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function shifts(): ShiftService
    {
        return app(ShiftService::class);
    }

    private function employee(string $code = 'AT-1'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);
    }

    /** Night shift 22:00–06:00, Sunday off, 20 minutes of grace. */
    private function nightShift(): array
    {
        $timings = [['day_of_week' => 0, 'is_week_off' => true]];
        for ($d = 1; $d <= 6; $d++) {
            $timings[] = ['day_of_week' => $d, 'start_time' => '22:00', 'end_time' => '06:00'];
        }

        return $this->shifts()->createShift([
            'name' => 'Night Ops', 'shift_type' => HrShift::FIXED, 'is_night_shift' => true,
            'grace_in_minutes' => 20, 'timings' => $timings,
        ], $this->tenantId);
    }

    private function assign(HrEmployee $employee, int $shiftId, string $from = '2026-01-01'): void
    {
        $this->shifts()->assign([
            'employee_id' => $employee->id, 'shift_id' => $shiftId, 'effective_from' => $from,
        ], $this->tenantId);
    }

    /* ── Backward compatibility ───────────────────────────────────────── */

    public function test_an_unassigned_employee_opens_on_the_general_preset(): void
    {
        $employee = $this->employee();

        $record = $this->attendance()->ensureRecord($employee, '2026-04-06');

        $this->assertSame('General', $record->shift);
        $this->assertSame('09:00', $record->shift_start);
        $this->assertSame('18:00', $record->shift_end);
        $this->assertSame(15, (int) $record->grace_period);
        $this->assertSame('Absent', $record->status);
    }

    public function test_an_unassigned_employee_opens_a_saturday_as_absent_not_weekend(): void
    {
        $employee = $this->employee();

        // Unchanged behaviour: nothing auto-marked weekends before shifts existed,
        // and an employee with no shift must not start behaving differently.
        $record = $this->attendance()->ensureRecord($employee, '2026-04-11');

        $this->assertSame('Absent', $record->status);
        $this->assertSame('General', $record->shift);
    }

    /* ── Shift-driven ─────────────────────────────────────────────────── */

    public function test_an_assigned_shift_supplies_its_own_timing_and_grace(): void
    {
        $employee = $this->employee();
        $shift = $this->nightShift();
        $this->assign($employee, $shift['id']);

        $record = $this->attendance()->ensureRecord($employee, '2026-04-06');

        // The tenant's own shift name and times, not one of the five presets.
        $this->assertSame('Night Ops', $record->shift);
        $this->assertSame('22:00', $record->shift_start);
        $this->assertSame('06:00', $record->shift_end);
        $this->assertSame(20, (int) $record->grace_period);
    }

    public function test_a_weekly_off_opens_as_weekend_rather_than_absent(): void
    {
        $employee = $this->employee();
        $shift = $this->nightShift();      // Sunday off
        $this->assign($employee, $shift['id']);

        $sunday = $this->attendance()->ensureRecord($employee, '2026-04-12');
        $monday = $this->attendance()->ensureRecord($employee, '2026-04-13');

        $this->assertSame('Weekend', $sunday->status, 'nobody is absent on their day off');
        $this->assertSame('Absent', $monday->status);
    }

    public function test_a_saturday_is_a_working_day_when_the_shift_says_so(): void
    {
        $employee = $this->employee();
        $shift = $this->nightShift();      // works Mon–Sat
        $this->assign($employee, $shift['id']);

        $record = $this->attendance()->ensureRecord($employee, '2026-04-11');

        $this->assertSame('Absent', $record->status, 'Saturday is worked on this shift');
    }

    public function test_alternate_saturdays_drive_the_opening_status(): void
    {
        $employee = $this->employee();
        $shift = $this->shifts()->createShift([
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

        $this->assertSame('Absent',  $this->attendance()->ensureRecord($employee, '2026-04-04')->status, 'week 1 Saturday is worked');
        $this->assertSame('Weekend', $this->attendance()->ensureRecord($employee, '2026-04-11')->status, 'week 2 Saturday is off');
    }

    public function test_an_explicit_shift_still_overrides_the_assignment(): void
    {
        $employee = $this->employee();
        $shift = $this->nightShift();
        $this->assign($employee, $shift['id']);

        // A manual entry or an external sync naming a shift is a deliberate
        // override, not a guess to be second-guessed.
        $record = $this->attendance()->ensureRecord($employee, '2026-04-06', 'Morning');

        $this->assertSame('Morning', $record->shift);
        $this->assertSame('06:00', $record->shift_start);
    }

    public function test_lateness_is_judged_against_the_assigned_shift_grace(): void
    {
        $employee = $this->employee();
        $shift = $this->shifts()->createShift([
            'name' => 'Late Start', 'shift_type' => HrShift::FIXED, 'grace_in_minutes' => 30,
            'timings' => array_map(fn ($d) => ['day_of_week' => $d, 'start_time' => '10:00', 'end_time' => '19:00'], range(0, 6)),
        ], $this->tenantId);
        $this->assign($employee, $shift['id']);

        $record = $this->attendance()->ensureRecord($employee, '2026-04-06');
        $onTime = $this->attendance()->checkIn($record, '2026-04-06 10:25:00');

        // 10:25 is late against a 09:00 General preset but inside this shift's
        // 10:00 + 30 minutes — the assigned shift is what counts.
        $this->assertSame('Present', $onTime->status);

        $employee2 = $this->employee('AT-2');
        $this->assign($employee2, $shift['id']);
        $late = $this->attendance()->checkIn(
            $this->attendance()->ensureRecord($employee2, '2026-04-06'), '2026-04-06 10:45:00'
        );
        $this->assertSame('Late', $late->status);
    }

    public function test_assignment_history_decides_a_past_date(): void
    {
        $employee = $this->employee();
        $night = $this->nightShift();
        $day = $this->shifts()->createShift([
            'name' => 'Day Ops', 'shift_type' => HrShift::FIXED,
            'timings' => array_map(fn ($d) => ['day_of_week' => $d, 'start_time' => '09:00', 'end_time' => '18:00'], range(0, 6)),
        ], $this->tenantId);

        $this->assign($employee, $night['id'], '2026-01-01');
        $this->assign($employee, $day['id'], '2026-06-01');

        $this->assertSame('Night Ops', $this->attendance()->ensureRecord($employee, '2026-04-06')->shift);
        $this->assertSame('Day Ops',   $this->attendance()->ensureRecord($employee, '2026-07-06')->shift);
    }

    public function test_an_existing_record_is_not_rewritten_by_a_later_assignment(): void
    {
        $employee = $this->employee();
        $record = $this->attendance()->ensureRecord($employee, '2026-04-06');
        $this->assertSame('General', $record->shift);

        // Assigning a shift afterwards must not retro-edit a day already opened —
        // that day's timing is what the employee was actually held to.
        $shift = $this->nightShift();
        $this->assign($employee, $shift['id'], '2026-01-01');

        $again = $this->attendance()->ensureRecord($employee, '2026-04-06');
        $this->assertSame('General', $again->shift);
    }
}
