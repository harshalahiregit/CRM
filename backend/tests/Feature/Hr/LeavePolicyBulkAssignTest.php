<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeavePolicyType;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rolling one leave policy out to a whole group.
 *
 * Assignment was one employee at a time, so "everyone in Sales moves to the new
 * policy" meant forty trips through the same modal -- and the Department scope a
 * policy could carry described who it was for without anything acting on it.
 *
 * The properties worth holding: only ACTIVE people in the chosen group are
 * touched, nobody outside it is, and an employee who cannot be assigned comes
 * back by name rather than silently reducing a count.
 */
class LeavePolicyBulkAssignTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private HrDepartment $sales;
    private HrDepartment $ops;
    private HrLeavePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'bulk-t', 'status' => 'active']);
        $this->sales = HrDepartment::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales', 'code' => 'SAL', 'is_active' => true]);
        $this->ops   = HrDepartment::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops', 'code' => 'OPS', 'is_active' => true]);

        $type = HrLeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Casual Leave', 'code' => 'CL',
            'category' => 'Casual', 'paid' => true, 'yearly_limit' => 12, 'is_active' => true,
        ]);

        $this->policy = HrLeavePolicy::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sales 2026', 'applies_to' => 'Department',
            'department_id' => $this->sales->id, 'is_active' => true,
        ]);

        HrLeavePolicyType::create([
            'policy_id' => $this->policy->id, 'leave_type_id' => $type->id,
            'yearly_allocation' => 12, 'carry_forward_limit' => 3,
        ]);
    }

    private function employee(string $code, HrDepartment $dept, string $status = 'Active'): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => $dept->name, 'department_id' => $dept->id, 'designation' => 'Executive',
            'status' => $status, 'joining_date' => '2020-01-01',
        ]);
    }

    private function actAsHr(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'HR', 'email' => 'hr@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]));
    }

    private function assign(array $body)
    {
        return $this->postJson('/api/hr/leave/balances/assign-bulk', $body);
    }

    public function test_a_department_gets_the_policy_and_nobody_else_does(): void
    {
        $this->actAsHr();
        $inSales = $this->employee('S1', $this->sales);
        $alsoSales = $this->employee('S2', $this->sales);
        $inOps = $this->employee('O1', $this->ops);

        $res = $this->assign([
            'leave_policy_id' => $this->policy->id,
            'scope' => 'department',
            'department_id' => $this->sales->id,
        ])->assertStatus(201)->json();

        $this->assertSame(2, $res['matched']);
        $this->assertSame(2, $res['assigned']);
        $this->assertSame([], $res['failed']);

        foreach ([$inSales, $alsoSales] as $e) {
            $this->assertDatabaseHas('hr_employee_leave_balances', [
                'employee_id' => $e->id, 'leave_policy_id' => $this->policy->id, 'allocated' => 12,
            ]);
        }

        $this->assertDatabaseMissing('hr_employee_leave_balances', ['employee_id' => $inOps->id]);
    }

    public function test_inactive_employees_are_left_out(): void
    {
        $this->actAsHr();
        $this->employee('S1', $this->sales);
        $left = $this->employee('S9', $this->sales, 'Inactive');

        $res = $this->assign([
            'leave_policy_id' => $this->policy->id,
            'scope' => 'department',
            'department_id' => $this->sales->id,
        ])->assertStatus(201)->json();

        $this->assertSame(1, $res['matched'], 'Someone who has left should not be allocated a year of leave');
        $this->assertDatabaseMissing('hr_employee_leave_balances', ['employee_id' => $left->id]);
    }

    public function test_scope_all_covers_every_department(): void
    {
        $this->actAsHr();
        $this->employee('S1', $this->sales);
        $this->employee('O1', $this->ops);

        $res = $this->assign(['leave_policy_id' => $this->policy->id, 'scope' => 'all'])
            ->assertStatus(201)->json();

        $this->assertSame(2, $res['assigned']);
    }

    public function test_a_department_with_nobody_active_is_refused_rather_than_reported_as_done(): void
    {
        $this->actAsHr();
        $this->employee('O1', $this->ops);

        // A silent "0 assigned, all good" is how a rollout is believed to have
        // happened when it has not.
        $this->assign([
            'leave_policy_id' => $this->policy->id,
            'scope' => 'department',
            'department_id' => $this->sales->id,
        ])->assertStatus(422);
    }

    public function test_a_department_scope_without_a_department_is_refused(): void
    {
        $this->actAsHr();
        $this->employee('S1', $this->sales);

        $this->assign(['leave_policy_id' => $this->policy->id, 'scope' => 'department'])
            ->assertStatus(422);
    }

    public function test_reassigning_archives_the_previous_balances_rather_than_stacking_them(): void
    {
        $this->actAsHr();
        $e = $this->employee('S1', $this->sales);

        $this->assign(['leave_policy_id' => $this->policy->id, 'scope' => 'department', 'department_id' => $this->sales->id]);
        $this->assign(['leave_policy_id' => $this->policy->id, 'scope' => 'department', 'department_id' => $this->sales->id]);

        $active = HrEmployeeLeaveBalance::where('employee_id', $e->id)->where('status', 'active')->count();
        $this->assertSame(1, $active, 'Two active balances for one leave type is a double allowance');
    }

    public function test_someone_who_cannot_manage_the_hr_queue_is_refused(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Staff', 'email' => 'staff@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]));
        $this->employee('S1', $this->sales);

        $this->assign(['leave_policy_id' => $this->policy->id, 'scope' => 'all'])->assertStatus(403);
    }
}
