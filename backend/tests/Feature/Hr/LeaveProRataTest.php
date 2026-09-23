<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeavePolicyType;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Services\Hr\EmployeeLeaveBalanceService;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use App\Support\Hr\LeaveCarryForward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Leave in an employee's first year.
 *
 * Entitlement was front-loaded in full whatever the joining date, so somebody
 * starting on 1 December received a whole year's leave on their first day. That
 * is not merely generous — ExitSettlementService encashes available_balance at
 * per-day basic, so unearned days were paid out in cash on exit. The last test
 * in this file is the one that says so.
 *
 * WHAT THIS FILE DEFENDS, in rough order of how badly it would hurt to lose it:
 *
 *   OFF MEANS NOTHING MOVED. leave_prorate_first_year defaults to false, and
 *   with it false every figure is what it was before. Half the tests below
 *   exist to prove that a deployment which never touches the setting never
 *   notices this change.
 *
 *   THE FRACTION IS APPLIED ONCE. Proration reduces the CURRENT year's
 *   allocation. Carry-forward is computed from the prior balance by
 *   LeaveCarryForward, separately, and must not be prorated again — the
 *   two-year test walks a prorated first year into a full second one.
 *
 *   CONFIGURATION IS NEVER REWRITTEN. yearly_limit and yearly_allocation are
 *   read, not touched.
 */
class LeaveProRataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    /** A fixed leave year, so "this year" never drifts with the wall clock. */
    private const YEAR = 2026;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'pr-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'pr-b', 'status' => 'active']);

        // Every allocation below opens the 2026 leave year. Frozen rather than
        // relative, because a test that passes only in the current calendar
        // year is a test that fails silently next January.
        Carbon::setTestNow(Carbon::create(self::YEAR, 6, 15, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function prorate(bool $on, ?Tenant $t = null): void
    {
        app(SettingsService::class)->set(
            ($t ?: $this->a)->id,
            HrSetting::GROUP,
            'leave_prorate_first_year',
            $on
        );
    }

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

    private function employee(string $joiningDate, string $status = 'Active', ?Tenant $t = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($t ?: $this->a)->id, 'name' => 'E'.substr(uniqid(), -5),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => $joiningDate, 'status' => $status,
        ]);
    }

    private function policy(HrLeaveType $type, float $allocation, float $cfLimit = 0, ?Tenant $t = null): HrLeavePolicy
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

    private function assign(HrEmployee $e, HrLeavePolicy $p, ?string $effectiveFrom = null): void
    {
        app(EmployeeLeaveBalanceService::class)->assignPolicy([
            'employee_id' => $e->id, 'leave_policy_id' => $p->id,
            'effective_from' => $effectiveFrom ?: self::YEAR.'-06-15',
        ], $e->tenant_id);
    }

    /** Run the command for one tenant and return the allocated figure. */
    private function allocateViaCommand(HrEmployee $e, HrLeaveType $type): ?float
    {
        $this->artisan('hr:allocate-leave', ['--tenant' => $e->tenant_id, '--commit' => true])
            ->assertSuccessful();

        $b = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('leave_type_id', $type->id)->first();

        return $b ? (float) $b->allocated : null;
    }

    private function allocatedVia(string $path, HrEmployee $e, HrLeaveType $type, float $annual): ?float
    {
        if ($path === 'command') {
            $type->update(['yearly_limit' => $annual]);

            return $this->allocateViaCommand($e, $type);
        }

        $this->assign($e, $this->policy($type, $annual, 0, Tenant::find($e->tenant_id)));
        $b = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('leave_type_id', $type->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->first();

        return $b ? (float) $b->allocated : null;
    }

    /* ═══════════════ 1. THE SETTING ══════════════════════════════════ */

    /**
     * OFF is the default, and off means today's behaviour exactly.
     *
     * The single most important assertion in this file: an existing workspace
     * that never opens HR Settings sees no change whatever.
     *
     * @test
     */
    public function proration_is_off_by_default_and_a_december_joiner_still_gets_a_full_year(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee(self::YEAR.'-12-01');

        $this->assertSame(12.0, $this->allocateViaCommand($e, $type));
    }

    /** @test */
    public function turning_the_setting_on_enables_proration(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee(self::YEAR.'-12-01');

        $this->assertSame(1.0, $this->allocateViaCommand($e, $type));
    }

    /**
     * The setting belongs to one workspace.
     *
     * Tenant A prorates; tenant B, which has not touched the setting, does not.
     *
     * @test
     */
    public function the_setting_does_not_leak_between_tenants(): void
    {
        $this->prorate(true, $this->a);

        $typeA = $this->type(['yearly_limit' => 12], $this->a);
        $typeB = $this->type(['yearly_limit' => 12], $this->b);
        $eA = $this->employee(self::YEAR.'-07-01', 'Active', $this->a);
        $eB = $this->employee(self::YEAR.'-07-01', 'Active', $this->b);

        $this->assertSame(6.0, $this->allocateViaCommand($eA, $typeA), 'A prorates.');
        $this->assertSame(12.0, $this->allocateViaCommand($eB, $typeB), 'B is untouched.');
    }

    /* ═══════════════ 2. BOUNDARIES ══════════════════════════════════ */

    /**
     * Every joining date in the brief, at both entitlements, through both
     * allocation paths.
     *
     * Exact values — a range would let a rounding mutant through.
     *
     * @test
     */
    public function the_full_boundary_table_holds_on_both_allocation_paths(): void
    {
        $cases = [
            // [joining date, annual, expected]
            [self::YEAR.'-01-01', 12, 12.0],
            [self::YEAR.'-04-01', 12, 9.0],
            [self::YEAR.'-04-15', 12, 9.0],
            [self::YEAR.'-07-01', 12, 6.0],
            [self::YEAR.'-12-01', 12, 1.0],
            [self::YEAR.'-12-31', 12, 1.0],

            [self::YEAR.'-01-01', 15, 15.0],
            // 15 × 9/12 = 11.25 → down to the nearest half day → 11.0
            [self::YEAR.'-04-01', 15, 11.0],
            [self::YEAR.'-04-15', 15, 11.0],
            // 15 × 6/12 = 7.5 exactly — must NOT be floored to 7.0
            [self::YEAR.'-07-01', 15, 7.5],
            // 15 × 1/12 = 1.25 → 1.0
            [self::YEAR.'-12-01', 15, 1.0],
            [self::YEAR.'-12-31', 15, 1.0],
        ];

        foreach (['command', 'policy'] as $path) {
            foreach ($cases as [$joined, $annual, $expected]) {
                $this->prorate(true);
                $type = $this->type(['yearly_limit' => $annual]);
                $e    = $this->employee($joined);

                $this->assertSame(
                    $expected,
                    $this->allocatedVia($path, $e, $type, (float) $annual),
                    "{$path}: joined {$joined} on {$annual} days"
                );
            }
        }
    }

    /**
     * The mid-month cases, stated on their own.
     *
     * The joining month counts in full however late in it somebody starts, so
     * the 1st and the 15th of April are the same nine months. A rule that
     * counted the joining month only when somebody started in its first half
     * would change the 15th and is what this pins against.
     *
     * @test
     */
    public function the_joining_month_counts_in_full_whatever_day_it_is(): void
    {
        $this->prorate(true);

        foreach (['-04-01', '-04-15', '-04-30'] as $suffix) {
            $type = $this->type(['yearly_limit' => 12]);
            $e    = $this->employee(self::YEAR.$suffix);

            $this->assertSame(9.0, $this->allocateViaCommand($e, $type), "joined {$suffix}");
        }
    }

    /* ═══════════════ 3. YEAR LOGIC ══════════════════════════════════ */

    /** @test */
    public function somebody_who_joined_in_a_previous_year_gets_the_full_entitlement(): void
    {
        $this->prorate(true);

        foreach ([self::YEAR - 1 .'-12-31', self::YEAR - 3 .'-06-01'] as $joined) {
            $type = $this->type(['yearly_limit' => 12]);
            $e    = $this->employee($joined);

            $this->assertSame(12.0, $this->allocateViaCommand($e, $type), "joined {$joined}");
        }
    }

    /**
     * 31 December of last year against 1 January of this one.
     *
     * The two dates are a day apart and both give a full year, but for
     * different reasons — one is a prior-year joiner, the other is a
     * twelve-month current-year joiner. A `<` flipped to `<=` on the year
     * comparison would not show here, which is why the December cases above
     * carry the weight.
     *
     * @test
     */
    public function the_year_boundary_is_exact(): void
    {
        $this->prorate(true);

        $prior = $this->employee(self::YEAR - 1 .'-12-31');
        $first = $this->employee(self::YEAR.'-01-01');
        $t1 = $this->type(['yearly_limit' => 12]);

        $this->assertSame(12.0, $this->allocateViaCommand($prior, $t1));
        $this->assertSame(12.0, $this->allocateViaCommand($first, $t1));
    }

    /**
     * A hire dated into next year earns nothing in this one.
     *
     * Zero, not a negative figure — the month arithmetic would otherwise run
     * past twelve and produce one.
     *
     * @test
     */
    public function a_joining_date_in_a_later_year_allocates_nothing_rather_than_a_negative(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee(self::YEAR + 1 .'-03-01');

        $allocated = $this->allocateViaCommand($e, $type);

        $this->assertSame(0.0, $allocated);
        $this->assertGreaterThanOrEqual(0.0, $allocated);
    }

    /* ═══════════════ 4. CONFIGURATION IS NEVER REWRITTEN ═══════════ */

    /** @test */
    public function proration_does_not_touch_the_configured_yearly_limit(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 15]);
        $e    = $this->employee(self::YEAR.'-07-01');

        $this->assertSame(7.5, $this->allocateViaCommand($e, $type));
        $this->assertSame('15.0', (string) $type->fresh()->yearly_limit,
            'The leave type is configuration — the allocation is what varies.');
    }

    /** @test */
    public function proration_does_not_touch_the_policy_allocation_or_carry_forward_limits(): void
    {
        $this->prorate(true);

        $type   = $this->type(['yearly_limit' => 12, 'carry_forward' => true, 'max_carry_forward' => 5]);
        $policy = $this->policy($type, 12, 4);
        $e      = $this->employee(self::YEAR.'-07-01');

        $this->assign($e, $policy);

        $pt = HrLeavePolicyType::where('policy_id', $policy->id)->first();
        $this->assertSame('12.0', (string) $pt->yearly_allocation);
        $this->assertSame('4.0', (string) $pt->carry_forward_limit);
        $this->assertSame('5.0', (string) $type->fresh()->max_carry_forward);
    }

    /* ═══════════════ 5. CARRY-FORWARD IS NOT PRORATED ══════════════ */

    /**
     * A prorated first year, then a full second year with carry-forward.
     *
     * The fraction must be applied ONCE. Year one gives 6 of 12; nothing is
     * used; year two allocates the full 12 and carries the 6 forward capped at
     * 5. If carry-forward were prorated too, the 6 would arrive as 3.
     *
     * @test
     */
    public function a_prorated_year_carries_forward_without_being_prorated_again(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 12, 'carry_forward' => true, 'max_carry_forward' => 5]);
        $e    = $this->employee(self::YEAR.'-07-01');

        // Year one: joined in July → 6 days.
        $y1 = $this->policy($type, 12, 5);
        $this->assign($e, $y1, self::YEAR.'-07-01');

        $b1 = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->first();
        $this->assertSame('6.0', (string) $b1->allocated);
        $this->assertSame('6.0', (string) $b1->available_balance);

        // Year two: the same employee is no longer a current-year joiner.
        Carbon::setTestNow(Carbon::create(self::YEAR + 1, 1, 5, 12));
        $y2 = $this->policy($type, 12, 5);
        $this->assign($e, $y2, (self::YEAR + 1).'-01-01');

        $b2 = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->first();

        $this->assertSame('12.0', (string) $b2->allocated, 'Second year is full.');
        $this->assertSame('5.0', (string) $b2->carried_forward,
            'Six days carried, capped at five — and NOT prorated a second time.');
        $this->assertSame('17.0', (string) $b2->available_balance);
    }

    /**
     * A policy change WITHIN the joining year — where the fraction could
     * genuinely be applied twice.
     *
     * The two-year test above cannot catch that: by the second year the
     * employee is no longer a current-year joiner, so a resolver wrongly
     * applied to the carried-forward days would return them unchanged and the
     * bug would hide. Here the employee joins in July and moves policy in
     * September, so both the new allocation AND the carry-forward are computed
     * while proration is live.
     *
     * Carried forward must be the five days the ceilings allow, NOT five
     * reduced again to 2.5 by six months' proration.
     *
     * @test
     */
    public function moving_policy_inside_the_joining_year_does_not_prorate_the_carry_forward(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 12, 'carry_forward' => true, 'max_carry_forward' => 6]);
        $e    = $this->employee(self::YEAR.'-07-01');

        // July: first policy. Joined in July → 6 of 12.
        $this->assign($e, $this->policy($type, 12, 6), self::YEAR.'-07-01');
        $first = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->first();
        $this->assertSame('6.0', (string) $first->allocated);

        // September: moved to a policy whose carry-forward ceiling is 5.
        $this->assign($e, $this->policy($type, 12, 5), self::YEAR.'-09-01');

        $second = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->first();

        $this->assertSame('6.0', (string) $second->allocated,
            'Still a July joiner — the new allocation is prorated from the JOINING month.');
        $this->assertSame('5.0', (string) $second->carried_forward,
            'Six available, capped at five by the policy — and not prorated a second time.');
        $this->assertSame('11.0', (string) $second->available_balance);
    }

    /** The carry-forward maths itself is untouched by any of this. */
    public function test_leave_carry_forward_still_answers_exactly_as_before(): void
    {
        $type = $this->type(['carry_forward' => true, 'max_carry_forward' => 5]);
        $pt   = new HrLeavePolicyType(['carry_forward_limit' => 8]);

        $this->assertSame(5.0, LeaveCarryForward::cap($type, $pt), 'Lower ceiling binds.');
        $this->assertSame(4.0, LeaveCarryForward::forBalance($type, $pt, 4.0));
        $this->assertSame(0.0, LeaveCarryForward::forBalance($type, $pt, -3.0));
    }

    /* ═══════════════ 6. THE THIRD PATH IS NOT PRORATED ═════════════ */

    /**
     * An operator's manual top-up is taken at face value.
     *
     * The quantity IS the decision — a goodwill grant, a correction, leave
     * agreed at offer. Scaling it by the months left in the year would
     * silently overrule the person who typed it.
     *
     * @test
     */
    public function a_manual_allocation_is_never_prorated(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee(self::YEAR.'-12-01');

        app(EmployeeLeaveBalanceService::class)->allocate([
            'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'quantity' => 10, 'remarks' => 'Agreed at offer',
        ], $this->a->id);

        $b = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('leave_type_id', $type->id)->first();

        $this->assertSame('10.0', (string) $b->allocated,
            'A December joiner asked for 10 gets 10, not 10/12.');
    }

    /* ═══════════════ 7. BULK ASSIGNMENT ════════════════════════════ */

    /** @test */
    public function bulk_assignment_prorates_each_employee_by_their_own_joining_date(): void
    {
        $this->prorate(true);

        $type   = $this->type(['yearly_limit' => 12]);
        $policy = $this->policy($type, 12);

        $jan = $this->employee(self::YEAR.'-01-01');
        $jul = $this->employee(self::YEAR.'-07-01');
        $old = $this->employee(self::YEAR - 2 .'-05-01');

        app(EmployeeLeaveBalanceService::class)->assignPolicyToMany([
            'leave_policy_id' => $policy->id, 'scope' => 'all',
            'effective_from'  => self::YEAR.'-06-15',
        ], $this->a->id);

        foreach ([[$jan, '12.0'], [$jul, '6.0'], [$old, '12.0']] as [$emp, $expected]) {
            $b = HrEmployeeLeaveBalance::where('employee_id', $emp->id)
                ->where('status', HrEmployeeLeaveBalance::ACTIVE)->first();
            $this->assertSame($expected, (string) $b->allocated, "employee {$emp->id}");
        }
    }

    /* ═══════════════ 8. INACTIVE / EXITED EMPLOYEES ════════════════ */

    /** @test */
    public function the_command_allocates_to_an_active_employee(): void
    {
        $type = $this->type();
        $e    = $this->employee(self::YEAR - 1 .'-01-01', 'Active');

        $this->assertSame(12.0, $this->allocateViaCommand($e, $type));
    }

    /**
     * An exited employee picks up nothing new.
     *
     * The filter was missing altogether, so every run handed a leaver another
     * year's entitlement — and F&F encashes available_balance, so those days
     * had a price.
     *
     * @test
     */
    public function the_command_skips_an_inactive_employee(): void
    {
        $type = $this->type();
        $e    = $this->employee(self::YEAR - 1 .'-01-01', 'Inactive');

        $this->assertNull($this->allocateViaCommand($e, $type),
            'No balance should have been created at all.');
        $this->assertSame(0, HrEmployeeLeaveBalance::where('employee_id', $e->id)->count());
    }

    /**
     * Somebody on leave is still employed and still earns their year.
     *
     * 'On Leave' sits between Active and Inactive in the employees enum, and
     * excluding it would deny a year's entitlement to anybody on maternity or
     * long-term sick leave when the command happened to run.
     *
     * @test
     */
    public function the_command_still_allocates_to_an_employee_on_leave(): void
    {
        $type = $this->type();
        $e    = $this->employee(self::YEAR - 1 .'-01-01', 'On Leave');

        $this->assertSame(12.0, $this->allocateViaCommand($e, $type));
    }

    /** An inactive employee's existing balance is left exactly as it was. */
    public function test_an_inactive_employees_existing_balance_is_untouched(): void
    {
        $type = $this->type();
        $e    = $this->employee(self::YEAR - 1 .'-01-01', 'Active');

        $this->allocateViaCommand($e, $type);
        $before = HrEmployeeLeaveBalance::where('employee_id', $e->id)->first();
        $before->update(['used' => 3]);
        $before->refresh();

        $e->update(['status' => 'Inactive']);
        $this->allocateViaCommand($e, $type);

        $after = HrEmployeeLeaveBalance::where('employee_id', $e->id)->get();
        $this->assertCount(1, $after, 'No second balance appeared.');
        $this->assertEquals($before->allocated, $after->first()->allocated);
        $this->assertSame('3.0', (string) $after->first()->used, 'Used days are untouched.');
    }

    /* ═══════════════ 9. NON-REGRESSION ═════════════════════════════ */

    /** @test */
    public function re_running_the_command_remains_idempotent(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee(self::YEAR.'-07-01');

        $this->assertSame(6.0, $this->allocateViaCommand($e, $type));
        $this->assertSame(6.0, $this->allocateViaCommand($e, $type));

        $this->assertSame(1, HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('leave_type_id', $type->id)->count());
    }

    /**
     * Turning the setting on later does not rewrite what is already allocated.
     *
     * Existing balances are history. Retro-correcting an entitlement people
     * have already booked leave against would be the one genuinely destructive
     * thing this change could do.
     *
     * @test
     */
    public function enabling_proration_does_not_recalculate_existing_balances(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee(self::YEAR.'-07-01');

        $this->assertSame(12.0, $this->allocateViaCommand($e, $type), 'Allocated in full while off.');

        $this->prorate(true);
        $this->allocateViaCommand($e, $type);

        $b = HrEmployeeLeaveBalance::where('employee_id', $e->id)->first();
        $this->assertSame('12.0', (string) $b->allocated, 'The existing figure stands.');
    }

    /** @test */
    public function with_proration_off_the_policy_path_is_unchanged_for_a_mid_year_joiner(): void
    {
        $type   = $this->type(['yearly_limit' => 12]);
        $policy = $this->policy($type, 12);
        $e      = $this->employee(self::YEAR.'-07-01');

        $this->assign($e, $policy);

        $b = HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)->first();
        $this->assertSame('12.0', (string) $b->allocated);
    }

    /** A zero-entitlement type (Unpaid Leave) prorates to zero, not an error. */
    public function test_a_zero_entitlement_type_allocates_zero(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 0, 'category' => 'Unpaid', 'paid' => false]);
        $e    = $this->employee(self::YEAR.'-07-01');

        $this->assertSame(0.0, $this->allocateViaCommand($e, $type));
    }

    /* ═══════════════ 10. THE MONEY ═════════════════════════════════ */

    /**
     * The reason this is a defect and not a preference.
     *
     * ExitSettlementService encashes the SUM of active available_balance at
     * per-day basic. A December joiner leaving in their first year used to
     * carry a full year's unearned leave into that sum. This asserts the
     * balance F&F would read, without touching the settlement calculation
     * itself.
     *
     * @test
     */
    public function the_balance_a_settlement_would_encash_is_the_prorated_one(): void
    {
        $this->prorate(true);

        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee(self::YEAR.'-12-01');
        $this->allocateViaCommand($e, $type);

        // Exactly the query ExitSettlementService runs.
        $encashable = (float) HrEmployeeLeaveBalance::where('tenant_id', $this->a->id)
            ->where('employee_id', $e->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)
            ->sum('available_balance');

        $this->assertSame(1.0, $encashable,
            'A December joiner may encash one day, not twelve.');
    }
}
