<?php

namespace App\Support\Hr;

use App\Models\Hr\HrLeavePolicyType;
use App\Models\Hr\HrLeaveType;

/**
 * How many days of a leave type actually carry into the next period.
 *
 * ONE PLACE, because there are two configured ceilings and the answer has to
 * be the same wherever it is asked. Both are already shown to the user as
 * maxima, which is what settles the arithmetic rather than a guess:
 *
 *   hr_leave_types.carry_forward      does this type carry forward at all
 *   hr_leave_types.max_carry_forward  the type's own ceiling — the Leave Types
 *                                     screen prints "≤ N" beside it, and "—"
 *                                     when the boolean is off
 *   hr_leave_policy_types.carry_forward_limit
 *                                     the policy's ceiling for this type,
 *                                     edited as "CF≤" in the policy builder
 *
 * THE BOOLEAN IS A GATE, NOT A HINT. A type marked "does not carry forward"
 * carries nothing, whatever a policy says — the screen has been printing "—"
 * for those types while the engine carried them anyway, which is the
 * disagreement this class exists to end.
 *
 * TWO CEILINGS MEANS THE LOWER ONE. Both read "at most N", so a type capped at
 * five days cannot carry ten because a policy is more generous; the tighter
 * constraint is the one that binds. That is the arithmetic of two simultaneous
 * maxima, not a new rule.
 *
 * And nothing carries forward that the employee did not actually have: the
 * final figure is bounded by the balance they finished the period with.
 */
final class LeaveCarryForward
{
    /**
     * The ceiling for this type under this policy, before the employee's own
     * balance is considered.
     *
     * Returns 0.0 when the type does not carry forward at all.
     */
    public static function cap(?HrLeaveType $type, ?HrLeavePolicyType $policyType): float
    {
        // No type record means nothing can be said about its rules, and
        // inventing a ceiling for it would be worse than declining to.
        if (! $type || ! $type->carry_forward) {
            return 0.0;
        }

        $caps = [(float) $type->max_carry_forward];

        if ($policyType) {
            $caps[] = (float) $policyType->carry_forward_limit;
        }

        return max(0.0, min($caps));
    }

    /**
     * What actually carries, given what the employee finished with.
     *
     * $available is the prior period's remaining balance. A negative one — a
     * workspace that allows going overdrawn — carries nothing rather than
     * carrying a debt into the new year.
     */
    public static function forBalance(?HrLeaveType $type, ?HrLeavePolicyType $policyType, float $available): float
    {
        return round(min(max(0.0, $available), self::cap($type, $policyType)), 1);
    }
}
