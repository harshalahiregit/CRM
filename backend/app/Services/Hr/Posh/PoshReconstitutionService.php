<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCommitteeRole;
use App\Models\User;
use App\Services\Hr\Decision\DecisionRoundService;
use Illuminate\Support\Facades\DB;

/**
 * Changing who is on a case.
 *
 * The only way an existing case's membership ever moves. A committee
 * reshuffled elsewhere does not reach a case that is already open — access
 * changes here, deliberately, with a reason, and it is audited.
 *
 * THE RECONSTITUTOR GAINS NOTHING. This is authorised by hr_settings, which is
 * configuration authority, and configuration authority is not case-content
 * authority. Somebody repairing a stalled committee has no business reading
 * the complaint, and nothing here gives them a membership row. The screen that
 * drives this shows a reference and a roster — never a complainant, a
 * respondent, a narrative or any evidence.
 *
 * AND THEY CANNOT TAKE IT EITHER. Any authority that may NAME case members may
 * name itself, which would turn hr_settings into a way to read any complaint in
 * the workspace — including one about the person holding it. So a roster entry
 * for the actor is dropped rather than honoured.
 *
 * Dropped, not refused, and only for them: refusing the whole request would
 * hand a stalled case to nobody, and the entire reason this operation exists is
 * that a committee can become unable to act. The response returns the roster as
 * actually stored, so the caller is never shown a committee the server did not
 * write. A roster of nothing but the actor leaves no case at all and is refused
 * outright.
 *
 * Preserving an EXISTING membership is not self-nomination. Somebody already on
 * the case reaches nothing new by staying on it, and dropping them here would
 * revoke a sitting member for the sole offence of being the person who filed
 * the paperwork.
 *
 * NOTHING IS DELETED. Removed members have their period closed; added members
 * get a new one beside it. An open inquiry is SUPERSEDED rather than edited,
 * so the previous committee's decisions survive as the record of what they
 * decided, and the new committee starts from nothing — a set of people who
 * have changed has not answered, whatever the previous set said.
 */
class PoshReconstitutionService
{
    public function __construct(
        private PoshAccessResolver $access,
        private PoshCaseService $cases,
        private DecisionRoundService $rounds,
        private PoshNotifier $notifier,
    ) {
    }

    /**
     * Replace the case roster.
     *
     * @param  array<int, array{user_id:int, role_key:string}>  $roster
     */
    public function reconstitute(HrPoshCase $case, array $roster, User $actor, string $reason): array
    {
        if (trim($reason) === '') {
            throw new BusinessException('Reconstituting a case needs a reason.', 422);
        }

        if ($roster === []) {
            throw new BusinessException(
                'A case needs at least one member. Withdraw or close it instead of emptying it.', 422
            );
        }

        $wanted = $this->validate($case, $roster);
        $selfExcluded = $this->dropSelfNomination($case, $wanted, $actor);
        $before = $this->rosterSnapshot($case);

        return DB::transaction(function () use ($case, $wanted, $actor, $reason, $before, $selfExcluded) {
            $current = HrPoshCaseMember::where('case_id', $case->id)
                ->whereNull('removed_at')->get();

            // Close the periods of everybody who is not staying. The rows stay
            // exactly where they are, stamped with when and why.
            foreach ($current as $member) {
                if (! array_key_exists((int) $member->user_id, $wanted)) {
                    HrPoshCaseMember::revoke($case, (int) $member->user_id, $actor, trim($reason));
                }
            }

            // Open a period for everybody new. Somebody already on the case
            // keeps their existing period rather than being churned.
            $existing = $current->pluck('user_id')->map(fn ($id) => (int) $id)->all();

            foreach ($wanted as $userId => $roleKey) {
                if (! in_array($userId, $existing, true)) {
                    HrPoshCaseMember::grant(
                        $case, $userId, $roleKey,
                        HrPoshCaseMember::SOURCE_RECONSTITUTION, $actor, trim($reason)
                    );
                }
            }

            // A live inquiry belonged to the previous set of people.
            $round = $this->cases->liveRound($case);

            if ($round) {
                $this->rounds->supersede(
                    $round,
                    $this->roundRoster($case),
                    $actor,
                    'Case reconstituted: '.trim($reason),
                );
            }

            $case->update(['updated_by' => $actor->id]);

            $case->recordAudit('POSH Case Reconstituted', $actor, trim($reason), [
                'old_roster' => $before,
                'new_roster' => $this->rosterSnapshot($case->fresh()),
                'round_superseded' => $round?->id,
                // An attempt to sit on a case one is only configuring is worth
                // a permanent mark. Ids and a flag only — no case content.
                'self_nomination_dropped' => $selfExcluded ? (int) $actor->id : null,
            ]);

            // Everyone on the case now, not only the arrivals: somebody who
            // stayed needs to know the committee around them has changed.
            $this->notifier->membershipChanged($case, $this->access->activeMemberIds($case->fresh()), $actor);

            return $this->rosterSnapshot($case->fresh());
        });
    }

