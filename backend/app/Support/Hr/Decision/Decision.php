<?php

namespace App\Support\Hr\Decision;

/**
 * The vocabulary of a decision round. One file, deliberately.
 *
 * A round asks a FIXED SET of people one question at the same time and works
 * out when the set has answered. That is the whole of it. It is not an approval
 * ladder: there is no order, no next step, no approver types, no conditions and
 * no configuration of who is asked — the caller supplies the roster and the
 * round freezes it.
 *
 * ApprovalEngine answers a different question — "whose turn is it" — and stays
 * where it is. Nothing here replaces or extends it, and the two share no code.
 *
 * STATE is where the round is. OUTCOME is what it concluded, and exists only
 * once the state is DECIDED. They are separate because a round can be alive and
 * unable to conclude: QUORUM_UNREACHABLE is not a verdict, it is a round
 * waiting for somebody to change the roster or the quorum.
 */
final class Decision
{
    /* ── how the set is evaluated ─────────────────────────────────────── */

    /** Every required participant must approve. */
    public const MODE_ALL_OF = 'all_of';

    /** N of the required participants must approve. */
    public const MODE_QUORUM = 'quorum';

    public const MODES = [self::MODE_ALL_OF, self::MODE_QUORUM];

    /* ── where the round is ───────────────────────────────────────────── */

    public const STATE_OPEN = 'open';

    /**
     * Alive, but arithmetically unable to conclude.
     *
     * Reached when recusals leave fewer required participants than the quorum
     * needs — or, under all_of, when every required participant has recused.
     * The quorum is NEVER reduced to fit: that would silently rewrite the rule
     * a workspace configured. Somebody has to change the roster or the quorum,
     * and that supersedes the round rather than editing it.
     */
    public const STATE_QUORUM_UNREACHABLE = 'quorum_unreachable';

    public const STATE_DECIDED = 'decided';

    /** Replaced by a later round after a roster or quorum change. */
    public const STATE_SUPERSEDED = 'superseded';

    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_OPEN, self::STATE_QUORUM_UNREACHABLE,
        self::STATE_DECIDED, self::STATE_SUPERSEDED, self::STATE_CANCELLED,
    ];

    /** A round that can still receive decisions. */
    public const LIVE = [self::STATE_OPEN, self::STATE_QUORUM_UNREACHABLE];

    /* ── what it concluded ────────────────────────────────────────────── */

    public const OUTCOME_APPROVED = 'approved';
    public const OUTCOME_REJECTED = 'rejected';

    /**
     * The set answered and did not agree.
     *
     * A tie lands here rather than being broken by anybody — a presiding
     * officer casting a deciding vote is a rule nobody has authorised, and
     * inventing one would be worse than reporting that the set was split.
     */
    public const OUTCOME_INCONCLUSIVE = 'inconclusive';

    public const OUTCOMES = [
        self::OUTCOME_APPROVED, self::OUTCOME_REJECTED, self::OUTCOME_INCONCLUSIVE,
    ];

    /* ── what one participant said ────────────────────────────────────── */

    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** Took part, and supported neither answer. Counts as having decided. */
    public const ABSTAINED = 'abstained';

    /**
     * Stood down — a conflict of interest, or no longer eligible.
     *
     * The one decision that changes the arithmetic: a recused participant
     * leaves the denominator entirely, so the rest of the set decides among
     * themselves. A recusal is always accepted, even when accepting it makes
     * the quorum unreachable. Refusing it would force somebody with a conflict
     * to either vote or stall the case in silence.
     */
    public const RECUSED = 'recused';

    public const DECISIONS = [self::APPROVED, self::REJECTED, self::ABSTAINED, self::RECUSED];

    /** Decisions that leave the participant counted in the denominator. */
    public const COUNTED = [self::APPROVED, self::REJECTED, self::ABSTAINED];

    public static function isMode(?string $v): bool
    {
        return $v !== null && in_array($v, self::MODES, true);
    }

    public static function isDecision(?string $v): bool
    {
        return $v !== null && in_array($v, self::DECISIONS, true);
    }
}
