<?php

namespace App\Services\Hr\Decision;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDecisionParticipant;
use App\Models\Hr\HrDecisionRound;
use App\Models\User;
use App\Support\Hr\Decision\Decision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Opening a decision round, recording what each seat said, and working out when
 * the set has answered.
 *
 * DELIBERATELY NARROW. It does not know what it is deciding about, who ought to
 * be on the roster, what the outcome should cause, who may reach the endpoint,
 * what to notify, or what anybody is allowed to read. All of that belongs to
 * the calling domain. The temptation with a component like this is to grow it
 * until it can express every workflow in the product; that is how the first
 * approval engine would end up with a second one beside it, and the two would
 * disagree.
 *
 * What it DOES own is the arithmetic, because that is the part every caller
 * would otherwise reimplement slightly differently: who is still counted, when
 * a set has finished answering, and what it concluded.
 *
 * The one check that is not domain authorization: a decision must come from
 * somebody on that seat's frozen roster. That is roster integrity — without it
 * the freeze means nothing — and the domain must still gate its own endpoint.
 */
class DecisionRoundService
{
    /**
     * Open a round over a subject, freezing the roster as given.
     *
     * The caller resolves membership. This records it and never resolves it
     * again, so a committee edited tomorrow cannot change who had a vote today.
     *
     * @param  array<int, array{slot_key:string, slot_label?:string,
     *     is_required?:bool, resolver_type?:string, resolver_ref?:string,
     *     user_ids?:array<int,int>}>  $roster
     */
    public function open(
        Model $subject,
        string $purpose,
        array $roster,
        string $mode = Decision::MODE_ALL_OF,
        ?int $quorumRequired = null,
        ?User $actor = null,
    ): HrDecisionRound {
        if (! Decision::isMode($mode)) {
            throw new BusinessException('Unknown decision mode: '.$mode, 422);
        }

        if ($roster === []) {
            throw new BusinessException('A decision round needs at least one participant.', 422);
        }

        $tenantId = (int) $subject->tenant_id;
        $required = count(array_filter($roster, fn ($p) => ($p['is_required'] ?? true) === true));

        if ($required === 0) {
            // Optional seats never settle an outcome, so a round made only of
            // them could never conclude. Refusing at open is kinder than a
            // round that silently never finishes.
            throw new BusinessException('A decision round needs at least one required participant.', 422);
        }

        if ($mode === Decision::MODE_QUORUM) {
            if ($quorumRequired === null || $quorumRequired < 1) {
                throw new BusinessException('A quorum round needs a quorum of at least one.', 422);
            }
            if ($quorumRequired > $required) {
                // Checked at open as well as at configuration time: a roster can
                // be smaller than the rule a workspace saved, and a round that
                // starts impossible is a configuration error, not a verdict.
                throw new BusinessException(
                    "The quorum of {$quorumRequired} cannot be met by {$required} required participant(s).",
                    422
                );
            }
        }

        return DB::transaction(function () use ($subject, $purpose, $roster, $mode, $quorumRequired, $actor, $tenantId) {
            $round = HrDecisionRound::create([
                'tenant_id'       => $tenantId,
                'subject_type'    => $subject->getMorphClass(),
                'subject_id'      => $subject->getKey(),
                'purpose'         => $purpose,
                'mode'            => $mode,
                'quorum_required' => $mode === Decision::MODE_QUORUM ? $quorumRequired : null,
                'state'           => Decision::STATE_OPEN,
                'opened_at'       => now(),
                'created_by'      => $actor?->id,
                'updated_by'      => $actor?->id,
            ]);

            foreach ($roster as $seat) {
                $key = trim((string) ($seat['slot_key'] ?? ''));
                if ($key === '') {
                    throw new BusinessException('Every participant needs a slot key.', 422);
                }

                HrDecisionParticipant::create([
                    'tenant_id'         => $tenantId,
                    'round_id'          => $round->id,
                    'slot_key'          => $key,
                    'slot_label'        => $seat['slot_label'] ?? null,
                    'is_required'       => ($seat['is_required'] ?? true) === true,
                    'resolver_type'     => $seat['resolver_type'] ?? null,
                    'resolver_ref'      => $seat['resolver_ref'] ?? null,
                    'resolved_user_ids' => array_values(array_unique(array_map(
                        'intval', $seat['user_ids'] ?? []
                    ))),
                ]);
            }

            $round->recordAudit('Decision Round Opened', $actor, null, [
                'purpose' => $purpose, 'mode' => $mode,
                'quorum'  => $round->quorum_required, 'participants' => count($roster),
            ]);

            // A round can be born unreachable: every seat resolving to nobody
            // is not the same as a round waiting to be answered.
            $fresh = $round->fresh(['participants']);
            $this->evaluate($fresh, $actor);

            return $fresh->fresh(['participants']);
        });
    }

