<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeWorkLocation;
use App\Services\Hr\WorkplaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Workplace Management.
 *
 * The behaviours worth protecting: the hierarchy cannot be made inconsistent (a
 * floor in another office is refused, not stored), deletes never silently erase
 * where people worked, and a branch's state reuses the SAME vocabulary as
 * Professional Tax so a city cannot masquerade as a jurisdiction.
 */
class WorkplaceManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private function service(): WorkplaceService
    {
        return app(WorkplaceService::class);
    }

    private function employee(string $code = 'WP-1'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Ops', 'designation' => 'Executive', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ]);
    }

    private function branch(string $name = 'Pune HQ', ?string $state = 'Maharashtra'): array
    {
        return $this->service()->saveBranch(null, [
            'name' => $name, 'code' => strtoupper(substr($name, 0, 3)),
            'city' => 'Pune', 'work_state' => $state, 'is_head_office' => true,
        ], $this->tenantId);
    }

    private function office(int $branchId, string $name = 'Tower A'): array
    {
        return $this->service()->saveOffice(null, ['branch_id' => $branchId, 'name' => $name], $this->tenantId);
    }

    private function floor(int $officeId, string $name = 'Third Floor'): array
    {
        return $this->service()->saveFloor(null, ['office_id' => $officeId, 'name' => $name, 'seat_capacity' => 50], $this->tenantId);
    }

    /* ── Hierarchy ────────────────────────────────────────────────────── */

    public function test_a_branch_office_and_floor_nest(): void
    {
        $branch = $this->branch();
        $office = $this->office($branch['id']);
        $floor  = $this->floor($office['id']);

        $this->assertSame($branch['id'], $office['branch_id']);
        $this->assertSame($office['id'], $floor['office_id']);
        $this->assertSame(50, $floor['seat_capacity']);

        $tree = $this->service()->tree($this->tenantId);
        $this->assertSame('Tower A', $tree[0]['offices'][0]['name']);
        $this->assertSame('Third Floor', $tree[0]['offices'][0]['floors'][0]['name']);
    }

    public function test_a_branch_state_uses_the_professional_tax_vocabulary(): void
    {
        // "MH" must normalise, exactly as it does on an employee — one list of
        // states across the whole system.
        $branch = $this->service()->saveBranch(null, ['name' => 'Mumbai', 'work_state' => 'MH'], $this->tenantId);

        $this->assertSame('Maharashtra', $branch['work_state']);
    }

    public function test_a_city_does_not_become_a_branch_state(): void
    {
        $branch = $this->service()->saveBranch(null, ['name' => 'Nagpur Site', 'work_state' => 'Nagpur'], $this->tenantId);

        $this->assertNull($branch['work_state'], 'a city is not a jurisdiction');
    }

    public function test_only_one_branch_can_be_head_office(): void
    {
        $first = $this->branch('Pune HQ');
        $second = $this->service()->saveBranch(null, ['name' => 'Delhi HQ', 'is_head_office' => true], $this->tenantId);

        $branches = collect($this->service()->branches($this->tenantId));

        $this->assertFalse($branches->firstWhere('id', $first['id'])['is_head_office']);
        $this->assertTrue($branches->firstWhere('id', $second['id'])['is_head_office']);
    }

    /* ── Deletes protect history ──────────────────────────────────────── */

    public function test_a_branch_with_offices_cannot_be_deleted(): void
    {
        $branch = $this->branch();
        $this->office($branch['id']);

        $this->expectExceptionMessage('has offices');
        $this->service()->deleteBranch($branch['id'], $this->tenantId);
    }

    public function test_a_branch_with_assignment_history_cannot_be_deleted(): void
    {
        $branch = $this->branch();
        $employee = $this->employee();
        $this->service()->assignLocation([
            'employee_id' => $employee->id, 'branch_id' => $branch['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        // Deleting would erase where this person worked — the one thing the module exists for.
        $this->expectExceptionMessage('Deactivate it instead');
        $this->service()->deleteBranch($branch['id'], $this->tenantId);
    }

    /* ── Assignment integrity ─────────────────────────────────────────── */

    public function test_an_office_from_another_branch_is_refused(): void
    {
        $branchA = $this->branch('Pune HQ');
        $branchB = $this->service()->saveBranch(null, ['name' => 'Delhi'], $this->tenantId);
        $officeB = $this->office($branchB['id'], 'Delhi Tower');
        $employee = $this->employee();

        $this->expectExceptionMessage('does not belong to the selected branch');
        $this->service()->assignLocation([
            'employee_id' => $employee->id, 'branch_id' => $branchA['id'],
            'office_id' => $officeB['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);
    }

    public function test_a_floor_without_its_office_is_refused(): void
    {
        $branch = $this->branch();
        $office = $this->office($branch['id']);
        $floor  = $this->floor($office['id']);
        $employee = $this->employee();

        $this->expectExceptionMessage('Choose the office');
        $this->service()->assignLocation([
            'employee_id' => $employee->id, 'branch_id' => $branch['id'],
            'floor_id' => $floor['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);
    }

    /* ── Assignment == history ────────────────────────────────────────── */

    public function test_reassigning_closes_the_previous_location(): void
    {
        $branch = $this->branch();
        $other  = $this->service()->saveBranch(null, ['name' => 'Bengaluru', 'work_state' => 'Karnataka'], $this->tenantId);
        $employee = $this->employee();

        $this->service()->assignLocation(['employee_id' => $employee->id, 'branch_id' => $branch['id'], 'effective_from' => '2026-04-01'], $this->tenantId);
        $this->service()->assignLocation(['employee_id' => $employee->id, 'branch_id' => $other['id'], 'effective_from' => '2026-09-01', 'reason' => 'Relocated'], $this->tenantId);

        $history = $this->service()->locationHistory($employee->id, $this->tenantId);

        $this->assertCount(2, $history);
        $this->assertSame('Bengaluru', $history[0]['branch_name']);
        $this->assertTrue($history[0]['is_current']);
        $this->assertSame('2026-08-31', $history[1]['effective_to']);

        $open = HrEmployeeWorkLocation::where('employee_id', $employee->id)->whereNull('effective_to')->count();
        $this->assertSame(1, $open);
    }

    public function test_seating_lists_only_current_locations(): void
    {
        $branch = $this->branch();
        $office = $this->office($branch['id']);
        $floor  = $this->floor($office['id']);
        $a = $this->employee('WP-A');
        $b = $this->employee('WP-B');

        foreach ([$a, $b] as $e) {
            $this->service()->assignLocation([
                'employee_id' => $e->id, 'branch_id' => $branch['id'], 'office_id' => $office['id'],
                'floor_id' => $floor['id'], 'seat_no' => 'S-'.$e->id, 'effective_from' => '2026-04-01',
            ], $this->tenantId);
        }

        $seating = $this->service()->seating($this->tenantId, ['floor_id' => $floor['id']]);
        $this->assertCount(2, $seating);

        $floors = $this->service()->floors($this->tenantId, $office['id']);
        $this->assertSame(2, $floors[0]['seats_used'], 'occupancy counts current assignments only');
    }

    /* ── The work_state payoff ────────────────────────────────────────── */

    public function test_sync_work_state_copies_the_branch_jurisdiction_onto_the_employee(): void
    {
        $branch = $this->service()->saveBranch(null, ['name' => 'Bengaluru', 'work_state' => 'Karnataka'], $this->tenantId);
        $employee = $this->employee();

        $this->service()->assignLocation([
            'employee_id' => $employee->id, 'branch_id' => $branch['id'],
            'effective_from' => '2026-04-01', 'sync_work_state' => true,
        ], $this->tenantId);

        $this->assertSame('Karnataka', $employee->fresh()->work_state,
            'so Professional Tax stops depending on per-employee data entry');
    }

    public function test_work_state_is_not_synced_unless_asked(): void
    {
        $branch = $this->service()->saveBranch(null, ['name' => 'Bengaluru', 'work_state' => 'Karnataka'], $this->tenantId);
        $employee = $this->employee();

        $this->service()->assignLocation([
            'employee_id' => $employee->id, 'branch_id' => $branch['id'], 'effective_from' => '2026-04-01',
        ], $this->tenantId);

        // A desk move must not silently change what tax someone pays.
        $this->assertNull($employee->fresh()->work_state);
    }
}
