<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshCaseMember;
use App\Models\Hr\HrPoshCommitteeRole;
use App\Models\User;

/**
 * The second question, asked only after the first one has been answered.
 *
 * PoshAccessResolver answers "may this person see this case". This answers
 * "and may they run it" — open or close an inquiry, publish a finding,
 * withdraw the case. It COMPOSES the resolver rather than replacing it, and
 * deliberately lives in its own class so the resolver stays what it is: one
 * question, no capability vocabulary, nothing that could grow into a bypass.
 *
 * ORDER IS THE WHOLE POINT. Membership is the gate; can_manage_case only
 * narrows within it. A presiding officer who is not on this case has no
 * authority over it, because authority over a case they cannot see is not a
 * coherent thing to hold — and because the complaint may be about them.
 *
 * The flag is read from the committee role the member's role_key names. That
 * key was copied onto the membership by value when the case was created, so a
 * role renamed or deleted afterwards cannot silently promote or demote
 * somebody mid-case; a key that no longer resolves simply grants nothing.
 */
class PoshCaseAuthority
{
    public function __construct(private PoshAccessResolver $access)
    {
    }

    /**
     * May this person run this case?
     *
     * False for anybody who is not an active member, whatever else they hold.
     */
    public function canManageCase(User $actor, HrPoshCase $case): bool
    {
        if (! $this->access->isMember($actor, $case)) {
            return false;
        }

        $roleKey = HrPoshCaseMember::where('case_id', $case->id)
            ->where('tenant_id', $case->tenant_id)
            ->where('user_id', $actor->id)
            ->whereNull('removed_at')
            ->value('role_key');

        if (! $roleKey) {
            return false;
        }

        return HrPoshCommitteeRole::where('tenant_id', $case->tenant_id)
            ->where('committee_id', $case->committee_id)
            ->where('key', $roleKey)
            ->where('is_active', true)
            ->where('can_manage_case', true)
            ->exists();
    }

    /**
     * Refuse anybody who may not.
     *
     * 403, not 404, and the distinction is deliberate. By the time this is
     * reached the case has already been resolved, which means this person IS
     * a member and may legitimately see it — they simply may not take this
     * particular action. Hiding it would tell them the case had vanished.
     *
     * A non-member never gets here: PoshAccessResolver::find() has already
     * answered them with its own 404.
     */
    public function assertCanManageCase(User $actor, HrPoshCase $case): void
    {
        if (! $this->canManageCase($actor, $case)) {
            throw new BusinessException(
                'You are not authorised to manage this case.', 403
            );
        }
    }
}