    /**
     * Record one seat's answer.
     *
     * Serialised on the round, so two people pressing at the same moment cannot
     * both be counted against a stale tally.
     */
    public function decide(
        HrDecisionRound $round,
        int $participantId,
        User $actor,
        string $decision,
        ?string $remarks = null,
    ): HrDecisionRound {
        if (! Decision::isDecision($decision)) {
            throw new BusinessException('Unknown decision: '.$decision, 422);
        }

        if ($decision === Decision::RECUSED && trim((string) $remarks) === '') {
            // A recusal changes the arithmetic. Without a reason it is an
            // unexplained hole in the record at exactly the point somebody
            // will later ask why the numbers moved.
            throw new BusinessException('A recusal needs a reason.', 422);
        }

        return DB::transaction(function () use ($round, $participantId, $actor, $decision, $remarks) {
            $fresh = HrDecisionRound::whereKey($round->id)->lockForUpdate()->first();

            if (! $fresh || ! $fresh->isLive()) {
                throw new BusinessException('This decision round is closed.', 422);
            }

            if ((int) $fresh->tenant_id !== (int) $actor->tenant_id) {
                throw new BusinessException('Decision round not found.', 404);
            }

            $participant = HrDecisionParticipant::where('round_id', $fresh->id)
                ->whereKey($participantId)->first();

            if (! $participant) {
                throw new BusinessException('That participant is not part of this round.', 404);
            }

            if ($participant->hasDecided()) {
                throw new BusinessException('That participant has already decided.', 422);
            }

            if (! $participant->admits($actor->id)) {
                // Roster integrity, not domain authorization: the frozen list
                // is the record of who held this seat, and writing a decision
                // for somebody outside it would make the snapshot a fiction.
                throw new BusinessException('You are not a participant in this seat.', 403);
            }

            $participant->update([
                'decision'        => $decision,
                'decided_by'      => $actor->id,
                'decided_by_name' => $actor->name,
                'decided_at'      => now(),
                'remarks'         => $remarks,
            ]);

            $fresh->recordAudit('Decision Recorded', $actor, $remarks, [
                'slot'     => $participant->slot_key,
                'decision' => $decision,
            ]);

            $this->evaluate($fresh->fresh(['participants']), $actor);

            return $fresh->fresh(['participants']);
        });
    }

