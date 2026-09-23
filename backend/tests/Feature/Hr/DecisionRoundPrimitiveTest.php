<?php

namespace Tests\Feature\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDecisionParticipant;
use App\Models\Hr\HrDecisionRound;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\Decision\DecisionRoundService;
use App\Support\Hr\Decision\Decision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The decision round: a fixed set of people answering one question at once.
 *
 * The approval engine answers "whose turn is it next" — current_step is a
 * single integer, the first decider at a step closes it, and a rejection
 * anywhere ends the request. None of that can express a committee deliberating
 * or five departments clearing an exit independently, which is why this exists
 * beside it rather than inside it.
 *
 * Two properties carry most of the weight.
 *
 * The roster is FROZEN. Editing a committee or a department mapping tomorrow
 * cannot hand somebody a vote in a round already under way, nor take one away.
 * A roster change supersedes the round; it never edits one.
 *
 * The quorum is NEVER reduced to fit. Recusals take people out of the
 * denominator, and if too many stand down the round says so and waits, because
 * quietly lowering the bar would change a rule the workspace set without
 * anybody asking.
 *
 * Nothing here knows what is being decided, who ought to be on the roster, what
 * an outcome should cause, or what anybody may read. That is the point.
 */
class DecisionRoundPrimitiveTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ops', 'slug' => 'dec-round', 'status' => 'active']);
    }

    /* ── fixture ──────────────────────────────────────────────────────── */

    private function user(?Tenant $tenant = null): User
    {
        return User::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'U'.substr(uniqid(), -4),
            'email' => uniqid().'@dec.test', 'password' => Hash::make('Password123!'),
            'role' => 'staff', 'status' => 'active',
        ]);
    }

    /** Any tenant-scoped model will do — the primitive is subject-agnostic. */
    private function subject(?Tenant $tenant = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => ($tenant ?: $this->tenant)->id, 'name' => 'Subject',
            'employee_code' => 'S'.substr(uniqid(), -6), 'department' => 'Ops',
            'designation' => 'Analyst', 'joining_date' => '2024-01-01', 'status' => 'Active',
        ]);
    }

    private function service(): DecisionRoundService
    {
        return app(DecisionRoundService::class);
    }

    /** A seat for one user. */
    private function seat(string $key, User $user, bool $required = true): array
    {
        return ['slot_key' => $key, 'slot_label' => ucfirst($key),
                'is_required' => $required, 'user_ids' => [$user->id]];
    }

    /** N required seats, one user each. Returns [round, users]. */
    private function roundOf(int $n, string $mode = Decision::MODE_ALL_OF, ?int $quorum = null): array
    {
        $users = collect(range(1, $n))->map(fn () => $this->user());
        $roster = $users->values()->map(fn ($u, $i) => $this->seat('seat'.$i, $u))->all();

        $round = $this->service()->open($this->subject(), 'test', $roster, $mode, $quorum);

        return [$round, $users->values()];
    }

    private function seatFor(HrDecisionRound $round, int $index): HrDecisionParticipant
    {
        return $round->participants()->orderBy('id')->get()[$index];
    }

    private function decide(HrDecisionRound $round, int $index, User $actor, string $decision, ?string $remarks = null): HrDecisionRound
    {
        return $this->service()->decide($round, $this->seatFor($round, $index)->id, $actor, $decision, $remarks);
    }

    /* ── 1. opening and the freeze ────────────────────────────────────── */

    public function test_a_round_opens_with_its_roster_frozen(): void
    {
        [$round, $users] = $this->roundOf(3);

        $this->assertSame(Decision::STATE_OPEN, $round->state);
        $this->assertNull($round->outcome);
        $this->assertCount(3, $round->participants);
        $this->assertSame([$users[0]->id], $round->participants->first()->resolved_user_ids);
    }

    public function test_a_later_roster_change_cannot_reach_an_open_round(): void
    {
        [$round, $users] = $this->roundOf(2);
        $newcomer = $this->user();

        // Whatever the domain's committee or department mapping says tomorrow,
        // this round was opened with these people.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('not a participant in this seat');
        $this->decide($round, 0, $newcomer, Decision::APPROVED);
    }

    public function test_a_round_needs_at_least_one_required_participant(): void
    {
        $optionalOnly = [$this->seat('observer', $this->user(), required: false)];

        // A round of only optional seats could never conclude; refusing at open
        // is kinder than one that silently never finishes.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('at least one required participant');
        $this->service()->open($this->subject(), 'test', $optionalOnly);
    }

    public function test_a_quorum_larger_than_the_roster_is_refused_at_open(): void
    {
        $roster = [$this->seat('a', $this->user()), $this->seat('b', $this->user())];

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot be met by 2 required participant(s)');
        $this->service()->open($this->subject(), 'test', $roster, Decision::MODE_QUORUM, 3);
    }

    /* ── 2. all_of ────────────────────────────────────────────────────── */

    public function test_all_of_concludes_approved_only_when_everyone_approves(): void
    {
        [$round, $users] = $this->roundOf(3);

        $round = $this->decide($round, 0, $users[0], Decision::APPROVED);
        $this->assertSame(Decision::STATE_OPEN, $round->state, 'one approval is not the set');

        $this->decide($round, 1, $users[1], Decision::APPROVED);
        $round = $this->decide($round, 2, $users[2], Decision::APPROVED);

        $this->assertSame(Decision::STATE_DECIDED, $round->state);
        $this->assertSame(Decision::OUTCOME_APPROVED, $round->outcome);
    }

    public function test_all_of_is_rejected_immediately_on_one_refusal(): void
    {
        [$round, $users] = $this->roundOf(3);

        $round = $this->decide($round, 0, $users[0], Decision::REJECTED);

        // Nothing the others could say would change it, so waiting for them
        // would only delay an answer that is already settled.
        $this->assertSame(Decision::STATE_DECIDED, $round->state);
        $this->assertSame(Decision::OUTCOME_REJECTED, $round->outcome);
    }

    public function test_all_of_with_an_abstention_is_inconclusive_not_approved(): void
    {
        [$round, $users] = $this->roundOf(3);

        $this->decide($round, 0, $users[0], Decision::APPROVED);
        $this->decide($round, 1, $users[1], Decision::ABSTAINED);
        $round = $this->decide($round, 2, $users[2], Decision::APPROVED);

        // Unanimous means unanimous. An abstention is participation without
        // agreement, so the set did not approve.
        $this->assertSame(Decision::OUTCOME_INCONCLUSIVE, $round->outcome);
    }

    public function test_an_optional_seat_neither_settles_nor_blocks(): void
    {
        $required = $this->user();
        $observer = $this->user();

        $round = $this->service()->open($this->subject(), 'test', [
            $this->seat('required', $required),
            $this->seat('observer', $observer, required: false),
        ]);

        $round = $this->service()->decide(
            $round, $this->seatFor($round, 0)->id, $required, Decision::APPROVED
        );

        // The observer has said nothing and the round is finished anyway.
        $this->assertSame(Decision::OUTCOME_APPROVED, $round->outcome);
    }

    public function test_an_optional_rejection_does_not_reject_the_round(): void
    {
        $required = $this->user();
        $observer = $this->user();

        $round = $this->service()->open($this->subject(), 'test', [
            $this->seat('required', $required),
            $this->seat('observer', $observer, required: false),
        ]);

        $this->service()->decide($round, $this->seatFor($round, 1)->id, $observer, Decision::REJECTED);
        $round = $this->service()->decide($round, $this->seatFor($round, 0)->id, $required, Decision::APPROVED);

        $this->assertSame(Decision::OUTCOME_APPROVED, $round->outcome);
    }

    /* ── 3. quorum ────────────────────────────────────────────────────── */

    public function test_quorum_concludes_as_soon_as_enough_approve(): void
    {
        [$round, $users] = $this->roundOf(5, Decision::MODE_QUORUM, 3);

        $this->decide($round, 0, $users[0], Decision::APPROVED);
        $this->decide($round, 1, $users[1], Decision::APPROVED);
        $round = $this->decide($round, 2, $users[2], Decision::APPROVED);

        $this->assertSame(Decision::OUTCOME_APPROVED, $round->outcome);
        // The remaining two never voted, and did not need to.
        $this->assertSame(2, $this->service()->inspect($round)['undecided']);
    }

    public function test_quorum_rejects_when_enough_refuse(): void
    {
        [$round, $users] = $this->roundOf(5, Decision::MODE_QUORUM, 3);

        $this->decide($round, 0, $users[0], Decision::REJECTED);
        $this->decide($round, 1, $users[1], Decision::REJECTED);
        $round = $this->decide($round, 2, $users[2], Decision::REJECTED);

        $this->assertSame(Decision::OUTCOME_REJECTED, $round->outcome);
    }

    public function test_an_even_split_is_inconclusive_and_nobody_breaks_it(): void
    {
        [$round, $users] = $this->roundOf(4, Decision::MODE_QUORUM, 3);

        $this->decide($round, 0, $users[0], Decision::APPROVED);
        $this->decide($round, 1, $users[1], Decision::APPROVED);
        $this->decide($round, 2, $users[2], Decision::REJECTED);
        $round = $this->decide($round, 3, $users[3], Decision::REJECTED);

        // Two each against a quorum of three: neither side can reach it. The
        // set is split, and no seat carries a casting vote.
        $this->assertSame(Decision::STATE_DECIDED, $round->state);
        $this->assertSame(Decision::OUTCOME_INCONCLUSIVE, $round->outcome);
    }

    public function test_a_round_concludes_early_once_approval_is_out_of_reach(): void
    {
        [$round, $users] = $this->roundOf(5, Decision::MODE_QUORUM, 4);

        $round = $this->decide($round, 0, $users[0], Decision::REJECTED);
        // Nought approved with four still to vote: four is still reachable.
        $this->assertSame(Decision::STATE_OPEN, $round->state);

        $round = $this->decide($round, 1, $users[1], Decision::ABSTAINED);

        // Now nought approved with three left. Even if all three approved,
        // that is three against a quorum of four — approval is out of reach,
        // and rejection never got there either. The set has effectively
        // answered, so the remaining two are not made to vote for nothing.
        $this->assertSame(Decision::STATE_DECIDED, $round->state);
        $this->assertSame(Decision::OUTCOME_INCONCLUSIVE, $round->outcome);
        $this->assertSame(3, $this->service()->inspect($round)['undecided'],
            'three seats never had to vote, because nothing they could say would change it');
    }

    public function test_a_round_stays_open_while_approval_is_still_reachable(): void
    {
        [$round, $users] = $this->roundOf(5, Decision::MODE_QUORUM, 3);

        $this->decide($round, 0, $users[0], Decision::REJECTED);
        $round = $this->decide($round, 1, $users[1], Decision::ABSTAINED);

        // Three left against a quorum of three: still exactly reachable, so
        // the round must NOT conclude early. The boundary is the interesting
        // part, and it is one either side of the test above.
        $this->assertSame(Decision::STATE_OPEN, $round->state);
        $this->assertNull($round->outcome);
    }

    /* ── 4. recusal and the denominator ───────────────────────────────── */

    public function test_a_recused_seat_leaves_the_denominator(): void
    {
        [$round, $users] = $this->roundOf(3);

        $round = $this->decide($round, 0, $users[0], Decision::RECUSED, 'Named in the complaint.');

        $this->assertSame(2, $this->service()->inspect($round)['denominator']);
        $this->assertSame(1, $this->service()->inspect($round)['recused']);
        $this->assertSame(Decision::STATE_OPEN, $round->state);
    }

    public function test_all_of_concludes_among_those_who_remain(): void
    {
        [$round, $users] = $this->roundOf(3);

        $this->decide($round, 0, $users[0], Decision::RECUSED, 'Conflict of interest.');
        $this->decide($round, 1, $users[1], Decision::APPROVED);
        $round = $this->decide($round, 2, $users[2], Decision::APPROVED);

        // The two who could act agreed. The seat that stood down is not a
        // missing approval.
        $this->assertSame(Decision::OUTCOME_APPROVED, $round->outcome);
    }

    public function test_a_recusal_needs_a_reason(): void
    {
        [$round, $users] = $this->roundOf(2);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('recusal needs a reason');
        $this->decide($round, 0, $users[0], Decision::RECUSED);
    }

    public function test_recusals_that_break_the_quorum_stall_the_round(): void
    {
        [$round, $users] = $this->roundOf(5, Decision::MODE_QUORUM, 3);

        $this->decide($round, 0, $users[0], Decision::RECUSED, 'Conflict.');
        $round = $this->decide($round, 1, $users[1], Decision::RECUSED, 'Conflict.');
        $this->assertSame(Decision::STATE_OPEN, $round->state, 'three remain, three needed');

        $round = $this->decide($round, 2, $users[2], Decision::RECUSED, 'Conflict.');

        // Two left, three needed. The quorum is NOT reduced to fit.
        $this->assertSame(Decision::STATE_QUORUM_UNREACHABLE, $round->state);
        $this->assertSame(3, $round->quorum_required);
        $this->assertNull($round->outcome, 'stalling is not a verdict');
        $this->assertStringContainsString('can no longer be met', $round->closing_note);
    }

    public function test_a_recusal_is_never_refused_even_when_it_stalls_the_round(): void
    {
        [$round, $users] = $this->roundOf(3, Decision::MODE_QUORUM, 3);

        // Forcing somebody with a conflict to vote, or to stay silent, would be
        // worse than a stalled round that says why.
        $round = $this->decide($round, 0, $users[0], Decision::RECUSED, 'Named in the complaint.');

        $this->assertSame(Decision::RECUSED, $this->seatFor($round, 0)->decision);
        $this->assertSame(Decision::STATE_QUORUM_UNREACHABLE, $round->state);
    }

    public function test_everyone_recusing_under_all_of_stalls_rather_than_approves(): void
    {
        [$round, $users] = $this->roundOf(2);

        $this->decide($round, 0, $users[0], Decision::RECUSED, 'Conflict.');
        $round = $this->decide($round, 1, $users[1], Decision::RECUSED, 'Conflict.');

        // "Everybody who could approve did" must not be true of an empty set.
        $this->assertSame(Decision::STATE_QUORUM_UNREACHABLE, $round->state);
        $this->assertNull($round->outcome);
    }

    /* ── 5. one terminal decision per seat ────────────────────────────── */

    public function test_a_seat_cannot_decide_twice(): void
    {
        [$round, $users] = $this->roundOf(3);

        $round = $this->decide($round, 0, $users[0], Decision::APPROVED);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already decided');
        $this->decide($round, 0, $users[0], Decision::REJECTED);
    }

    public function test_a_decided_round_refuses_further_decisions(): void
    {
        [$round, $users] = $this->roundOf(2);

        $this->decide($round, 0, $users[0], Decision::APPROVED);
        $round = $this->decide($round, 1, $users[1], Decision::APPROVED);
        $this->assertTrue($round->isDecided());

        $extra = $this->user();
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('closed');
        $this->service()->decide($round, $this->seatFor($round, 0)->id, $extra, Decision::REJECTED);
    }

    public function test_an_unknown_decision_value_is_refused(): void
    {
        [$round, $users] = $this->roundOf(2);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Unknown decision');
        $this->decide($round, 0, $users[0], 'maybe');
    }

    /* ── 6. supersede ─────────────────────────────────────────────────── */

    public function test_superseding_preserves_the_old_round_and_its_decisions(): void
    {
        [$round, $users] = $this->roundOf(3);
        $this->decide($round, 0, $users[0], Decision::APPROVED);

        $replacement = $this->service()->supersede(
            $round, [$this->seat('fresh', $this->user())], $users[0], 'Two members recused.'
        );

        $old = $round->fresh(['participants']);
        $this->assertSame(Decision::STATE_SUPERSEDED, $old->state);
        $this->assertSame($replacement->id, $old->superseded_by_round_id);
        // The history survives: what the previous set said is still on record.
        $this->assertSame(Decision::APPROVED, $old->participants->first()->decision);
    }

    public function test_the_replacement_carries_nothing_forward(): void
    {
        [$round, $users] = $this->roundOf(3);
        $this->decide($round, 0, $users[0], Decision::APPROVED);
        $this->decide($round, 1, $users[1], Decision::APPROVED);

        $kept = $users[0];
        $replacement = $this->service()->supersede(
            $round,
            [$this->seat('a', $kept), $this->seat('b', $this->user())],
            $kept, 'Committee reconstituted.'
        );

        // A set of people who have changed has not answered, whatever the
        // previous set said — including the member who is still on it.
        $this->assertSame(Decision::STATE_OPEN, $replacement->state);
        foreach ($replacement->participants as $p) {
            $this->assertNull($p->decision);
        }
    }

    public function test_a_stalled_round_can_be_rescued_by_superseding_it(): void
    {
        [$round, $users] = $this->roundOf(3, Decision::MODE_QUORUM, 3);
        $round = $this->decide($round, 0, $users[0], Decision::RECUSED, 'Conflict.');
        $this->assertSame(Decision::STATE_QUORUM_UNREACHABLE, $round->state);

        // The documented way out: change the roster, or the quorum, or both —
        // explicitly, with a reason, into a new round.
        $replacement = $this->service()->supersede(
            $round,
            [$this->seat('a', $users[1]), $this->seat('b', $users[2])],
            $users[1], 'Replaced the recused member.',
            Decision::MODE_QUORUM, 2
        );

        $this->assertSame(Decision::STATE_OPEN, $replacement->state);
        $this->assertSame(2, $replacement->quorum_required);
    }

    public function test_superseding_needs_a_reason(): void
    {
        [$round, $users] = $this->roundOf(2);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('needs a reason');
        $this->service()->supersede($round, [$this->seat('a', $users[0])], $users[0], '');
    }

    public function test_a_round_cannot_be_superseded_twice(): void
    {
        [$round, $users] = $this->roundOf(2);
        $this->service()->supersede($round, [$this->seat('a', $users[0])], $users[0], 'First.');

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already been replaced');
        $this->service()->supersede($round->fresh(), [$this->seat('b', $users[1])], $users[1], 'Second.');
    }

    public function test_the_roster_change_is_audited_with_both_rosters(): void
    {
        [$round, $users] = $this->roundOf(2);
        $this->service()->supersede($round, [$this->seat('a', $this->user())], $users[0], 'Reconstituted.');

        $entry = $round->fresh()->auditLogs()->where('action', 'Decision Round Replaced')->first();

        $this->assertNotNull($entry);
        $this->assertSame($users[0]->id, (int) $entry->actor_id);
        $this->assertStringContainsString('Reconstituted.', (string) $entry->comment);
    }

    /* ── 7. cancellation ──────────────────────────────────────────────── */

    public function test_a_cancelled_round_takes_no_further_decisions(): void
    {
        [$round, $users] = $this->roundOf(2);
        $round = $this->service()->cancel($round, $users[0], 'Case withdrawn.');

        $this->assertSame(Decision::STATE_CANCELLED, $round->state);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('closed');
        $this->decide($round, 0, $users[0], Decision::APPROVED);
    }

    /* ── 8. tenant isolation ──────────────────────────────────────────── */

    public function test_a_user_from_another_tenant_cannot_decide(): void
    {
        [$round, $users] = $this->roundOf(2);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'dec-other', 'status' => 'active']);
        $stranger = $this->user($other);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('not found');
        $this->decide($round, 0, $stranger, Decision::APPROVED);
    }

    public function test_a_round_belongs_to_its_subjects_tenant(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'dec-other2', 'status' => 'active']);

        $round = $this->service()->open(
            $this->subject($other), 'test', [$this->seat('a', $this->user($other))]
        );

        $this->assertSame($other->id, (int) $round->tenant_id);
        $this->assertSame(0, HrDecisionRound::where('tenant_id', $this->tenant->id)->count());
    }

    /* ── 9. audit ─────────────────────────────────────────────────────── */

    public function test_opening_deciding_and_concluding_are_all_audited(): void
    {
        [$round, $users] = $this->roundOf(2);
        $this->decide($round, 0, $users[0], Decision::APPROVED);
        $round = $this->decide($round, 1, $users[1], Decision::APPROVED);

        $actions = $round->fresh()->auditLogs()->pluck('action')->all();

        $this->assertContains('Decision Round Opened', $actions);
        $this->assertContains('Decision Recorded', $actions);
        $this->assertContains('Decision Round Concluded', $actions);
    }

    public function test_a_decision_records_who_made_it(): void
    {
        [$round, $users] = $this->roundOf(2);
        $round = $this->decide($round, 0, $users[0], Decision::APPROVED);

        $seat = $this->seatFor($round, 0);
        $this->assertSame($users[0]->id, (int) $seat->decided_by);
        $this->assertSame($users[0]->name, $seat->decided_by_name);
        $this->assertNotNull($seat->decided_at);
    }

    /* ── 10. it did not become a second approval engine ───────────────── */

    public function test_the_primitive_has_no_sequencing_vocabulary(): void
    {
        $columns = \Schema::getColumnListing('hr_decision_rounds')
            + \Schema::getColumnListing('hr_decision_participants');

        // The absence IS the design. A step cursor, an approver type or a
        // condition here would be the beginning of a second ApprovalEngine,
        // and the two would eventually disagree.
        foreach (['current_step', 'step_order', 'approver_type', 'approver_ref',
                  'conditions', 'workflow_id', 'levels_up'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    public function test_the_approval_engine_is_untouched_by_this(): void
    {
        // Every seat is open at once — there is no "whose turn is it", which is
        // the only question ApprovalEngine answers. They do not overlap.
        [$round, $users] = $this->roundOf(3);

        $this->decide($round, 2, $users[2], Decision::APPROVED);
        $round = $this->decide($round, 0, $users[0], Decision::APPROVED);

        $this->assertSame(Decision::STATE_OPEN, $round->state);
        $this->assertSame(1, $this->service()->inspect($round)['undecided']);
    }
}
