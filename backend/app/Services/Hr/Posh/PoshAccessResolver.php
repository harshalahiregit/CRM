<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\User;

/**
 * Whether one person may read one POSH case. That is the whole of it.
 *
 * The answer is an active row in hr_posh_case_members, and nothing else. Not a
 * permission, not a data scope, not the HR queue, not committee membership,
 * and not being an administrator.
 *
 * WHY EVERY ONE OF THOSE IS EXCLUDED, since each looks reasonable on its own:
 *
 *   A complaint may name any of those people. The administrator, the HR
 *   executive who runs the queue, the head of the complainant's department,
 *   the committee member who has not been put on this case — each is a
 *   plausible respondent, and each would be able to read the file about
 *   themselves. There is no version of this where a general-purpose authority
 *   is safe, so none is consulted.
 *
 *   ScopeResolver is the sharpest example. Department scope would show a case
 *   to the complainant's own department head, who is a common respondent. The
 *   mechanism that protects every other HR record is actively wrong here.
 *
 *   StaffPermissionService carries an administrator bypass by design
 *   (BYPASS_ROLES). That is right for editing a master and would be exactly
 *   wrong for reading a harassment complaint.
 *
 * So this file imports none of them, and a test asserts their absence by name.
 * The absence is the design, not an oversight — the same way the decision-round
 * primitive asserts it has no step cursor.
 *
 * DELIBERATELY NARROW. It answers one question and holds no case business
 * logic. Anything that starts "while we are here, it could also…" belongs
 * somewhere else, because every extra responsibility here is another place a
 * bypass could be introduced.
 */
class PoshAccessResolver
{
    /**
     * Is this person on the case right now?
     *
     * Tenancy first: a case belongs to one workspace, and a membership row
     * pointing across a tenant boundary is evidence of nothing.
     */
    public function isMember(User $actor, HrPoshCase $case): bool
    {
        if ((int) $case->tenant_id !== (int) $actor->tenant_id) {
            return false;
        }

        return HrPoshCaseMember::where('case_id', $case->id)
            ->where('tenant_id', $case->tenant_id)
            ->where('user_id', $actor->id)
            ->whereNull('removed_at')
            ->exists();
    }

    /**
     * Refuse anybody who is not.
     *
     * 404, and the same 404 every other failure produces — see find().
     */
    public function assertMember(User $actor, HrPoshCase $case): void
    {
        if (! $this->isMember($actor, $case)) {
            $this->refuse();
        }
    }

    /**
     * The one door. Every protected case surface comes through here.
     *
     * FOUR DIFFERENT FAILURES, ONE ANSWER. A case that does not exist, one in
     * another workspace, one that was deleted, and one this person is simply
     * not on all return an identical 404 with an identical message. Anything
     * that distinguished them would be a way to ask "does case 41 exist" and
     * get a truthful answer, which is the first thing somebody probing would
     * try — and on this data, confirming a case exists is itself a disclosure.
     *
     * The message names no id, no reference, no committee and no membership
     * state for the same reason.
     */
    public function find(int $tenantId, int $caseId, User $actor): HrPoshCase
    {
        if ((int) $tenantId !== (int) $actor->tenant_id) {
            $this->refuse();
        }

        // Soft-deleted cases are excluded by the model's default scope, so a
        // deleted case is already indistinguishable from one that never was.
        $case = HrPoshCase::where('tenant_id', $tenantId)->find($caseId);

        if (! $case) {
            $this->refuse();
        }

        $this->assertMember($actor, $case);

        return $case;
    }

    /** Who may read this case right now. */
    public function activeMemberIds(HrPoshCase $case): array
    {
        return HrPoshCaseMember::where('case_id', $case->id)
            ->where('tenant_id', $case->tenant_id)
            ->whereNull('removed_at')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * The single refusal, written once.
     *
     * One place, so the four failure paths above cannot drift into four
     * different messages as the file is edited.
     */
    private function refuse(): never
    {
        throw new BusinessException('Case not found', 404);
    }
}
