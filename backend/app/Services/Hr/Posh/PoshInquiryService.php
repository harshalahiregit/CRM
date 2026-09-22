<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDecisionParticipant;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCommittee;
use App\Models\User;
use App\Services\Hr\Decision\DecisionRoundService;
use App\Support\Hr\Decision\Decision;
use Illuminate\Support\Facades\DB;

/**
 * The committee's inquiry, run on the shared decision-round primitive.
 *
 * Nothing about quorum, recusal, abstention, ties or unreachable quorums is
 * reimplemented here — all of it already exists, was tested on its own, and
 * is reused unchanged. This service only decides WHO sits on the round and
 * WHAT the case does once it concludes.
 *
 * THE ROSTER IS THE CASE'S MEMBERS, NOT THE COMMITTEE'S. That is the whole
 * value of the snapshot 3b built: somebody added to the committee after this
 * case opened has no seat, and somebody removed from the committee keeps
 * theirs until the case is deliberately reconstituted. Reading the live
 * committee here would quietly undo that.
 *
 * QUORUM IS RE-VALIDATED AT OPENING. It was checked when the committee was
 * configured, but a case's roster is a snapshot and members may have been
 * removed since. The primitive refuses a round that starts impossible, which
 * is a configuration error rather than a verdict.
 */
class PoshInquiryService
{
    public function __construct(
        private PoshAccessResolver $access,
        private PoshCaseAuthority $authority,
        private DecisionRoundService $rounds,
        private PoshCaseService $cases,
        private PoshFindingService $findings,
        private PoshNotifier $notifier,
    ) {
    }

    /**
     * Open the inquiry.
     *
     * One seat per active case member, every seat required. No observers in
     * v1: an optional seat neither settles nor blocks an outcome, and a
     * committee member who is on the case but whose view does not count is not
     * a concept anybody has asked for.
     */
    public function open(HrPoshCase $case, User $actor): array
    {
        $this->authority->assertCanManageCase($actor, $case);

        if (! in_array($case->status, [HrPoshCase::STATUS_RECEIVED, HrPoshCase::STATUS_UNDER_INQUIRY], true)) {
            throw new BusinessException('This case is not open to an inquiry.', 422);
        }

        if ($this->cases->liveRound($case)) {
            throw new BusinessException('An inquiry is already under way on this case.', 422);
        }

        $roster = $this->roster($case);

        if ($roster === []) {
            throw new BusinessException(
                'This case has no active members, so no inquiry can be held. Reconstitute the committee first.',
                422
            );
        }

        [$mode, $quorum] = $this->quorumFor($case, count($roster));

        return DB::transaction(function () use ($case, $actor, $roster, $mode, $quorum) {
            // The primitive re-validates achievability and refuses a round
            // that could never conclude.
            $round = $this->rounds->open($case, 'posh_inquiry', $roster, $mode, $quorum, $actor);

            $case->update([
                'status'             => HrPoshCase::STATUS_UNDER_INQUIRY,
                'inquiry_started_at' => $case->inquiry_started_at ?: now(),
                'updated_by'         => $actor->id,
            ]);

            $case->recordAudit('POSH Inquiry Opened', $actor, null, [
                'round_id' => $round->id, 'seats' => count($roster),
                'mode' => $mode, 'quorum' => $quorum,
            ]);

            $this->notifier->inquiryOpened($case, $this->access->activeMemberIds($case), $actor);

            return $this->inspect($case->fresh());
        });
    }

