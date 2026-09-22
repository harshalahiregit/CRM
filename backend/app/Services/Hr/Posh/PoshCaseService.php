<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDecisionRound;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCommittee;
use App\Models\Hr\HrPoshCommitteeMember;
use App\Models\User;
use App\Services\Hr\Decision\DecisionRoundService;
use App\Support\Hr\Decision\Decision;
use Illuminate\Support\Facades\DB;

/**
 * Raising a case, moving it through its states, and consuming a finished
 * inquiry.
 *
 * THE CASE OWNS ITS OWN STATUS. The decision round decides the inquiry and
 * nothing else — it never writes hr_posh_cases. This service reads a terminal
 * round and decides what that means for the case, which is the same boundary
 * every other migrated process keeps: the engine decides whether, the domain
 * decides what happens.
 *
 * CREATING A CASE GRANTS THE CREATOR NOTHING. Intake is a capability; reading
 * a case is a membership. Somebody in HR may need to log a complaint without
 * being entitled to read it afterwards — particularly if the complaint is
 * about a colleague of theirs — and a creator bypass would make that
 * impossible to arrange.
 */
class PoshCaseService
{
    public function __construct(
        private PoshCaseReference $references,
        private PoshAccessResolver $access,
        private PoshCaseAuthority $authority,
        private DecisionRoundService $rounds,
        private PoshNotifier $notifier,
    ) {
    }

    /* ── intake ───────────────────────────────────────────────────────── */

