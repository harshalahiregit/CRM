<?php

namespace App\Services\Hr;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Support\Hr\AdvanceStage;

/**
 * Who may approve an advance, and at which rung.
 *
 * The ladder is only worth having if the rungs are held by different people, so
 * three rules are enforced here rather than left to convention:
 *
 *   1. ORDER      — only the tier whose turn it is may act. Accounts cannot
 *                   approve something the manager has not seen.
 *   2. NOT YOURSELF — nobody approves their own advance at any tier, however
 *                   senior. This one has no exception.
 *   3. NOT TWICE  — whoever approved at one rung cannot also approve at the
 *                   next. Without this a single admin clicks three times and the
 *                   three-tier ladder is decoration.
 *
 * Rule 3 can deadlock a tenant that has fewer real approvers than tiers, which
 * is a policy question rather than a technical one — see REQUIRE_DISTINCT_APPROVERS.
 *
 * Tier membership: the manager rung is the employee's OWN reporting manager,
 * because "my manager approved it" means nothing if it can be any manager. The
 * accounts and director rungs go by internal_role. An admin can stand in on any
 * rung — rules 2 and 3 still apply to them — so a tenant that has not filled in
 * its roles yet is slowed down rather than stopped.
 */
class AdvanceTierService
{
    /**
     * Whether the same person is barred from approving at two rungs.
     *
     * True is the correct default: it is the whole point of separating tiers.
     * A small tenant with one approver will need this relaxed, and that is a
     * decision for whoever runs the company, not for this file.
     */
    public const REQUIRE_DISTINCT_APPROVERS = true;

    /** internal_role values that hold each rung, beyond an admin standing in. */
    public const TIER_ROLES = [
        AdvanceStage::ACCOUNTS => ['accounts', 'accountant', 'finance'],
        AdvanceStage::DIRECTOR => ['director', 'md', 'ceo'],
    ];

    /**
     * Why this user may not approve this advance right now, or null if they may.
     *
     * Returns the reason rather than a bare false so the caller can say what is
     * wrong. "You are not authorised" when the real answer is "it is still with
     * the manager" wastes somebody's afternoon.
     */
    public function refusalReason(HrAdvance $advance, User $actor): ?string
    {
        $tier = AdvanceStage::nextTier((string) $advance->status);

        if ($tier === null) {
            return AdvanceStage::isDecided((string) $advance->status)
                ? 'This advance has already been decided.'
                : 'This advance is not waiting on an approval — it is ' . strtolower(AdvanceStage::label((string) $advance->status)) . '.';
        }

        // Rule 2: never your own, at any rung.
        if ($this->isOwnAdvance($advance, $actor)) {
            return 'You cannot approve your own advance.';
        }

        if (! $this->holdsTier($advance, $actor, $tier)) {
            return $this->notOnThisRung($tier);
        }

        // Rule 3: not the same person twice on one advance.
        if (self::REQUIRE_DISTINCT_APPROVERS && $this->alreadyApproved($advance, $actor)) {
            return 'You have already approved this advance at an earlier stage. Somebody else has to approve it here.';
        }

        return null;
    }

    public function may(HrAdvance $advance, User $actor): bool
    {
        return $this->refusalReason($advance, $actor) === null;
    }

    /* ── membership ──────────────────────────────────────────────────── */

    public function holdsTier(HrAdvance $advance, User $actor, string $tier): bool
    {
        // An admin can stand in on any rung. Rules 2 and 3 still bind them.
        if ($actor->isAdmin()) {
            return true;
        }

        if ($tier === AdvanceStage::MANAGER) {
            return $this->isReportingManagerOf($advance, $actor);
        }

        return in_array((string) $actor->internal_role, self::TIER_ROLES[$tier] ?? [], true);
    }

    /**
     * The employee's own manager, resolved through the employee record rather
     * than a name string — reporting_manager_name exists too and is not an
     * identity.
     */
    public function isReportingManagerOf(HrAdvance $advance, User $actor): bool
    {
        $employee = $advance->relationLoaded('employee') ? $advance->employee : $advance->employee()->first();

        if (! $employee || ! $employee->reporting_manager_id) {
            return false;
        }

        $manager = HrEmployee::where('tenant_id', $advance->tenant_id)
            ->whereKey($employee->reporting_manager_id)
            ->first();

        return $manager !== null
            && $manager->user_id !== null
            && (int) $manager->user_id === (int) $actor->id;
    }

    /* ── the two personal rules ──────────────────────────────────────── */

    public function isOwnAdvance(HrAdvance $advance, User $actor): bool
    {
        $employee = $advance->relationLoaded('employee') ? $advance->employee : $advance->employee()->first();

        return $employee !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === (int) $actor->id;
    }

    /**
     * Whether this person already signed off a rung on this advance.
     *
     * Read from the thread, which is the only place each individual approval is
     * recorded — the row keeps just the latest decided_by, so it cannot answer
     * this on its own.
     */
    public function alreadyApproved(HrAdvance $advance, User $actor): bool
    {
        return $advance->messages()
            ->where('kind', 'event')
            ->where('event_type', 'tier_approved')
            ->where('author_id', $actor->id)
            ->exists();
    }

    private function notOnThisRung(string $tier): string
    {
        return [
            AdvanceStage::MANAGER  => 'This is waiting on the employee\'s own reporting manager.',
            AdvanceStage::ACCOUNTS => 'This is waiting on accounts.',
            AdvanceStage::DIRECTOR => 'This is waiting on a director.',
        ][$tier] ?? 'You are not on the tier this advance is waiting for.';
    }
}
