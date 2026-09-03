<?php

namespace App\Services\Hr;

use App\Models\Hr\HrAdvance;
use App\Models\Hr\HrEmployee;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Support\Hr\AdvanceStage;
use App\Support\Hr\HrSetting;

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
     * The fallback when a workspace has not set it. Settings win: this is now
     * hr.advance_require_distinct_approvers, so a company with fewer approvers
     * than tiers can relax it without a deploy — which is the decision it always
     * should have been.
     */
    public const REQUIRE_DISTINCT_APPROVERS = true;

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * How high the ladder actually goes for a given amount.
     *
     * SangoeTrack's tiers were fixed in code, so a ₹500 advance took the same
     * three signatures as a ₹5,00,000 one. Two thresholds change that:
     * at or below the manager limit only the manager is needed; at or below the
     * accounts limit a director is not. Zero — the default — means no shortcut,
     * so behaviour is unchanged until somebody sets them.
     */
    public function ladderFor(HrAdvance $advance): array
    {
        $amount = $advance->effectiveAmount();
        $s      = $this->settings->getGroup((int) $advance->tenant_id, HrSetting::GROUP);

        $managerLimit  = (float) ($s['advance_manager_limit'] ?? 0);
        $accountsLimit = (float) ($s['advance_accounts_limit'] ?? 0);

        if ($managerLimit > 0 && $amount <= $managerLimit) {
            return [AdvanceStage::MANAGER];
        }

        if ($accountsLimit > 0 && $amount <= $accountsLimit) {
            return [AdvanceStage::MANAGER, AdvanceStage::ACCOUNTS];
        }

        return AdvanceStage::LADDER;
    }

    /**
     * The tier whose turn it is, honouring the thresholds.
     *
     * Once the ladder for this amount is exhausted the request is finished —
     * which is how a small advance skips straight to ready-to-disburse instead
     * of waiting on rungs its amount does not require.
     */
    public function nextTierFor(HrAdvance $advance): ?string
    {
        $next = AdvanceStage::nextTier($advance->isOnHold() ? (string) $advance->held_from : (string) $advance->status);

        if ($next === null) {
            return null;
        }

        return in_array($next, $this->ladderFor($advance), true) ? $next : null;
    }

    /** Whether this tier is the last one this amount requires. */
    public function isFinalTier(HrAdvance $advance, string $tier): bool
    {
        $ladder = $this->ladderFor($advance);

        return $tier === end($ladder);
    }

    private function requiresDistinctApprovers(HrAdvance $advance): bool
    {
        $value = $this->settings->get(
            (int) $advance->tenant_id,
            HrSetting::GROUP,
            'advance_require_distinct_approvers',
            self::REQUIRE_DISTINCT_APPROVERS
        );

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

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
        $tier = $this->nextTierFor($advance);

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
        if ($this->requiresDistinctApprovers($advance) && $this->alreadyApproved($advance, $actor)) {
            return 'You have already approved this advance at an earlier stage. Somebody else has to approve it here.';
        }

        return null;
    }

    public function may(HrAdvance $advance, User $actor): bool
    {
        return $this->refusalReason($advance, $actor) === null;
    }

    /**
     * Narrow a queue to what this person has any business seeing.
     *
     * An advance says what somebody is doing and how much they needed, so the
     * whole tenant's requests are not everybody's reading. Admins, HR, accounts
     * and directors oversee the process and see all of it; a line manager sees
     * their own reports and nothing else.
     */
    public function scopeQueue($query, User $actor)
    {
        if ($actor->isAdmin() || $actor->canManageHrQueue() || $this->holdsAnyTierRole($actor)) {
            return $query;
        }

        $me = HrEmployee::where('tenant_id', $actor->tenant_id)->where('user_id', $actor->id)->first();

        if (! $me) {
            // Reachable only if the gate let somebody through who has no employee
            // record at all. Nothing is the safe answer, not everything.
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'employee_id',
            HrEmployee::where('tenant_id', $actor->tenant_id)
                ->where('reporting_manager_id', $me->id)
                ->select('id')
        );
    }

    public function holdsAnyTierRole(User $actor): bool
    {
        foreach (self::TIER_ROLES as $roles) {
            if (in_array((string) $actor->internal_role, $roles, true)) {
                return true;
            }
        }

        return false;
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