    /**
     * Raise a case and snapshot the committee onto it.
     *
     * The snapshot is the point. From here the case has its own roster, and a
     * committee reshuffled tomorrow cannot reach into it — access changes only
     * by explicit reconstitution, which is a deliberate, audited act.
     */
    public function create(int $tenantId, array $data, User $actor): HrPoshCase
    {
        $committee = HrPoshCommittee::where('tenant_id', $tenantId)
            ->whereKey((int) ($data['committee_id'] ?? 0))
            ->first();

        if (! $committee) {
            throw new BusinessException('Committee not found.', 404);
        }

        if (! $committee->is_active) {
            // An inactive committee has not met its own invariants, so a case
            // handed to it could not be inquired into.
            throw new BusinessException('That committee is not active.', 422);
        }

        $members = $committee->activeMembers();

        if ($members->isEmpty()) {
            throw new BusinessException(
                'That committee has no active members, so a case cannot be assigned to it.', 422
            );
        }

        return DB::transaction(function () use ($tenantId, $data, $actor, $committee, $members) {
            $case = HrPoshCase::create([
                'tenant_id'   => $tenantId,
                'reference'   => $this->references->next($tenantId),
                'committee_id' => $committee->id,

                'complainant_type'        => $data['complainant_type'] ?? HrPoshCase::COMPLAINANT_EMPLOYEE,
                'complainant_employee_id' => $data['complainant_employee_id'] ?? null,
                'complainant_label'       => $data['complainant_label'] ?? null,

                // Stored, never granted access. What a respondent may see is
                // an unanswered legal question and no surface exists for it.
                'respondent_employee_id'  => $data['respondent_employee_id'] ?? null,
                'respondent_label'        => $data['respondent_label'] ?? null,

                'incident_at'    => $data['incident_at'] ?? null,
                'incident_place' => $data['incident_place'] ?? null,
                'narrative'      => $data['narrative'],

                'status'                => HrPoshCase::STATUS_RECEIVED,
                'complaint_received_at' => $data['complaint_received_at'] ?? now(),

                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($members as $member) {
                HrPoshCaseMember::grant(
                    $case,
                    (int) $member->user_id,
                    // By VALUE. A role renamed or deleted later cannot change
                    // what this person sat as on this case.
                    (string) ($member->role?->key ?: 'member'),
                    HrPoshCaseMember::SOURCE_SNAPSHOT,
                    $actor,
                    'Committee roster at intake.'
                );
            }

            $case->recordAudit('POSH Case Opened', $actor, null, [
                'reference' => $case->reference,
                'committee' => $committee->id,
                'members'   => $members->count(),
            ]);

            // Existence only. Every member is told there is a case; nobody is
            // told anything about it.
            $this->notifier->membersAdded($case, $this->access->activeMemberIds($case), $actor);

            return $case->fresh();
        });
    }

    /* ── status ───────────────────────────────────────────────────────── */

    /**
     * Withdraw a case.
     *
     * A case member with can_manage_case, and nobody else. Not the creator by
     * virtue of having created it, not an administrator, not an HR-settings
     * holder. The complainant withdrawing their own complaint is the obvious
     * missing case and it waits for the restricted surface that does not exist
     * yet.
     */
    public function withdraw(HrPoshCase $case, User $actor, string $reason): HrPoshCase
    {
        $this->authority->assertCanManageCase($actor, $case);

        if (in_array($case->status, [HrPoshCase::STATUS_CLOSED, HrPoshCase::STATUS_WITHDRAWN], true)) {
            throw new BusinessException('This case is already finished.', 422);
        }

        if (trim($reason) === '') {
            throw new BusinessException('Withdrawing a case needs a reason.', 422);
        }

        return DB::transaction(function () use ($case, $actor, $reason) {
            // A live inquiry is abandoned, not decided. Cancelling preserves
            // every decision already recorded on it as history.
            $round = $this->liveRound($case);
            if ($round) {
                $this->rounds->cancel($round, $actor, 'Case withdrawn: '.trim($reason));
            }

            $case->update([
                'status'     => HrPoshCase::STATUS_WITHDRAWN,
                'updated_by' => $actor->id,
            ]);

            $case->recordAudit('POSH Case Withdrawn', $actor, trim($reason));

            return $case->fresh();
        });
    }

    /** Close a case that has run its course. */
    public function close(HrPoshCase $case, User $actor, ?string $note = null): HrPoshCase
    {
        $this->authority->assertCanManageCase($actor, $case);

        if ($case->status === HrPoshCase::STATUS_CLOSED) {
            throw new BusinessException('This case is already closed.', 422);
        }

        if ($case->status === HrPoshCase::STATUS_WITHDRAWN) {
            throw new BusinessException('A withdrawn case cannot be closed.', 422);
        }

        if ($this->liveRound($case)) {
            throw new BusinessException(
                'The inquiry is still open. Conclude or supersede it before closing the case.', 422
            );
        }

        $case->update(['status' => HrPoshCase::STATUS_CLOSED, 'updated_by' => $actor->id]);
        $case->recordAudit('POSH Case Closed', $actor, $note);

        return $case->fresh();
    }

    /** Record that the complaint was acknowledged. A date, never a deadline. */
    public function acknowledge(HrPoshCase $case, User $actor): HrPoshCase
    {
        $this->authority->assertCanManageCase($actor, $case);

        if ($case->acknowledged_at) {
            throw new BusinessException('This case has already been acknowledged.', 422);
        }

        $case->update(['acknowledged_at' => now(), 'updated_by' => $actor->id]);
        $case->recordAudit('POSH Case Acknowledged', $actor);

        return $case->fresh();
    }

    /* ── consuming a finished inquiry ─────────────────────────────────── */

    /**
     * Move the case on, once the round has actually concluded.
     *
     * Called by PoshInquiryService after every decision. The round remains the
     * authoritative record of who decided what; this only reads its verdict.
     * A round that is still open, stalled on an unreachable quorum, superseded
     * or cancelled moves nothing — which is what keeps quorum_unreachable a
     * state to be resolved rather than an outcome to be acted on.
     */
    public function applyInquiryOutcome(HrPoshCase $case, HrDecisionRound $round, ?User $actor = null): HrPoshCase
    {
        if ($round->state !== Decision::STATE_DECIDED) {
            return $case;
        }

        if ($case->status === HrPoshCase::STATUS_INQUIRY_COMPLETE) {
            return $case;
        }

        $case->update([
            'status'               => HrPoshCase::STATUS_INQUIRY_COMPLETE,
            'outcome'              => $round->outcome,
            'inquiry_completed_at' => now(),
            'updated_by'           => $actor?->id,
        ]);

        $case->recordAudit('POSH Inquiry Concluded', $actor, null, [
            'outcome' => $round->outcome, 'round_id' => $round->id,
        ]);

        $this->notifier->inquiryConcluded($case, $this->access->activeMemberIds($case), $actor);

        return $case->fresh();
    }

    /* ── shared lookups ───────────────────────────────────────────────── */

    /** The inquiry round still able to receive decisions, if there is one. */
    public function liveRound(HrPoshCase $case): ?HrDecisionRound
    {
        return HrDecisionRound::where('tenant_id', $case->tenant_id)
            ->where('subject_type', $case->getMorphClass())
            ->where('subject_id', $case->getKey())
            ->whereIn('state', Decision::LIVE)
            ->latest('id')
            ->first();
    }

    /** Every round this case has had, newest first — including superseded ones. */
    public function rounds(HrPoshCase $case)
    {
        return HrDecisionRound::where('tenant_id', $case->tenant_id)
            ->where('subject_type', $case->getMorphClass())
            ->where('subject_id', $case->getKey())
            ->with('participants')
            ->orderByDesc('id')
            ->get();
    }
}
