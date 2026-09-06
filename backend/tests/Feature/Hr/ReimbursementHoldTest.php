<?php

namespace Tests\Feature\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrReimbursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\ReimbursementService;
use App\Services\Hr\RequestThreadService;
use App\Support\Hr\ReimbursementStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Expense claims, hold, and the back-and-forth.
 *
 * The scenario these are built around: somebody claims ₹5,000 and the receipt
 * says ₹2,500. Today that can only be approved wrongly or rejected outright.
 * With a hold the admin says what is wrong, and the employee either sends the
 * right receipt or accepts the lower figure — as many times as it takes.
 */
class ReimbursementHoldTest extends TestCase
{
    use RefreshDatabase;

    private ReimbursementService $svc;
    private RequestThreadService $thread;
    private ?Tenant $t = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(ReimbursementService::class);
        $this->thread = app(RequestThreadService::class);
    }

    private function tenant(): Tenant
    {
        return $this->t ??= Tenant::create(['name' => 'T', 'slug' => 'rb-t', 'status' => 'active']);
    }

    private function employee(): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant()->id, 'employee_code' => 'SNE-1',
            'name' => 'Priya', 'department' => 'Ops', 'designation' => 'Analyst',
            'joining_date' => now()->toDateString(), 'status' => 'Active',
        ]);
    }

    private function user(string $email, string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id, 'name' => ucfirst($role), 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $role, 'status' => 'active',
        ]);
    }

    private function claim(float $amount = 5000): HrReimbursement
    {
        return $this->svc->submit($this->employee(), [
            'title' => 'Client dinner', 'expense_date' => now()->toDateString(),
            'amount_claimed' => $amount,
        ], $this->user('priya@example.test', 'staff'));
    }

    /* ── the scenario ────────────────────────────────────────────────── */

    public function test_the_employee_sends_a_corrected_receipt_and_it_returns_to_the_queue(): void
    {
        $admin = $this->user('admin@example.test');
        $staff = $this->user('staff@example.test', 'staff');
        $claim = $this->claim(5000);

        $this->svc->hold($claim, $admin, 'The receipt attached is for 2,500, not 5,000.');
        $this->assertSame(ReimbursementStatus::ON_HOLD, $claim->fresh()->status);

        $claim = $this->svc->reply($claim->fresh(), $staff, 'Sorry, wrong file — the right receipt is attached.');

        $this->assertSame(ReimbursementStatus::PENDING, $claim->status, 'A reply returns the claim to the queue.');
        $this->assertNull($claim->held_from);
    }

    public function test_the_employee_accepts_the_proposed_amount_instead(): void
    {
        $admin = $this->user('admin@example.test');
        $staff = $this->user('staff@example.test', 'staff');
        $claim = $this->claim(5000);

        $this->svc->hold($claim, $admin, 'The receipt supports 2,500.', proposedAmount: 2500);
        $this->assertTrue($claim->fresh()->can_accept_proposal);

        $claim = $this->svc->acceptProposal($claim->fresh(), $staff);

        $this->assertSame(ReimbursementStatus::APPROVED, $claim->status);
        $this->assertSame('2500.00', $claim->amount_approved);
        $this->assertNull($claim->proposed_amount);
    }

    /** The repetition is the point — one exchange is a rejection with extra steps. */
    public function test_it_can_go_round_more_than_once(): void
    {
        $admin = $this->user('admin@example.test');
        $staff = $this->user('staff@example.test', 'staff');
        $claim = $this->claim(5000);

        $this->svc->hold($claim, $admin, 'Receipt is for the wrong amount.');
        $claim = $this->svc->reply($claim->fresh(), $staff, 'Attached the right one.');

        $this->svc->hold($claim->fresh(), $admin, 'That receipt is dated last quarter.');
        $claim = $this->svc->reply($claim->fresh(), $staff, 'Here is the current one.');

        $this->svc->hold($claim->fresh(), $admin, 'Still unreadable, sorry.');
        $claim = $this->svc->reply($claim->fresh(), $staff, 'Photographed again in better light.');

        $this->assertSame(ReimbursementStatus::PENDING, $claim->status);

        // And every turn is still there to read.
        $held = $this->thread->forSubject($claim, asEmployee: true)->where('event_type', 'held');
        $this->assertCount(3, $held, 'The hold history must survive every round.');
    }

    public function test_the_hold_history_is_visible_to_whoever_picks_it_up(): void
    {
        $first  = $this->user('first@example.test');
        $second = $this->user('second@example.test');
        $staff  = $this->user('staff@example.test', 'staff');
        $claim  = $this->claim(5000);

        $this->svc->hold($claim, $first, 'Receipt does not match.');
        $claim = $this->svc->reply($claim->fresh(), $staff, 'Corrected.');

        // A different admin picks it up and can read what was asked before.
        $bodies = $this->thread->forSubject($claim, asEmployee: false)->pluck('body')->implode(' | ');

        $this->assertStringContainsString('Receipt does not match.', $bodies);
        $this->assertStringContainsString('Corrected.', $bodies);

        // And they have the same three actions available.
        $approved = $this->svc->approve($claim->fresh(), $second, 2500, 'Agreed with the employee.');
        $this->assertSame(ReimbursementStatus::APPROVED, $approved->status);
    }

    /* ── the rules ───────────────────────────────────────────────────── */

    public function test_a_hold_without_a_reason_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->svc->hold($this->claim(), $this->user('admin@example.test'), '   ');
    }

    public function test_changing_the_amount_without_a_reason_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->svc->approve($this->claim(5000), $this->user('admin@example.test'), 2500);
    }

    /** Approving the claimed amount unchanged needs no reason. */
    public function test_approving_as_claimed_needs_no_reason(): void
    {
        $claim = $this->svc->approve($this->claim(5000), $this->user('admin@example.test'));

        $this->assertSame(ReimbursementStatus::APPROVED, $claim->status);
        $this->assertSame('5000.00', $claim->amount_approved);
    }

    /**
     * The SangoeTrack bug, which must not reappear: restoring an amount there
     * compares against the originally requested figure, takes the "unchanged"
     * branch, and silently writes nothing while reporting success.
     */
    public function test_restoring_an_amount_actually_writes(): void
    {
        $admin = $this->user('admin@example.test');
        $claim = $this->claim(5000);

        // Approved low, then reopened by a second look is not possible once
        // decided — so the comparison is exercised directly on a held claim that
        // already carries an approved figure.
        $claim->update(['amount_approved' => 4000]);

        $restored = $this->svc->approve($claim->fresh(), $admin, 5000, 'Second receipt covers the full amount.');

        $this->assertSame('5000.00', $restored->amount_approved, 'Restoring the amount must actually be written.');

        $changed = $this->thread->forSubject($restored, asEmployee: true)->firstWhere('event_type', 'amount_changed');
        $this->assertNotNull($changed, 'The change must be recorded.');
        // assertEquals, not assertSame: meta round-trips through JSON, where 4000.0
        // encodes as 4000 and decodes as an int. What matters is the VALUE — 4,000
        // is the amount then in force, where SangoeTrack would have compared
        // against the 5,000 originally claimed and written nothing at all.
        $this->assertEquals(4000, $changed->meta['from'], 'Recorded from the CURRENT amount, not the original claim.');
        $this->assertEquals(5000, $changed->meta['to']);
    }

    public function test_a_decided_claim_cannot_be_held_or_changed(): void
    {
        $admin = $this->user('admin@example.test');
        $claim = $this->svc->approve($this->claim(5000), $admin);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already been decided');

        $this->svc->hold($claim, $admin, 'Actually, wait.');
    }

    public function test_accept_is_refused_when_no_amount_was_proposed(): void
    {
        $admin = $this->user('admin@example.test');
        $staff = $this->user('staff@example.test', 'staff');
        $claim = $this->claim(5000);

        $this->svc->hold($claim, $admin, 'Please attach the receipt.');

        $this->assertFalse($claim->fresh()->can_accept_proposal, 'A question is not an offer.');

        $this->expectException(BusinessException::class);
        $this->svc->acceptProposal($claim->fresh(), $staff);
    }

    /** A standing offer must not survive new information. */
    public function test_replying_withdraws_the_proposed_amount(): void
    {
        $admin = $this->user('admin@example.test');
        $staff = $this->user('staff@example.test', 'staff');
        $claim = $this->claim(5000);

        $this->svc->hold($claim, $admin, 'Receipt supports 2,500.', proposedAmount: 2500);
        $claim = $this->svc->reply($claim->fresh(), $staff, 'Here is the full receipt for 5,000.');

        $this->assertNull($claim->proposed_amount, 'The offer must not stand once the employee has answered it.');
        $this->assertFalse($claim->can_accept_proposal);
    }

    /** Holding twice keeps the ORIGINAL origin, not 'on_hold'. */
    public function test_holding_a_held_claim_keeps_the_original_origin(): void
    {
        $admin = $this->user('admin@example.test');
        $claim = $this->claim(5000);

        $this->svc->hold($claim, $admin, 'First question.');
        $this->svc->hold($claim->fresh(), $admin, 'Second question.');

        $this->assertSame(ReimbursementStatus::PENDING, $claim->fresh()->held_from);
    }

    public function test_the_whole_exchange_is_on_the_thread_in_order(): void
    {
        $admin = $this->user('admin@example.test');
        $staff = $this->user('staff@example.test', 'staff');
        $claim = $this->claim(5000);

        $this->svc->hold($claim, $admin, 'Receipt supports 2,500.', proposedAmount: 2500);
        $claim = $this->svc->reply($claim->fresh(), $staff, 'Wrong file, sorry.');
        $this->svc->approve($claim->fresh(), $admin, 2500, 'Employee confirmed 2,500 is right.');

        $events = $this->thread->forSubject($claim->fresh(), asEmployee: true)
            ->pluck('event_type')->filter()->values()->all();

        $this->assertSame(['submitted', 'held', 'hold_cleared', 'amount_changed', 'approved'], $events);
    }
}
