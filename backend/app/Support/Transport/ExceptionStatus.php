<?php

namespace App\Support\Transport;

/**
 * The lifecycle of one operational exception.
 *
 * ── SIX DOCUMENTS, SIX LIFECYCLES, NO TWO ALIKE (D-29) ───────────────────
 * The package describes this lifecycle six times and never the same way:
 *
 *   Step 9         OPEN → ACKNOWLEDGED → IN_PROGRESS → MITIGATION_PLANNED
 *                       → RESOLVED → VERIFIED / CLOSED               (7)
 *   Step 11 SM-EXC open (initial) · acknowledged (active) · resolved (terminal)
 *                                                                    (3, LOCKED)
 *   Step 11 ENUM-004  open|acknowledged|in_progress|resolved|closed  (5)
 *   Step 5         open → acknowledged → mitigated → resolved   (adds `mitigated`)
 *   FRS TRP-P0-012 "Open→acknowledged→resolved/waived"          (adds `waived`)
 *   LSM §74        DETECTED → CLASSIFIED → PRIORITIZED → ASSIGNED → NOTIFIED
 *                       → ACTION_IN_PROGRESS → RESOLVED → VERIFIED → CLOSED,
 *                       and OVERDUE → ESCALATED when unresolved      (11)
 *
 * None is a superset of the others, and Step 11 contradicts ITSELF: SM-EXC has
 * three states where ENUM-004 has five, and SM-EXC calls `resolved` terminal
 * while ENUM-004 places `closed` after it. Nine distinct state tokens across the
 * package — a worse divergence than the Trip machine's.
 *
 * Step 9 governs because it is the authority tier and because its list contains
 * every token any registry LOCKS. Two vocabularies are deliberately NOT merged
 * in: LSM §74's DETECTED/CLASSIFIED/PRIORITIZED/ASSIGNED/NOTIFIED describe the
 * detection pipeline rather than the record's state, and its OVERDUE/ESCALATED
 * are SLA facts, which this module derives rather than stores (see
 * ExceptionSlaState). Step 5's `mitigated` is Step 9's `mitigation_planned`
 * under another name, and Step 9 wins on authority.
 *
 * Owner's ruling, 2026-09-10 (Q1 and Q2): declare Step 9's seven, plus `waived`,
 * and wire only the two transitions Step 11 LOCKS. Step 9 is the authority tier;
 * declaring its full list now fixes the vocabulary before the tickets that need
 * it, without letting anything reach a state this ticket cannot honour. Exactly
 * the pattern tickets 009 and 010 already follow.
 *
 * ── WHAT IS REACHABLE ────────────────────────────────────────────────────
 * Three of eight: open, acknowledged, resolved — SM-EXC's three, joined by the
 * two LOCKED transitions:
 *
 *   STT-015  open → acknowledged   | "Assign owner"      | SLA timer starts
 *   STT-016  acknowledged → resolved | "Resolve exception" | SLA stopped
 *
 * The other five are declared and unreachable, each for a stated reason. That
 * is not tidiness: a status column whose vocabulary is smaller than the
 * documents' is how two systems end up disagreeing about what "closed" means.
 *
 * ── OPS §154 ─────────────────────────────────────────────────────────────
 * "An exception must interrupt the workflow only when necessary, but it must
 * never disappear from the system." So there is no `deleted` and no `cancelled`
 * here, and TripException does not soft-delete.
 */
final class ExceptionStatus
{
    /** Raised, nobody has taken it. SM-EXC initial; FLD-015's default. */
    public const OPEN = 'open';

    /** An owner has accepted it. SM-EXC active. STT-015's destination. */
    public const ACKNOWLEDGED = 'acknowledged';

    /** Work has started. Step 9 and ENUM-004; no LOCKED transition reaches it. */
    public const IN_PROGRESS = 'in_progress';

    /** Step 9 only. A plan exists but the root action is not done. */
    public const MITIGATION_PLANNED = 'mitigation_planned';

    /** Root action completed. SM-EXC terminal. STT-016's destination. */
    public const RESOLVED = 'resolved';

    /** Step 9 only. Someone has checked the resolution actually held. */
    public const VERIFIED = 'verified';

    /** Step 9 and ENUM-004. Step 9 marks it the machine's terminal state. */
    public const CLOSED = 'closed';

