<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeShift;
use App\Models\Hr\HrShift;
use App\Services\Hr\ShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shift Management.
 *
 * Two behaviours carry the design and are asserted hardest:
 *  - assignment and history are ONE table, so assigning closes the previous row
 *    instead of deleting it;
 *  - weekly off lives on the shift's weekday timing, including the alternate-week
 *    pattern, so "is this Saturday off?" has exactly one answer.
 */
class ShiftManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function service(): ShiftService
    {
        return app(ShiftService::class);
    }

    private function employee(string $code = 'SH-1'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);
    }

    /** Mon–Fri 09:00–18:00, Sunday off. Saturday is passed in by the caller. */
    private function generalShift(array $saturday = ['is_week_off' => true]): array
    {
        $timings = [['day_of_week' => 0, 'is_week_off' => true]];
        for ($d = 1; $d <= 5; $d++) {
            $timings[] = ['day_of_week' => $d, 'start_time' => '09:00', 'end_time' => '18:00'];
        }
        $timings[] = ['day_of_week' => 6] + $saturday;

        return $this->service()->createShift([
            'name' => 'General', 'code' => 'GEN', 'shift_type' => HrShift::FIXED,
            'full_day_hours' => 8, 'half_day_hours' => 4, 'grace_in_minutes' => 15,
            'timings' => $timings,
        ], $this->tenantId);
    }

    private function nightShift(): array
    {
        return $this->service()->createShift([
            'name' => 'Night', 'code' => 'NGT', 'shift_type' => HrShift::FIXED, 'is_night_shift' => true,
            'timings' => array_map(fn ($d) => ['day_of_week' => $d, 'start_time' => '22:00', 'end_time' => '06:00'], range(0, 6)),
        ], $this->tenantId);
    }

    /* ── Master + timings ─────────────────────────────────────────────── */

    public function test_a_shift_stores_its_weekday_timings_and_week_offs(): void
    {
        $shift = $this->generalShift();

        $this->assertCount(7, $shift['timings']);
        $this->assertSame(['Sunday', 'Saturday'], $shift['week_offs']);
        $this->assertSame('09:00', $shift['timings'][1]['start_time'], 'Monday');
    }

    public function test_a_week_off_day_carries_no_timing(): void
    {
        $shift = $this->generalShift();
        $sunday = collect($shift['timings'])->firstWhere('day_of_week', 0);

        // A day that is off must not also claim working hours — the two would
        // contradict each other the moment something read only one of them.
        $this->assertTrue($sunday['is_week_off']);
        $this->assertNull($sunday['start_time']);
    }

    public function test_saving_timings_replaces_them_rather_than_merging(): void
    {
        $shift = $this->generalShift();

        $updated = $this->service()->updateShift($shift['id'], [
            'timings' => [['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '19:00']],
        ], $this->tenantId);

        $this->assertCount(1, $updated['timings'], 'a removed day must not survive as a stale week off');
        $this->assertSame([], $updated['week_offs']);
    }

    public function test_a_shift_in_use_cannot_be_deleted(): void
    {
        $shift = $this->generalShift();
        $employee = $this->employee();
        $this->service()->assign([
            'employee_id' => $employee->id, 'shift_id' => $shift['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        $this->expectExceptionMessage('Deactivate it instead');
        $this->service()->deleteShift($shift['id'], $this->tenantId);
    }

    /* ── Weekly off resolution ────────────────────────────────────────── */

    public function test_a_weekly_off_is_resolved_for_a_date(): void
    {
        $shift = $this->generalShift();
        $employee = $this->employee();
        $this->service()->assign([
            'employee_id' => $employee->id, 'shift_id' => $shift['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        // 2026-04-05 is a Sunday, 2026-04-06 a Monday.
        $this->assertTrue($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-05'));
        $this->assertFalse($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-06'));
    }

    public function test_an_alternate_saturday_pattern_is_honoured(): void
    {
        // Saturday off in weeks 2 and 4 only — the common Indian pattern.
        $shift = $this->generalShift(['is_week_off' => true, 'week_numbers' => [2, 4]]);
        $employee = $this->employee();
        $this->service()->assign([
            'employee_id' => $employee->id, 'shift_id' => $shift['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        // April 2026 Saturdays: 4th (week 1), 11th (week 2), 18th (week 3), 25th (week 4).
        $this->assertFalse($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-04'), 'week 1');
        $this->assertTrue($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-11'), 'week 2');
        $this->assertFalse($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-18'), 'week 3');
        $this->assertTrue($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-25'), 'week 4');
    }

    public function test_an_unassigned_employee_resolves_to_no_shift_rather_than_failing(): void
    {
        $employee = $this->employee();

        $result = $this->service()->shiftForDate($employee->id, $this->tenantId, '2026-04-06');

        // Additive by design: code written before shifts existed keeps working.
        $this->assertNull($result['shift']);
        $this->assertFalse($result['is_week_off']);
        $this->assertStringContainsString('No shift assigned', $result['reason']);
    }

    /* ── Assignment == history ────────────────────────────────────────── */

    public function test_assigning_closes_the_previous_assignment_instead_of_deleting_it(): void
    {
        $general = $this->generalShift();
        $night   = $this->nightShift();
        $employee = $this->employee();

        $this->service()->assign([
            'employee_id' => $employee->id, 'shift_id' => $general['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);
        $this->service()->assign([
            'employee_id' => $employee->id, 'shift_id' => $night['id'], 'effective_from' => '2026-07-01',
            'reason' => 'Moved to night operations',
        ], $this->tenantId);

        $history = $this->service()->history($employee->id, $this->tenantId);

        $this->assertCount(2, $history, 'the old assignment IS the history');
        $this->assertSame('Night', $history[0]['shift_name']);
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame('2026-06-30', $history[1]['effective_to'], 'closed the day before the new one starts');
        $this->assertFalse($history[1]['is_current']);
    }

    public function test_only_one_assignment_is_ever_current(): void
    {
        $general = $this->generalShift();
        $night   = $this->nightShift();
        $employee = $this->employee();

        foreach ([['2026-04-01', $general['id']], ['2026-07-01', $night['id']], ['2026-10-01', $general['id']]] as [$from, $shiftId]) {
            $this->service()->assign([
                'employee_id' => $employee->id, 'shift_id' => $shiftId, 'effective_from' => $from,
            ], $this->tenantId);
        }

        $open = HrEmployeeShift::where('employee_id', $employee->id)->whereNull('effective_to')->count();
        $this->assertSame(1, $open, 'two open assignments would make "which shift?" unanswerable');
    }

    public function test_a_past_date_resolves_to_the_shift_in_force_then(): void
    {
        $general = $this->generalShift();
        $night   = $this->nightShift();
        $employee = $this->employee();

        $this->service()->assign(['employee_id' => $employee->id, 'shift_id' => $general['id'], 'effective_from' => '2026-04-01'], $this->tenantId);
        $this->service()->assign(['employee_id' => $employee->id, 'shift_id' => $night['id'], 'effective_from' => '2026-07-01'], $this->tenantId);

        $this->assertSame('General', $this->service()->shiftForDate($employee->id, $this->tenantId, '2026-05-15')['shift']->name);
        $this->assertSame('Night', $this->service()->shiftForDate($employee->id, $this->tenantId, '2026-08-15')['shift']->name);
    }

    public function test_backdating_an_assignment_before_the_current_one_is_refused(): void
    {
        $general = $this->generalShift();
        $employee = $this->employee();
        $this->service()->assign(['employee_id' => $employee->id, 'shift_id' => $general['id'], 'effective_from' => '2026-07-01'], $this->tenantId);

        $this->expectExceptionMessage('Choose a later date');
        $this->service()->assign(['employee_id' => $employee->id, 'shift_id' => $general['id'], 'effective_from' => '2026-05-01'], $this->tenantId);
    }

    public function test_assigning_both_a_shift_and_a_rotation_is_refused(): void
    {
        $general = $this->generalShift();
        $rotation = $this->service()->saveRotation(null, [
            'name' => 'Two week', 'steps' => [['shift_id' => $general['id'], 'duration_days' => 7]],
        ], $this->tenantId);
        $employee = $this->employee();

        $this->expectExceptionMessage('not both, and not neither');
        $this->service()->assign([
            'employee_id' => $employee->id, 'shift_id' => $general['id'],
            'rotation_id' => $rotation['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);
    }

    /* ── Rotation ─────────────────────────────────────────────────────── */

    public function test_a_rotation_moves_the_employee_through_its_steps(): void
    {
        $general = $this->generalShift();
        $night   = $this->nightShift();
        $rotation = $this->service()->saveRotation(null, [
            'name' => 'Day/Night weekly',
            'steps' => [
                ['shift_id' => $general['id'], 'duration_days' => 7],
                ['shift_id' => $night['id'],   'duration_days' => 7],
            ],
        ], $this->tenantId);

        $this->assertSame(14, $rotation['cycle_days']);

        $employee = $this->employee();
        $this->service()->assign([
            'employee_id' => $employee->id, 'rotation_id' => $rotation['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        $on = fn (string $d) => $this->service()->shiftForDate($employee->id, $this->tenantId, $d)['shift']?->name;

        $this->assertSame('General', $on('2026-04-01'), 'day 0 — first step');
        $this->assertSame('General', $on('2026-04-07'), 'day 6 — still the first step');
        $this->assertSame('Night',   $on('2026-04-08'), 'day 7 — second step');
        $this->assertSame('Night',   $on('2026-04-14'), 'day 13 — end of the second step');
        $this->assertSame('General', $on('2026-04-15'), 'day 14 — the cycle repeats');
    }

    public function test_a_rotation_carries_the_week_off_of_whichever_shift_is_active(): void
    {
        $general = $this->generalShift();       // Sunday off
        $night   = $this->nightShift();          // works every day
        $rotation = $this->service()->saveRotation(null, [
            'name' => 'Alternating',
            'steps' => [
                ['shift_id' => $general['id'], 'duration_days' => 7],
                ['shift_id' => $night['id'],   'duration_days' => 7],
            ],
        ], $this->tenantId);

        $employee = $this->employee();
        $this->service()->assign([
            'employee_id' => $employee->id, 'rotation_id' => $rotation['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        // 2026-04-05 (Sunday, week 1 → General) vs 2026-04-12 (Sunday, week 2 → Night).
        $this->assertTrue($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-05'));
        $this->assertFalse($this->service()->isWeekOff($employee->id, $this->tenantId, '2026-04-12'));
    }

    public function test_a_rotation_with_no_steps_cannot_be_assigned(): void
    {
        $rotation = $this->service()->saveRotation(null, ['name' => 'Empty', 'steps' => []], $this->tenantId);
        $employee = $this->employee();

        $this->expectExceptionMessage('never resolve to a shift');
        $this->service()->assign([
            'employee_id' => $employee->id, 'rotation_id' => $rotation['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);
    }

    /* ── Roster ───────────────────────────────────────────────────────── */

    public function test_the_roster_shows_only_current_assignments(): void
    {
        $general = $this->generalShift();
        $night   = $this->nightShift();
        $a = $this->employee('SH-A');
        $b = $this->employee('SH-B');

        $this->service()->assign(['employee_id' => $a->id, 'shift_id' => $general['id'], 'effective_from' => '2026-04-01'], $this->tenantId);
        $this->service()->assign(['employee_id' => $a->id, 'shift_id' => $night['id'], 'effective_from' => '2026-07-01'], $this->tenantId);
        $this->service()->assign(['employee_id' => $b->id, 'shift_id' => $general['id'], 'effective_from' => '2026-04-01'], $this->tenantId);

        $roster = $this->service()->roster($this->tenantId);

        $this->assertCount(2, $roster, 'one row per employee, not per assignment ever made');
        $this->assertSame('Night', collect($roster)->firstWhere('employee_code', 'SH-A')['shift_name']);
    }
}
