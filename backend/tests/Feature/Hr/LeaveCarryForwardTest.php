<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeavePolicyType;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Services\Hr\EmployeeLeaveBalanceService;
use App\Support\Hr\LeaveCarryForward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What actually carries into the next leave period.
 *
 * TWO CEILINGS AND A GATE, all three already configurable and only one of them
 * previously consulted:
 *
 *   hr_leave_types.carry_forward      the gate. The Leave Types screen prints
 *                                     "—" when it is off — while the engine
 *                                     carried the leave anyway.
 *   hr_leave_types.max_carry_forward  the type's ceiling, printed "≤ N" on
 *                                     that same screen and read by nothing.
 *   hr_leave_policy_types.carry_forward_limit
 *                                     the policy's ceiling, the only one the
 *                                     allocator used.
 *
 * Both ceilings are shown to the user as maxima, so two of them together mean
 * the lower one binds. That is arithmetic rather than a new rule, and it is
 * what lets a type capped at five days stay capped under a more generous
 * policy.
 *
 * NO NAMES ANYWHERE. Carry-forward is decided by configuration on the record,
 * never by what a leave type is called.
 */
class LeaveCarryForwardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'cf-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'cf-b', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function type(array $attrs = [], ?Tenant $t = null): HrLeaveType
    {
        return HrLeaveType::create(array_merge([
            'tenant_id' => ($t ?: $this->a)->id,
            'name' => 'Type '.substr(uniqid(), -5),
            'code' => strtoupper(substr(uniqid(), -5)),
            'category' => 'Casual', 'paid' => true, 'yearly_limit' => 12,
            'carry_forward' => false, 'max_carry_forward' => 0,
            'requires_attachment' => false, 'requires_approval' => true, 'is_active' => true,
        ], $attrs));
    }

    private function employee(?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);
    }

    /** A policy mapping one type at a given allocation and carry-forward limit. */
    private function policy(HrLeaveType $type, float $allocation, float $cfLimit, ?Tenant $t = null): HrLeavePolicy
    {
        $policy = HrLeavePolicy::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'P'.substr(uniqid(), -5),
            'applies_to' => 'All', 'weekends_count' => false, 'holidays_count' => false,
            'half_day_allowed' => true, 'negative_balance_allowed' => false, 'is_active' => true,
        ]);

        HrLeavePolicyType::create([
            'policy_id' => $policy->id, 'leave_type_id' => $type->id,
            'yearly_allocation' => $allocation, 'carry_forward_limit' => $cfLimit,
        ]);

        return $policy;
    }

    /** An existing active balance the next assignment will carry from. */
    private function priorBalance(HrEmployee $employee, HrLeaveType $type, float $available): HrEmployeeLeaveBalance
    {
        return HrEmployeeLeaveBalance::create([
            'tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id,
            'leave_type_id' => $type->id, 'allocated' => $available, 'opening_balance' => $available,
            'used' => 0, 'adjusted' => 0, 'carried_forward' => 0,
            'available_balance' => $available, 'effective_from' => '2025-01-01',
            'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);
    }

    private function assign(HrEmployee $employee, HrLeavePolicy $policy): void
    {
        app(EmployeeLeaveBalanceService::class)->assignPolicy([
            'employee_id' => $employee->id, 'leave_policy_id' => $policy->id,
            'effective_from' => '2026-01-01',
        ], (int) $employee->tenant_id);
    }

    private function carriedFor(HrEmployee $employee, HrLeaveType $type): float
    {
        return (float) HrEmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)
            ->value('carried_forward');
    }

    /* ══ THE RESOLVER, ON ITS OWN ═════════════════════════════════════ */

    public function test_a_type_that_does_not_carry_forward_caps_at_zero(): void
    {
        $type = $this->type(['carry_forward' => false, 'max_carry_forward' => 99]);
        $pt = new HrLeavePolicyType(['carry_forward_limit' => 50]);

        // The gate wins over both ceilings — a generous policy cannot switch
        // carry-forward on for a type whose own answer is no.
        $this->assertSame(0.0, LeaveCarryForward::cap($type, $pt));
        $this->assertSame(0.0, LeaveCarryForward::forBalance($type, $pt, 40));
    }

    public function test_the_lower_of_the_two_ceilings_binds(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 5]);

        $this->assertSame(5.0, LeaveCarryForward::cap($type, new HrLeavePolicyType(['carry_forward_limit' => 10])));
        $this->assertSame(3.0, LeaveCarryForward::cap($type, new HrLeavePolicyType(['carry_forward_limit' => 3])));
    }

    public function test_the_type_ceiling_applies_with_no_policy_mapping(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 7]);

        $this->assertSame(7.0, LeaveCarryForward::cap($type, null));
    }

    public function test_nothing_carries_without_a_type_record(): void
    {
        // A mapping whose leave type has gone. Inventing a ceiling for a record
        // nothing can describe is worse than declining to.
        $this->assertSame(0.0, LeaveCarryForward::cap(null, new HrLeavePolicyType(['carry_forward_limit' => 10])));
    }

    public function test_an_overdrawn_balance_carries_nothing(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10]);

        // A workspace that allows going negative does not carry the debt into
        // the new year.
        $this->assertSame(0.0, LeaveCarryForward::forBalance($type, null, -4));
    }

    /* ══ THROUGH THE REAL ASSIGNMENT ══════════════════════════════════ */

    public function test_an_eligible_balance_carries_forward(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10]);
        $employee = $this->employee();
        $this->priorBalance($employee, $type, 6);

        $this->assign($employee, $this->policy($type, 12, 10));

        $this->assertSame(6.0, $this->carriedFor($employee, $type));
    }

    public function test_a_balance_above_the_cap_carries_only_the_cap(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 4]);
        $employee = $this->employee();
        $this->priorBalance($employee, $type, 11);

        $this->assign($employee, $this->policy($type, 12, 10));

        // The type's 4 binds, not the policy's 10.
        $this->assertSame(4.0, $this->carriedFor($employee, $type));
    }

    public function test_the_policy_cap_binds_when_it_is_the_lower_one(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 20]);
        $employee = $this->employee();
        $this->priorBalance($employee, $type, 15);

        $this->assign($employee, $this->policy($type, 12, 3));

        $this->assertSame(3.0, $this->carriedFor($employee, $type));
    }

    public function test_a_type_marked_not_to_carry_forward_carries_nothing(): void
    {
        $type = $this->type(['carry_forward' => false, 'max_carry_forward' => 0]);
        $employee = $this->employee();
        $this->priorBalance($employee, $type, 9);

        // The policy is generous and the type still says no. This is the
        // disagreement the fix ends: the screen printed "—" while the engine
        // carried nine days.
        $this->assign($employee, $this->policy($type, 12, 10));

        $this->assertSame(0.0, $this->carriedFor($employee, $type));
    }

    public function test_a_balance_below_the_cap_carries_what_there_was(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10]);
        $employee = $this->employee();
        $this->priorBalance($employee, $type, 2.5);

        $this->assign($employee, $this->policy($type, 12, 10));

        $this->assertSame(2.5, $this->carriedFor($employee, $type));
    }

    public function test_a_new_employee_with_no_prior_balance_carries_zero(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10]);
        $employee = $this->employee();

        $this->assign($employee, $this->policy($type, 12, 10));

        $this->assertSame(0.0, $this->carriedFor($employee, $type));
        $this->assertSame(12.0, (float) HrEmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->value('allocated'));
    }

    public function test_each_type_uses_its_own_configuration(): void
    {
        $carries = $this->type(['carry_forward' => true, 'max_carry_forward' => 5]);
        $doesNot = $this->type(['carry_forward' => false]);

        $employee = $this->employee();
        $this->priorBalance($employee, $carries, 8);
        $this->priorBalance($employee, $doesNot, 8);

        $policy = $this->policy($carries, 12, 10);
        HrLeavePolicyType::create([
            'policy_id' => $policy->id, 'leave_type_id' => $doesNot->id,
            'yearly_allocation' => 12, 'carry_forward_limit' => 10,
        ]);

        $this->assign($employee, $policy);

        $this->assertSame(5.0, $this->carriedFor($employee, $carries));
        $this->assertSame(0.0, $this->carriedFor($employee, $doesNot));
    }

    /**
     * The decision is configuration, never the name.
     *
     * @dataProvider suggestiveNames
     */
    public function test_a_types_name_never_decides_carry_forward(string $name): void
    {
        $type = $this->type(['name' => $name, 'carry_forward' => true, 'max_carry_forward' => 3]);
        $employee = $this->employee();
        $this->priorBalance($employee, $type, 9);

        $this->assign($employee, $this->policy($type, 12, 10));

        $this->assertSame(3.0, $this->carriedFor($employee, $type),
            "the name \"{$name}\" changed the carry-forward");
    }

    public static function suggestiveNames(): array
    {
        return [['Earned Leave'], ['Casual Leave'], ['Sick Leave'], ['Unpaid Leave'], ['Comp Off']];
    }

    public function test_the_available_balance_includes_what_carried(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10]);
        $employee = $this->employee();
        $this->priorBalance($employee, $type, 4);

        $this->assign($employee, $this->policy($type, 12, 10));

        $balance = HrEmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->firstOrFail();

        $this->assertSame(4.0, (float) $balance->carried_forward);
        $this->assertSame(16.0, (float) $balance->available_balance);
    }

    /* ══ HISTORY IS NOT REWRITTEN ═════════════════════════════════════ */

    public function test_the_previous_balance_is_deactivated_not_destroyed(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10]);
        $employee = $this->employee();
        $prior = $this->priorBalance($employee, $type, 6);

        $this->assign($employee, $this->policy($type, 12, 10));

        $fresh = $prior->fresh();
        $this->assertNotNull($fresh, 'the prior period must remain readable');
        $this->assertSame(HrEmployeeLeaveBalance::INACTIVE, $fresh->status);
        $this->assertSame(6.0, (float) $fresh->available_balance, 'a closed period must not be recomputed');
    }

    public function test_an_existing_carried_forward_figure_is_left_alone(): void
    {
        $type = $this->type(['carry_forward' => false]);
        $employee = $this->employee();

        // A balance written before this fix, carrying days the type now says
        // it should not have. Nothing rewrites it.
        $legacy = $this->priorBalance($employee, $type, 5);
        $legacy->update(['carried_forward' => 5]);

        $this->assertSame(5.0, (float) $legacy->fresh()->carried_forward);
    }

    /* ══ TENANCY ══════════════════════════════════════════════════════ */

    public function test_carry_forward_never_reads_another_workspaces_balance(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10]);
        $mine = $this->employee();
        $theirs = $this->employee($this->b);

        // A generous prior balance in the other workspace.
        $theirType = $this->type(['carry_forward' => true, 'max_carry_forward' => 10], $this->b);
        $this->priorBalance($theirs, $theirType, 20);

        $this->assign($mine, $this->policy($type, 12, 10));

        // Nothing carried here, and the other workspace's balance is untouched.
        $this->assertSame(0.0, $this->carriedFor($mine, $type));
        $this->assertSame(20.0, (float) HrEmployeeLeaveBalance::where('employee_id', $theirs->id)
            ->value('available_balance'));

        // And no balance anywhere points across the boundary.
        $crossed = DB::table('hr_employee_leave_balances as b')
            ->join('hr_employees as e', 'e.id', '=', 'b.employee_id')
            ->whereColumn('b.tenant_id', '!=', 'e.tenant_id')
            ->count();
        $this->assertSame(0, $crossed);
    }

    /* ══ YEARLY ALLOCATION IS UNCHANGED ═══════════════════════════════ */

    public function test_the_yearly_allocation_still_comes_from_the_policy_mapping(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 10, 'yearly_limit' => 99]);
        $employee = $this->employee();

        $this->assign($employee, $this->policy($type, 14, 10));

        // assignPolicy allocates the POLICY's figure. This phase changed
        // carry-forward only and must not have touched that.
        $this->assertSame(14.0, (float) HrEmployeeLeaveBalance::where('employee_id', $employee->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->value('allocated'));
    }
}