    /**
     * Record one member's view.
     *
     * The seat is found from the ACTOR rather than taken from the request: a
     * caller who could name their seat could name somebody else's.
     */
    public function decide(HrPoshCase $case, User $actor, string $decision, ?string $remarks = null): array
    {
        // Membership is enough to hold a seat. can_manage_case is about
        // running the case, not about having a view on it.
        $this->access->assertMember($actor, $case);

        $round = $this->cases->liveRound($case);

        if (! $round) {
            throw new BusinessException('There is no inquiry open on this case.', 422);
        }

        $seat = HrDecisionParticipant::where('round_id', $round->id)
            ->where('slot_key', (string) $actor->id)
            ->first();

        if (! $seat) {
            // On the case, but not on this round — they joined after it opened.
            throw new BusinessException('You do not hold a seat in this inquiry.', 403);
        }

        $this->rounds->decide($round, $seat->id, $actor, $decision, $remarks);

        $fresh = $round->fresh();

        // The round decides the inquiry; the case decides what that means.
        if ($fresh->state === Decision::STATE_DECIDED) {
            $case = $this->cases->applyInquiryOutcome($case, $fresh, $actor);
            $this->findings->openDraft($case, $fresh, $actor);
        }

        return $this->inspect($case->fresh());
    }

    /** The inquiry as a member sees it. */
    public function inspect(HrPoshCase $case): array
    {
        $round = $this->cases->liveRound($case);
        $history = $this->cases->rounds($case);

        return [
            'current' => $round ? $this->presentRound($round) : null,
            // Superseded and cancelled rounds stay visible: they are the
            // record of what an earlier committee decided.
            'history' => $history->map(fn ($r) => $this->presentRound($r))->values()->all(),
        ];
    }

    /* ── internals ────────────────────────────────────────────────────── */

    /**
     * One seat per active case member.
     *
     * slot_key is the user id, so decide() can find a caller's own seat
     * without being told which one it is.
     */
    private function roster(HrPoshCase $case): array
    {
        return HrPoshCaseMember::where('case_id', $case->id)
            ->where('tenant_id', $case->tenant_id)
            ->whereNull('removed_at')
            ->with('user:id,name')
            ->get()
            ->map(fn (HrPoshCaseMember $m) => [
                'slot_key'      => (string) $m->user_id,
                'slot_label'    => $m->user?->name,
                'is_required'   => true,
                'resolver_type' => 'posh_case_member',
                'resolver_ref'  => $m->role_key,
                'user_ids'      => [(int) $m->user_id],
            ])->values()->all();
    }

    /**
     * The committee's rule, applied to this case's roster.
     *
     * all_members means everybody on the ROUND, which the primitive works out
     * for itself — so it needs no number. A set quorum larger than the roster
     * is refused by the primitive rather than quietly reduced.
     */
    private function quorumFor(HrPoshCase $case, int $seats): array
    {
        $committee = HrPoshCommittee::where('tenant_id', $case->tenant_id)
            ->whereKey($case->committee_id)->first();

        if (! $committee) {
            // The committee has been removed since the case opened. The
            // snapshot roster survives; the rule for counting it does not.
            throw new BusinessException(
                'The committee for this case no longer exists, so its quorum cannot be determined.', 422
            );
        }

        if ($committee->quorum_mode !== HrPoshCommittee::QUORUM_N_OF_M) {
            return [Decision::MODE_ALL_OF, null];
        }

        $required = (int) $committee->quorum_required;

        if ($required > $seats) {
            throw new BusinessException(
                "This committee needs {$required} member(s) to agree, but the case has {$seats} active member(s). "
                .'Reconstitute the case before opening an inquiry.',
                422
            );
        }

        return [Decision::MODE_QUORUM, $required];
    }

    private function presentRound($round): array
    {
        return [
            'id'       => $round->id,
            'state'    => $round->state,
            'outcome'  => $round->outcome,
            'mode'     => $round->mode,
            'quorum'   => $round->quorum_required,
            'note'     => $round->closing_note,
            'opened_at' => optional($round->opened_at)->toIso8601String(),
            'closed_at' => optional($round->closed_at)->toIso8601String(),
            'tally'    => $this->rounds->inspect($round),
            'seats'    => $round->participants->map(fn ($p) => [
                'slot_label' => $p->slot_label,
                'role_key'   => $p->resolver_ref,
                'decision'   => $p->decision,
                'decided_by' => $p->decided_by_name,
                'decided_at' => optional($p->decided_at)->toIso8601String(),
                'remarks'    => $p->remarks,
            ])->values()->all(),
        ];
    }
}