    /* ── internals ────────────────────────────────────────────────────── */

    /**
     * Every entry has to be usable before any of them is written.
     *
     * Refused rather than skipped: a roster that silently dropped an invalid
     * entry would leave the screen showing a committee the server never
     * stored.
     *
     * @return array<int, string>  user id => role key
     */
    private function validate(HrPoshCase $case, array $roster): array
    {
        $out = [];

        foreach ($roster as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            $roleKey = trim((string) ($row['role_key'] ?? ''));

            if (isset($out[$userId])) {
                throw new BusinessException('One person cannot hold two seats on a case.', 422);
            }

            $eligible = User::whereKey($userId)
                ->where('tenant_id', $case->tenant_id)
                ->where('status', 'active')
                ->whereIn('role', ['admin', 'staff'])
                ->exists();

            if (! $eligible) {
                // One message for every failure: naming which workspace an id
                // belongs to is more than the caller asked.
                throw new BusinessException(
                    'A case member must be an active staff account in this workspace.', 422
                );
            }

            $roleExists = HrPoshCommitteeRole::where('tenant_id', $case->tenant_id)
                ->where('committee_id', $case->committee_id)
                ->where('key', $roleKey)
                ->exists();

            if (! $roleExists) {
                throw new BusinessException('That role does not belong to this case\'s committee.', 422);
            }

            $out[$userId] = $roleKey;
        }

        return $out;
    }

    /**
     * Take the actor out of their own roster.
     *
     * The one thing hr_settings must not be able to do with this operation is
     * use it to read a complaint — possibly one about them. Naming authority is
     * self-naming authority unless something stops it, and this is that.
     *
     * Returns whether an entry was actually dropped, so the audit trail records
     * the attempt rather than only the result.
     *
     * @param  array<int, string>  $wanted  user id => role key, modified in place
     */
    private function dropSelfNomination(HrPoshCase $case, array &$wanted, User $actor): bool
    {
        $actorId = (int) $actor->id;

        if (! array_key_exists($actorId, $wanted)) {
            return false;
        }

        // Already a member: they are keeping access they hold, not taking
        // access they do not. Left alone, and specifically NOT revoked for
        // having been the one to submit this.
        if ($this->access->isMember($actor, $case)) {
            return false;
        }

        unset($wanted[$actorId]);

        if ($wanted === []) {
            // Everything asked for has just been refused. Writing this would
            // leave a case with no members at all — unreadable by anybody and
            // unable to hold an inquiry — so it is refused as a whole rather
            // than half-applied.
            throw new BusinessException(
                'You cannot reconstitute a case onto yourself. Name at least one other member.', 422
            );
        }

        return true;
    }

    /** One seat per active member, for the replacement round. */
    private function roundRoster(HrPoshCase $case): array
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

    /** The roster as the audit trail records it, before and after. */
    private function rosterSnapshot(HrPoshCase $case): array
    {
        return HrPoshCaseMember::where('case_id', $case->id)
            ->whereNull('removed_at')
            ->get()
            ->map(fn (HrPoshCaseMember $m) => [
                'user_id' => (int) $m->user_id, 'role_key' => $m->role_key,
            ])->values()->all();
    }
}