    /**
     * FRS TRP-P0-012 only, and BR-P0-011's named override ("Owner waiver").
     *
     * ── NOT IN THE VOCABULARY. Ruled 2026-09-18 — D-30 ───────────────────
     * It used to be in ALL, under the owner's Q2 ruling of 2026-09-10
     * ("waived declared in the enum, not wired"). The standing Step 9 / Step 11
     * rule in TEAM-CONTRACTS supersedes that, and cleanly: the vocabulary comes
     * from Step 9, and `waived` is the one status in this file Step 9 does not
     * contain. It appears in FRS TRP-P0-012 and BR-P0-011 alone.
     *
     * So unlike in_progress, mitigation_planned, verified and closed — which
     * ARE Step 9's, and therefore stay declared and unreachable — this one is
     * out of ALL until it has an edge and a gate.
     *
     * The CONSTANT stays, because the behaviour is SPECIFIED and we are choosing
     * not to build it yet. That is a different thing from an override nobody
     * defined, and it is the same distinction BR-P0-017's waiver carries on the
     * closure side: a refusal says the waiver is not built YET, never that no
     * waiver exists. See ExceptionScope::WAIVER_DEFERRED.
     */
    public const WAIVED = 'waived';

    /** Step 9's seven, in Step 9's order, plus FRS's waived. Q1 + Q2. */
    public const ALL = [
        self::OPEN,
        self::ACKNOWLEDGED,
        self::IN_PROGRESS,
        self::MITIGATION_PLANNED,
        self::RESOLVED,
        self::VERIFIED,
        self::CLOSED,
    ];

    /** Step 9's list. Identical to ALL since D-30 — kept because a test that
     *  asserts the two match is what stops them drifting apart again. */
    public const STEP_9 = [
        self::OPEN, self::ACKNOWLEDGED, self::IN_PROGRESS,
        self::MITIGATION_PLANNED, self::RESOLVED, self::VERIFIED, self::CLOSED,
    ];

    /** Step 11 SM-EXC's three. The only states with LOCKED rows. */
    public const SM_EXC = [self::OPEN, self::ACKNOWLEDGED, self::RESOLVED];

    /** Step 11 ENUM-004's five, recorded rather than discarded. */
    public const ENUM_004 = [
        self::OPEN, self::ACKNOWLEDGED, self::IN_PROGRESS, self::RESOLVED, self::CLOSED,
    ];

    /** What this ticket can actually produce. */
    public const REACHABLE = [self::OPEN, self::ACKNOWLEDGED, self::RESOLVED];

    /** Declared, and why nothing reaches them. */
    public const UNREACHABLE = [
        self::IN_PROGRESS        => 'Step 9 and ENUM-004 both have it, but Step 11 LOCKS no transition into it. acknowledged → resolved (STT-016) is the only route onward.',
        self::MITIGATION_PLANNED => 'Step 9 only. A mitigation plan is an entity with an owner and a date that no document models.',
        self::VERIFIED           => 'Step 9 only. Verification is STOS-REQ-QC-007 ("Verify CAPA effectiveness", P1) and belongs to the Quality domain, which has no ticket.',
        self::CLOSED             => 'Step 9 marks it terminal and ENUM-004 lists it, but no transition reaches it and no document says what closing adds beyond resolving.',
            // NOT listed: `waived` is not in the vocabulary at all (D-30), so
            // it is not an unreachable member of one. Its deferral is recorded
            // on the constant above.
    ];

    /**
     * The two LOCKED transitions, and nothing else.
     *
     * A state absent from this map is terminal by construction, which is how
     * `resolved` stays terminal without a second list saying so.
     */
    public const TRANSITIONS = [
        self::OPEN         => [self::ACKNOWLEDGED],   // STT-015
        self::ACKNOWLEDGED => [self::RESOLVED],       // STT-016
    ];

    /**
     * Still needing someone's attention. Drives the control-room filters.
     *
     * in_progress and mitigation_planned are here even though nothing reaches
     * them: they describe work still under way, and a future ticket that wires
     * them must not have to remember to reclassify them. Leaving them out of
     * both lists is how an exception ends up counted as neither open nor
     * finished — OPS §154's "must never disappear", by arithmetic.
     */
    public const ACTIVE = [
        self::OPEN, self::ACKNOWLEDGED, self::IN_PROGRESS, self::MITIGATION_PLANNED,
    ];

    /** Nothing further is expected. The SLA clock stops here. */
    public const TERMINAL = [self::RESOLVED, self::VERIFIED, self::CLOSED];

    public const LABELS = [
        self::OPEN               => 'Open',
        self::ACKNOWLEDGED       => 'Acknowledged',
        self::IN_PROGRESS        => 'In progress',
        self::MITIGATION_PLANNED => 'Mitigation planned',
        self::RESOLVED           => 'Resolved',
        self::VERIFIED           => 'Verified',
        self::CLOSED             => 'Closed',
        self::WAIVED             => 'Waived',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** True while the exception still counts against its trip. */
    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    /** True once the SLA clock should stop. */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