    /**
     * Close this round and hand the question to a fresh one.
     *
     * The route every roster or quorum change takes. The old round keeps every
     * decision it collected, as history; the new one starts empty. Nothing is
     * carried forward, and that is the point: a set of people who have changed
     * has not answered, whatever the previous set said.
     */
    public function supersede(
        HrDecisionRound $round,
        array $roster,
        ?User $actor = null,
        ?string $reason = null,
        ?string $mode = null,
        ?int $quorumRequired = null,
    ): HrDecisionRound {
        if (trim((string) $reason) === '') {
            throw new BusinessException('Replacing a decision round needs a reason.', 422);
        }

        if ($round->state === Decision::STATE_SUPERSEDED) {
            throw new BusinessException('This round has already been replaced.', 422);
        }

        return DB::transaction(function () use ($round, $roster, $actor, $reason, $mode, $quorumRequired) {
            $subject = $round->subject()->first();

            if (! $subject) {
                throw new BusinessException('The subject of this round no longer exists.', 422);
            }

            $replacement = $this->open(
                $subject,
                $round->purpose,
                $roster,
                $mode ?? $round->mode,
                $quorumRequired ?? $round->quorum_required,
                $actor,
            );

            $round->update([
                'state'                  => Decision::STATE_SUPERSEDED,
                'closed_at'              => now(),
                'closing_note'           => trim($reason),
                'superseded_by_round_id' => $replacement->id,
                'updated_by'             => $actor?->id,
            ]);

            $round->recordAudit('Decision Round Replaced', $actor, trim($reason), [
                'old_roster'   => $this->rosterSummary($round),
                'new_roster'   => $this->rosterSummary($replacement),
                'new_round_id' => $replacement->id,
            ]);

            return $replacement->fresh(['participants']);
        });
    }

    /** Abandon a round without replacing it. */
    public function cancel(HrDecisionRound $round, ?User $actor = null, ?string $reason = null): HrDecisionRound
    {
        if (! $round->isLive()) {
            throw new BusinessException('This decision round is already closed.', 422);
        }

        $round->update([
            'state'        => Decision::STATE_CANCELLED,
            'closed_at'    => now(),
            'closing_note' => $reason ? trim($reason) : null,
            'updated_by'   => $actor?->id,
        ]);

        $round->recordAudit('Decision Round Cancelled', $actor, $reason);

        return $round->fresh(['participants']);
    }

    /** What the round looks like right now, without deciding anything. */
    public function inspect(HrDecisionRound $round): array
    {
        $required    = $round->participants->where('is_required', true);
        $denominator = $required->filter(fn ($p) => $p->countsTowardsQuorum());

        return [
            'state'        => $round->state,
            'outcome'      => $round->outcome,
            'mode'         => $round->mode,
            'quorum'       => $round->quorum_required,
            'required'     => $required->count(),
            'denominator'  => $denominator->count(),
            'recused'      => $required->where('decision', Decision::RECUSED)->count(),
            'approved'     => $required->where('decision', Decision::APPROVED)->count(),
            'rejected'     => $required->where('decision', Decision::REJECTED)->count(),
            'abstained'    => $required->where('decision', Decision::ABSTAINED)->count(),
            'undecided'    => $denominator->filter(fn ($p) => ! $p->hasDecided())->count(),
        ];
    }

    /* ── the arithmetic ───────────────────────────────────────────────── */

