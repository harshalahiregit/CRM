<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrLeaveBalanceTransaction;
use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeavePolicyType;
use App\Models\Hr\HrLeaveType;
use App\Models\Tenant;
use App\Services\Hr\EmployeeLeaveBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A leave balance must be worth what it says it is worth.
 *
 * hr:allocate-leave wrote the year's entitlement into BOTH `allocated` and
 * `opening_balance`, then wrote `available_balance` explicitly — which hid the
 * problem until something recomputed. recomputeAvailable() sums
 * opening + allocated + adjusted + carried_forward − used, so the first
 * approved day of leave turned a 12-day balance into 23. F&F encashes
 * available_balance at per-day basic, so the invented days had a price.
 *
 * The first block below is the regression: allocate, use, and check the number.
 * It would have failed before the fix and is the test that should have existed.
 *
 * The second block is the repair command, where the risk now lives. It writes
 * to live balances, so most of these tests are about what it must NOT touch:
 * rows the service created, genuine opening balances brought in from another
 * system, other tenants, and anybody whose corrected balance would go negative.
 */
class LeaveBalanceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;
    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Tenant::create(['name' => 'Alpha', 'slug' => 'lbi-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Beta', 'slug' => 'lbi-b', 'status' => 'active']);
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
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ]);
    }

    private function allocate(?Tenant $t = null): void
    {
        $this->artisan('hr:allocate-leave', ['--tenant' => ($t ?: $this->a)->id, '--commit' => true])
            ->assertSuccessful();
    }

    private function balanceOf(HrEmployee $e, HrLeaveType $type): ?HrEmployeeLeaveBalance
    {
        return HrEmployeeLeaveBalance::where('employee_id', $e->id)
            ->where('leave_type_id', $type->id)->first();
    }

    /** The exact shape hr:allocate-leave used to write, for repair tests. */
    private function brokenRow(HrEmployee $e, HrLeaveType $type, float $days = 12, float $used = 0): HrEmployeeLeaveBalance
    {
        return HrEmployeeLeaveBalance::create([
            'tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'allocated' => $days, 'opening_balance' => $days, 'used' => $used,
            'adjusted' => 0, 'carried_forward' => 0, 'available_balance' => $days,
            'effective_from' => '2026-01-01', 'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);
    }

    private function repair(array $opts = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('hr:repair-leave-opening-balances', $opts);
    }

    /* ═══════════ 1. THE REGRESSION ═══════════════════════════════════ */

    /**
     * The test that should have existed.
     *
     * Before the fix this read 23.0 — a single day's leave ADDED eleven days
     * to the balance.
     *
     * @test
     */
    public function using_leave_reduces_the_balance_instead_of_doubling_it(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee();
        $this->allocate();

        app(EmployeeLeaveBalanceService::class)->recordUsage($e->id, $type->id, 1, 'One day', $this->a->id);

        $b = $this->balanceOf($e, $type);
        $this->assertSame('11.0', (string) $b->available_balance);
        $this->assertSame('0.0', (string) $b->opening_balance, 'Nothing is opening here.');
        $this->assertSame('12.0', (string) $b->allocated, 'The entitlement itself is unchanged.');
    }

    /** @test */
    public function a_freshly_allocated_balance_already_agrees_with_its_own_formula(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee();
        $this->allocate();

        $b = $this->balanceOf($e, $type);
        $stored = (float) $b->available_balance;
        $b->recomputeAvailable();

        $this->assertSame($stored, (float) $b->available_balance,
            'A row must not change value merely by being recomputed.');
    }

    /** @test */
    public function adjustments_stay_correct_on_an_allocated_balance(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee();
        $this->allocate();

        app(EmployeeLeaveBalanceService::class)->adjust([
            'balance_id' => $this->balanceOf($e, $type)->id,
            'quantity' => 2, 'remarks' => 'Goodwill',
        ], $this->a->id);

        $this->assertSame('14.0', (string) $this->balanceOf($e, $type)->available_balance);
    }

    /** @test */
    public function a_manual_allocation_on_top_stays_correct(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee();
        $this->allocate();

        app(EmployeeLeaveBalanceService::class)->allocate([
            'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'quantity' => 3, 'remarks' => 'Agreed at offer',
        ], $this->a->id);

        $this->assertSame('15.0', (string) $this->balanceOf($e, $type)->available_balance);
    }

    /** The service path was always correct and must stay that way. */
    public function test_assign_policy_balances_remain_correct(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee();

        $policy = HrLeavePolicy::create([
            'tenant_id' => $this->a->id, 'name' => 'P'.substr(uniqid(), -5), 'applies_to' => 'All',
            'weekends_count' => false, 'holidays_count' => false, 'half_day_allowed' => true,
            'negative_balance_allowed' => false, 'is_active' => true,
        ]);
        HrLeavePolicyType::create([
            'policy_id' => $policy->id, 'leave_type_id' => $type->id,
            'yearly_allocation' => 12, 'carry_forward_limit' => 0,
        ]);

        app(EmployeeLeaveBalanceService::class)->assignPolicy([
            'employee_id' => $e->id, 'leave_policy_id' => $policy->id,
        ], $this->a->id);

        app(EmployeeLeaveBalanceService::class)->recordUsage($e->id, $type->id, 2, null, $this->a->id);

        $this->assertSame('10.0', (string) $this->balanceOf($e, $type)->available_balance);
    }

    /**
     * What F&F would encash. The same query ExitSettlementService runs.
     *
     * @test
     */
    public function the_settlement_balance_is_the_real_remaining_leave(): void
    {
        $type = $this->type(['yearly_limit' => 12]);
        $e    = $this->employee();
        $this->allocate();
        app(EmployeeLeaveBalanceService::class)->recordUsage($e->id, $type->id, 5, null, $this->a->id);

        $encashable = (float) HrEmployeeLeaveBalance::where('tenant_id', $this->a->id)
            ->where('employee_id', $e->id)
            ->where('status', HrEmployeeLeaveBalance::ACTIVE)
            ->sum('available_balance');

        $this->assertSame(7.0, $encashable, 'Twelve allocated, five used, seven encashable.');
    }

    /* ═══════════ 2. THE REPAIR COMMAND ══════════════════════════════ */

    /** @test */
    public function a_dry_run_changes_nothing(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        $row  = $this->brokenRow($e, $type, 12, 1);

        $this->repair()->assertSuccessful();

        $row->refresh();
        $this->assertSame('12.0', (string) $row->opening_balance, 'Untouched by a dry run.');
        $this->assertSame(0, HrLeaveBalanceTransaction::count());
    }

    /** @test */
    public function applying_the_repair_clears_the_duplicate_and_corrects_the_balance(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        // As the bug left it: 12 + 12 − 1 = 23.
        $row = $this->brokenRow($e, $type, 12, 1);
        $row->update(['available_balance' => 23]);

        $this->repair(['--commit' => true])->assertSuccessful();

        $row->refresh();
        $this->assertSame('0.0', (string) $row->opening_balance);
        $this->assertSame('11.0', (string) $row->available_balance);
        $this->assertSame('12.0', (string) $row->allocated, 'Entitlement preserved.');
        $this->assertSame('1.0', (string) $row->used, 'Usage preserved.');
    }

    /** @test */
    public function the_repair_is_idempotent(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        $row  = $this->brokenRow($e, $type, 12, 1);

        $this->repair(['--commit' => true])->assertSuccessful();
        $after = $row->fresh()->toArray();
        $txns  = HrLeaveBalanceTransaction::count();

        $this->repair(['--commit' => true])->assertSuccessful();

        $this->assertSame($after['available_balance'], $row->fresh()->available_balance);
        $this->assertSame($txns, HrLeaveBalanceTransaction::count(), 'No second correction row.');
    }

    /**
     * A row the service created is off limits, however its numbers look.
     *
     * The Allocation transaction is the load-bearing signal — this row is
     * deliberately given the same opening_balance == allocated shape as the
     * bug, so only the ledger can tell them apart.
     *
     * @test
     */
    public function the_repair_never_touches_a_balance_carrying_an_allocation_transaction(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        $row  = $this->brokenRow($e, $type, 12, 0);

        HrLeaveBalanceTransaction::create([
            'tenant_id' => $this->a->id, 'employee_leave_balance_id' => $row->id,
            'transaction_type' => 'Allocation', 'quantity' => 12, 'remarks' => 'Policy allocation',
        ]);

        $this->repair(['--commit' => true])->assertSuccessful();

        $this->assertSame('12.0', (string) $row->fresh()->opening_balance,
            'A service-created balance is not this command\'s business.');
    }

    /**
     * A genuine opening balance — carried in from another payroll system — is
     * not a duplicated allocation and must survive.
     *
     * @test
     */
    public function the_repair_never_touches_a_real_opening_balance(): void
    {
        $type = $this->type();
        $e    = $this->employee();

        // Opening differs from allocated: a real migrated-in balance.
        $row = HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->a->id, 'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'allocated' => 12, 'opening_balance' => 4, 'used' => 0, 'adjusted' => 0,
            'carried_forward' => 0, 'available_balance' => 16,
            'effective_from' => '2026-01-01', 'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);

        $this->repair(['--commit' => true])->assertSuccessful();

        $row->refresh();
        $this->assertSame('4.0', (string) $row->opening_balance);
        $this->assertSame('16.0', (string) $row->available_balance);
    }

    /**
     * Over-consumed rows are reported and left alone — the approved decision.
     *
     * Twelve allocated, fifteen used against the inflated balance: correcting
     * would write minus three.
     *
     * @test
     */
    public function a_balance_that_would_go_negative_is_skipped_and_reported(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        $row  = $this->brokenRow($e, $type, 12, 15);
        $row->update(['available_balance' => 9]);

        $this->repair(['--commit' => true])
            ->expectsOutputToContain($e->employee_code)
            ->assertSuccessful();

        $row->refresh();
        $this->assertSame('12.0', (string) $row->opening_balance, 'Left exactly as it was.');
        $this->assertSame('9.0', (string) $row->available_balance);
        $this->assertSame('15.0', (string) $row->used);
        $this->assertSame(0, HrLeaveBalanceTransaction::count(), 'No correction was written.');
    }

    /** A skipped row does not block the repairable ones beside it. */
    public function test_a_skipped_row_does_not_stop_the_others(): void
    {
        $type  = $this->type();
        $ok    = $this->employee();
        $overC = $this->employee();

        $good = $this->brokenRow($ok, $type, 12, 1);
        $bad  = $this->brokenRow($overC, $type, 12, 15);

        $this->repair(['--commit' => true])->assertSuccessful();

        $this->assertSame('0.0', (string) $good->fresh()->opening_balance);
        $this->assertSame('12.0', (string) $bad->fresh()->opening_balance);
    }

    /**
     * A balance corrected to exactly zero IS repaired.
     *
     * Twelve allocated, twelve used — nothing left, but nothing wrong either.
     * The skip rule is "would go negative", not "would not be positive", and a
     * guard written `<= 0` would abandon everybody who has used their whole
     * entitlement, leaving those rows inflated forever.
     *
     * @test
     */
    public function a_balance_correcting_to_exactly_zero_is_repaired_not_skipped(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        $row  = $this->brokenRow($e, $type, 12, 12);
        $row->update(['available_balance' => 12]);

        $this->repair(['--commit' => true])->assertSuccessful();

        $row->refresh();
        $this->assertSame('0.0', (string) $row->opening_balance);
        $this->assertSame('0.0', (string) $row->available_balance);
    }

    /**
     * Carried-forward days survive the correction.
     *
     * Every other fixture here has none, so a correction that simply forgot
     * the column would look right everywhere else.
     *
     * @test
     */
    public function carried_forward_days_are_kept_by_the_correction(): void
    {
        $type = $this->type();
        $e    = $this->employee();

        // The bug's shape, plus five days carried in: 12 + 12 + 5 − 2 = 27.
        $row = HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->a->id, 'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'allocated' => 12, 'opening_balance' => 12, 'used' => 2, 'adjusted' => 0,
            'carried_forward' => 5, 'available_balance' => 27,
            'effective_from' => '2026-01-01', 'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);

        $this->repair(['--commit' => true])->assertSuccessful();

        $row->refresh();
        $this->assertSame('5.0', (string) $row->carried_forward, 'Carry-forward is untouched.');
        $this->assertSame('15.0', (string) $row->available_balance, '12 allocated + 5 carried − 2 used.');
    }

    /**
     * A zero-entitlement balance is left alone.
     *
     * Unpaid Leave allocates 0, so opening and allocated are both 0 and the
     * equality test alone would match it. Nothing about such a row is wrong,
     * and a repair that touched it would write a pointless Correction into a
     * ledger meant to record real events.
     *
     * @test
     */
    public function a_zero_entitlement_balance_is_not_a_repair_candidate(): void
    {
        $type = $this->type(['yearly_limit' => 0, 'category' => 'Unpaid', 'paid' => false]);
        $e    = $this->employee();

        $row = HrEmployeeLeaveBalance::create([
            'tenant_id' => $this->a->id, 'employee_id' => $e->id, 'leave_type_id' => $type->id,
            'allocated' => 0, 'opening_balance' => 0, 'used' => 0, 'adjusted' => 0,
            'carried_forward' => 0, 'available_balance' => 0,
            'effective_from' => '2026-01-01', 'status' => HrEmployeeLeaveBalance::ACTIVE,
        ]);

        $this->repair(['--commit' => true])->assertSuccessful();

        $this->assertSame(0, HrLeaveBalanceTransaction::where('employee_leave_balance_id', $row->id)->count(),
            'Nothing was wrong, so nothing should have been recorded.');
    }

    /** @test */
    public function the_repair_is_tenant_scoped(): void
    {
        $typeA = $this->type([], $this->a);
        $typeB = $this->type([], $this->b);
        $rowA  = $this->brokenRow($this->employee($this->a), $typeA, 12, 1);
        $rowB  = $this->brokenRow($this->employee($this->b), $typeB, 12, 1);

        $this->repair(['--tenant' => $this->a->id, '--commit' => true])->assertSuccessful();

        $this->assertSame('0.0', (string) $rowA->fresh()->opening_balance);
        $this->assertSame('12.0', (string) $rowB->fresh()->opening_balance, 'Other tenant untouched.');
    }

    /** @test */
    public function the_repair_preserves_the_transaction_history(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        $row  = $this->brokenRow($e, $type, 12, 1);

        HrLeaveBalanceTransaction::create([
            'tenant_id' => $this->a->id, 'employee_leave_balance_id' => $row->id,
            'transaction_type' => 'Leave Deduction', 'quantity' => -1, 'remarks' => 'One day',
        ]);

        $this->repair(['--commit' => true])->assertSuccessful();

        $types = HrLeaveBalanceTransaction::where('employee_leave_balance_id', $row->id)
            ->pluck('transaction_type')->all();

        $this->assertContains('Leave Deduction', $types, 'History is append-only.');
        $this->assertContains('Correction', $types, 'And the repair says it happened.');
    }

    /** A repaired balance then behaves correctly when leave is taken. */
    public function test_a_repaired_balance_deducts_correctly_afterwards(): void
    {
        $type = $this->type();
        $e    = $this->employee();
        $row  = $this->brokenRow($e, $type, 12, 0);

        $this->repair(['--commit' => true])->assertSuccessful();
        app(EmployeeLeaveBalanceService::class)->recordUsage($e->id, $type->id, 2, null, $this->a->id);

        $this->assertSame('10.0', (string) $row->fresh()->available_balance);
    }

    /** @test */
    public function the_dry_run_reports_counts_without_writing(): void
    {
        $type = $this->type();
        $this->brokenRow($this->employee(), $type, 12, 1);
        $this->brokenRow($this->employee(), $type, 12, 15);

        $this->repair()
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, HrLeaveBalanceTransaction::count());
        $this->assertSame(2, HrEmployeeLeaveBalance::where('opening_balance', '>', 0)->count());
    }
}
