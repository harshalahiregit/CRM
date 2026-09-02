<?php

namespace Tests\Feature\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrAdvanceSettlement;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\AdvanceService;
use App\Services\Hr\RequestThreadService;
use App\Support\Hr\AdvanceStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The advance ladder: manager, then accounts, then director — each in turn.
 *
 * The ORDER is the point. SangoeTrack has the same three stages but approves
 * without choosing one, so any approver could finish a request from any rung.
 * Most of these tests exist to make that impossible here.
 */
class AdvanceLadderTest extends TestCase
{
    use RefreshDatabase;

    private AdvanceService $svc;
    private RequestThreadService $thread;
    private ?Tenant $t = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(AdvanceService::class);
        $this->thread = app(RequestThreadService::class);
    }

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'adv-t', 'status' => 'active']);
    }

    private function user(string $email, string $role = 'staff', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => explode('@', $email)[0], 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
            'internal_role' => $internal,
        ]);
    }

    private function employee(string $code, ?User $user = null, ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => $code,
            'name' => 'E' . $code, 'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->toDateString(), 'status' => 'Active',
            'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    /** A requester with a real reporting manager, plus accounts and a director. */
    private function cast(): array
    {
        $managerUser = $this->user('manager@example.test');
        $manager     = $this->employee('SNE-M', $managerUser);

        $staffUser = $this->user('priya@example.test');
        $staff     = $this->employee('SNE-1', $staffUser, $manager->id);

        return [
            $staff, $staffUser,
            $managerUser,
            $this->user('accounts@example.test', 'staff', 'accounts'),
            $this->user('director@example.test', 'staff', 'director'),
        ];
    }

    private function request(HrEmployee $employee, User $actor, float $amount = 20000): HrAdvance
    {
        return $this->svc->request($employee, [
            'purpose' => 'Site visit to Pune', 'amount_requested' => $amount,
            'advance_type' => 'travel', 'required_date' => now()->addDays(3)->toDateString(),
        ], $actor);
    }

    /* ── the ladder, in order ────────────────────────────────────────── */

    public function test_it_climbs_manager_then_accounts_then_director(): void
    {
        [$staff, $staffUser, $manager, $accounts, $director] = $this->cast();
        $advance = $this->request($staff, $staffUser);

        $this->assertSame(AdvanceStage::PENDING, $advance->status);

        $advance = $this->svc->approve($advance, $manager);
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->status);

        $advance = $this->svc->approve($advance, $accounts);
        $this->assertSame(AdvanceStage::ACCOUNTS_APPROVED, $advance->status);

        $advance = $this->svc->approve($advance, $director);
        $this->assertSame(AdvanceStage::APPROVED, $advance->status, 'The top of the ladder is ready-to-disburse.');
        $this->assertNotNull($advance->decided_at);
    }

    public function test_accounts_cannot_approve_before_the_manager_has(): void
    {
        [$staff, $staffUser, , $accounts] = $this->cast();
        $advance = $this->request($staff, $staffUser);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('waiting on the employee\'s own reporting manager');

        $this->svc->approve($advance, $accounts);
    }

    public function test_a_director_cannot_skip_to_the_top(): void
    {
        [$staff, $staffUser, , , $director] = $this->cast();
        $advance = $this->request($staff, $staffUser);

        $this->expectException(BusinessException::class);
        $this->svc->approve($advance, $director);
    }

    /** Any manager is not the same as YOUR manager. */
    public function test_somebody_elses_manager_cannot_approve_the_first_rung(): void
    {
        [$staff, $staffUser] = $this->cast();
        $strangerUser = $this->user('stranger@example.test');
        $this->employee('SNE-X', $strangerUser);

        $advance = $this->request($staff, $staffUser);

        $this->expectException(BusinessException::class);
        $this->svc->approve($advance, $strangerUser);
    }

    /* ── the two personal rules ──────────────────────────────────────── */

    public function test_nobody_approves_their_own_advance_even_an_admin(): void
    {
        $adminUser = $this->user('boss@example.test', 'admin');
        $admin     = $this->employee('SNE-A', $adminUser);
        $advance   = $this->request($admin, $adminUser);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot approve your own advance');

        $this->svc->approve($advance, $adminUser);
    }

    /**
     * Without this an admin walks a request up all three rungs alone and the
     * ladder means nothing.
     */
    public function test_one_person_cannot_approve_at_two_rungs(): void
    {
        [$staff, $staffUser] = $this->cast();
        $adminUser = $this->user('admin@example.test', 'admin');

        $advance = $this->request($staff, $staffUser);
        $advance = $this->svc->approve($advance, $adminUser);   // stood in for the manager
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->status);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already approved this advance at an earlier stage');

        $this->svc->approve($advance, $adminUser);
    }

    /* ── holds pause the ladder, they do not unwind it ───────────────── */

    public function test_a_hold_by_accounts_returns_to_accounts_not_to_the_start(): void
    {
        [$staff, $staffUser, $manager, $accounts] = $this->cast();

        $advance = $this->svc->approve($this->request($staff, $staffUser), $manager);
        $advance = $this->svc->hold($advance, $accounts, 'Which cost centre is this against?');

        $this->assertSame(AdvanceStage::ON_HOLD, $advance->status);
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->held_from);
        $this->assertSame(AdvanceStage::ACCOUNTS, $advance->next_tier, 'A held request still shows whose desk it is on.');

        $advance = $this->svc->reply($advance, $staffUser, 'Cost centre OPS-2.');

        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->status,
            'The manager approval must survive a hold by accounts.');
    }

    public function test_accepting_a_proposed_amount_does_not_skip_the_remaining_tiers(): void
    {
        [$staff, $staffUser, $manager, $accounts, $director] = $this->cast();

        $advance = $this->svc->approve($this->request($staff, $staffUser, 20000), $manager);
        $advance = $this->svc->hold($advance, $accounts, 'We can only fund 15,000.', proposedAmount: 15000);

        $advance = $this->svc->acceptProposal($advance, $staffUser);

        $this->assertSame('15000.00', $advance->amount_approved);
        $this->assertSame(AdvanceStage::MANAGER_APPROVED, $advance->status,
            'Agreeing a figure with accounts is not the director\'s consent.');

        $advance = $this->svc->approve($advance, $accounts);
        $advance = $this->svc->approve($advance, $director);
        $this->assertSame(AdvanceStage::APPROVED, $advance->status);
    }

    public function test_changing_the_amount_without_a_reason_is_refused(): void
    {
        [$staff, $staffUser, $manager] = $this->cast();
        $advance = $this->request($staff, $staffUser, 20000);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->svc->approve($advance, $manager, 15000);
    }

    /* ── money out ───────────────────────────────────────────────────── */

    private function approved(): array
    {
        [$staff, $staffUser, $manager, $accounts, $director] = $this->cast();
        $advance = $this->svc->approve($this->request($staff, $staffUser, 20000), $manager);
        $advance = $this->svc->approve($advance, $accounts);
        $advance = $this->svc->approve($advance, $director);

        return [$advance, $staff, $staffUser, $accounts];
    }

    public function test_an_advance_cannot_be_disbursed_before_the_ladder_is_finished(): void
    {
        [$staff, $staffUser, $manager, $accounts] = $this->cast();
        $advance = $this->svc->approve($this->request($staff, $staffUser), $manager);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('every tier has approved');

        $this->svc->disburse($advance, $accounts, 'bank_transfer', 'UTR123');
    }

    public function test_a_transfer_without_a_reference_is_refused_but_cash_is_not(): void
    {
        [$advance, , , $accounts] = $this->approved();

        try {
            $this->svc->disburse($advance, $accounts, 'bank_transfer', '  ');
            $this->fail('A referenceless transfer must be refused.');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('needs a reference', $e->getMessage());
        }

        $done = $this->svc->disburse($advance->fresh(), $accounts, 'cash', null);
        $this->assertSame(AdvanceStage::DISBURSED, $done->status);
    }

    public function test_more_than_the_approved_amount_cannot_be_disbursed(): void
    {
        [$advance, , , $accounts] = $this->approved();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot disburse more than the approved amount');

        $this->svc->disburse($advance, $accounts, 'cash', null, 25000);
    }

    public function test_an_advance_cannot_be_disbursed_twice(): void
    {
        [$advance, , , $accounts] = $this->approved();
        $this->svc->disburse($advance, $accounts, 'cash', null);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already been disbursed');

        $this->svc->disburse($advance->fresh(), $accounts, 'cash', null);
    }

    /* ── bills back ──────────────────────────────────────────────────── */

    public function test_spending_less_leaves_a_balance_to_return(): void
    {
        [$advance, , $staffUser, $accounts] = $this->approved();
        $advance = $this->svc->disburse($advance, $accounts, 'cash', null);

        $s = $this->svc->submitSettlement($advance, $staffUser, ['actual_expense' => 14000]);

        $this->assertSame('6000.00', $s->balance_return);
        $this->assertSame('0.00', $s->extra_due);
        $this->assertStringContainsString('balance comes back', $s->case_label);
        $this->assertSame(AdvanceStage::SETTLEMENT_SUBMITTED, $advance->fresh()->status);
    }

    public function test_spending_more_leaves_the_company_owing(): void
    {
        [$advance, , $staffUser, $accounts] = $this->approved();
        $advance = $this->svc->disburse($advance, $accounts, 'cash', null);

        $s = $this->svc->submitSettlement($advance, $staffUser, ['actual_expense' => 23000]);

        $this->assertSame('3000.00', $s->extra_due);
        $this->assertSame('0.00', $s->balance_return);
        $this->assertStringContainsString('company owes', $s->case_label);
    }

    /**
     * The SangoeTrack bug that must not reappear: their re-submission deletes the
     * previous settlement, destroying the only record of what was first claimed.
     */
    public function test_a_rejected_settlement_is_kept_when_another_is_submitted(): void
    {
        [$advance, , $staffUser, $accounts] = $this->approved();
        $advance = $this->svc->disburse($advance, $accounts, 'cash', null);

        $first = $this->svc->submitSettlement($advance, $staffUser, ['actual_expense' => 19000]);
        $this->svc->rejectSettlement($first, $accounts, 'The hotel bill is missing.');

        $this->assertSame(AdvanceStage::DISBURSED, $advance->fresh()->status, 'A rejection reopens settlement.');

        $second = $this->svc->submitSettlement($advance->fresh(), $staffUser, ['actual_expense' => 14000]);
        $this->svc->acceptSettlement($second, $accounts);

        $this->assertSame(AdvanceStage::SETTLED, $advance->fresh()->status);

        $kept = HrAdvanceSettlement::where('advance_id', $advance->id)->orderBy('id')->get();
        $this->assertCount(2, $kept, 'Both attempts must survive.');
        $this->assertSame(HrAdvanceSettlement::REJECTED, $kept[0]->status);
        $this->assertSame('19000.00', $kept[0]->actual_expense, 'What was FIRST claimed must still be readable.');
    }

    public function test_a_settlement_cannot_be_reviewed_twice(): void
    {
        [$advance, , $staffUser, $accounts] = $this->approved();
        $advance = $this->svc->disburse($advance, $accounts, 'cash', null);
        $s = $this->svc->submitSettlement($advance, $staffUser, ['actual_expense' => 14000]);
        $this->svc->acceptSettlement($s, $accounts);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already been reviewed');

        $this->svc->rejectSettlement($s->fresh(), $accounts, 'Changed my mind.');
    }

    /* ── the ledger the old screen could not show ────────────────────── */

    public function test_outstanding_shows_what_an_employee_is_carrying(): void
    {
        [$advance, $staff, $staffUser, $accounts] = $this->approved();
        $this->svc->disburse($advance, $accounts, 'cash', null);

        $out = $this->svc->outstandingFor($staff);

        $this->assertSame(1, $out['open_count']);
        $this->assertSame(20000.0, $out['outstanding_amount']);
    }

    /* ── the whole story, on the thread, in order ────────────────────── */

    public function test_the_whole_lifecycle_is_recorded_in_order(): void
    {
        [$staff, $staffUser, $manager, $accounts, $director] = $this->cast();

        $advance = $this->request($staff, $staffUser, 20000);
        $advance = $this->svc->approve($advance, $manager);
        $advance = $this->svc->hold($advance, $accounts, 'Which cost centre?');
        $advance = $this->svc->reply($advance, $staffUser, 'OPS-2.');
        $advance = $this->svc->approve($advance, $accounts);
        $advance = $this->svc->approve($advance, $director);
        $advance = $this->svc->disburse($advance, $accounts, 'upi', 'UPI-99');
        $s = $this->svc->submitSettlement($advance, $staffUser, ['actual_expense' => 14000]);
        $this->svc->acceptSettlement($s, $accounts);

        $events = $this->thread->forSubject($advance->fresh(), asEmployee: true)
            ->pluck('event_type')->filter()->values()->all();

        $this->assertSame([
            'submitted', 'tier_approved', 'held', 'hold_cleared',
            'tier_approved', 'tier_approved', 'disbursed',
            'settlement_submitted', 'settled',
        ], $events);
    }
}