    /**
     * Work out where the round now stands, and close it if the set has answered.
     *
     * OPTIONAL SEATS ARE IGNORED HERE. They may decide, and their answers are
     * kept, but they never settle or block an outcome — the same rule exit
     * clearance already applies to non-mandatory departments.
     *
     * RECUSED SEATS LEAVE THE DENOMINATOR. The remainder decide among
     * themselves. The configured quorum is never reduced to compensate: if too
     * many stand down the round says so and waits, because quietly lowering the
     * bar would change a rule the workspace set without anybody asking.
     */
    private function evaluate(HrDecisionRound $round, ?User $actor): void
    {
        if (! $round->isLive()) {
            return;
        }

        $required    = $round->participants->where('is_required', true);
        $denominator = $required->filter(fn ($p) => $p->countsTowardsQuorum());

        if ($denominator->isEmpty()) {
            // Everybody stood down. Not a verdict — a round that needs a
            // different set of people before it can mean anything.
            $this->stall($round, $actor, 'Every required participant has recused.');

            return;
        }

        $approved  = $denominator->where('decision', Decision::APPROVED)->count();
        $rejected  = $denominator->where('decision', Decision::REJECTED)->count();
        $undecided = $denominator->filter(fn ($p) => ! $p->hasDecided())->count();

        if ($round->mode === Decision::MODE_ALL_OF) {
            // One refusal is enough, and it is enough immediately — there is
            // nothing the rest could say that would change it.
            if ($rejected > 0) {
                $this->conclude($round, $actor, Decision::OUTCOME_REJECTED);

                return;
            }

            if ($undecided > 0) {
                $this->revive($round, $actor);

                return;
            }

            // Everybody answered and nobody refused. Unanimous approval only
            // when it really is unanimous: an abstention means the set did not
            // agree, which is inconclusive rather than approved.
            $this->conclude(
                $round, $actor,
                $approved === $denominator->count()
                    ? Decision::OUTCOME_APPROVED
                    : Decision::OUTCOME_INCONCLUSIVE
            );

            return;
        }

        // Quorum. The rule a workspace configured, applied to whoever is left.
        $quorum = (int) $round->quorum_required;

        if ($quorum > $denominator->count()) {
            $this->stall(
                $round, $actor,
                "The quorum of {$quorum} can no longer be met: {$denominator->count()} participant(s) remain."
            );

            return;
        }

        if ($approved >= $quorum) {
            $this->conclude($round, $actor, Decision::OUTCOME_APPROVED);

            return;
        }

        if ($rejected >= $quorum) {
            $this->conclude($round, $actor, Decision::OUTCOME_REJECTED);

            return;
        }

        // Neither side has reached the bar. The question is whether either
        // still can — if the undecided seats could not carry approval even by
        // voting together, the set has effectively answered.
        if ($approved + $undecided >= $quorum) {
            $this->revive($round, $actor);

            return;
        }

        // Approval is out of reach and rejection never got there either: the
        // set is split. A tie lands here, and nobody breaks it.
        $this->conclude($round, $actor, Decision::OUTCOME_INCONCLUSIVE);
    }

    private function conclude(HrDecisionRound $round, ?User $actor, string $outcome): void
    {
        $round->update([
            'state'      => Decision::STATE_DECIDED,
            'outcome'    => $outcome,
            'closed_at'  => now(),
            'updated_by' => $actor?->id,
        ]);

        $round->recordAudit('Decision Round Concluded', $actor, null, [
            'outcome' => $outcome,
        ] + $this->inspect($round->fresh(['participants'])));
    }

    /** Alive, and arithmetically stuck until somebody changes the roster. */
    private function stall(HrDecisionRound $round, ?User $actor, string $why): void
    {
        if ($round->state === Decision::STATE_QUORUM_UNREACHABLE) {
            return;
        }

        $round->update([
            'state'        => Decision::STATE_QUORUM_UNREACHABLE,
            'closing_note' => $why,
            'updated_by'   => $actor?->id,
        ]);

        $round->recordAudit('Decision Round Quorum Unreachable', $actor, $why);
    }

    /**
     * Back from unreachable without a roster change.
     *
     * Only possible in principle — a recusal is terminal per seat, so a stalled
     * round does not normally recover on its own. Kept so the state is derived
     * from the arithmetic rather than latched, which means the tests can prove
     * the stall is a conclusion about the numbers and not a flag somebody set.
     */
    private function revive(HrDecisionRound $round, ?User $actor): void
    {
        if ($round->state === Decision::STATE_OPEN) {
            return;
        }

        $round->update(['state' => Decision::STATE_OPEN, 'closing_note' => null, 'updated_by' => $actor?->id]);
    }

    private function rosterSummary(HrDecisionRound $round): array
    {
        return $round->participants->map(fn (HrDecisionParticipant $p) => [
            'slot'        => $p->slot_key,
            'is_required' => (bool) $p->is_required,
            'user_ids'    => $p->resolved_user_ids ?? [],
            'decision'    => $p->decision,
        ])->values()->all();
    }
}
