<?php

namespace App\Support\Transport;

/**
 * Pre-dispatch readiness status — STOS-OPS §29, verbatim.
 *
 * The status of a trip's whole checklist, as opposed to PretripResult, which is
 * the outcome of one item on it.
 *
 * ── DERIVED, NOT STORED ───────────────────────────────────────────────────
 * There is no readiness column on transport_trips. This value is computed from
 * the trip's trip_pretrip_checks rows every time it is asked for, for the same
 * reason DriverComplianceStatus is derived: a stored status is wrong the morning
 * after a certificate lapses unless something swept overnight, and no P0 ticket
 * owns a sweep. A checklist that passed on Monday against a document expiring
 * Tuesday must not still read READY on Wednesday.
 *
 * ── THREE DOCUMENTS, THREE ENUMS (D-16) ───────────────────────────────────
 * The package describes this concept three times and never the same way:
 *
 *   OPS §29   NOT_STARTED | IN_PROGRESS | READY | BLOCKED | OVERRIDE_REQUIRED
 *   CMP §158  READY | READY WITH EXCEPTION | NOT READY | BLOCKED
 *   FLEET §88 PASS | PASS WITH WARNING | FAIL | CRITICAL FAIL
 *
 * Step 11's Enums sheet carries none of them, so there is no canonical answer to
 * defer to — recorded as D-16.
 *
 * FLEET §88 is not really a competitor: it grades one inspection, not a
 * checklist, and it is used here as PretripResult. CMP §158 genuinely does
 * compete. OPS §29 governs, because STOS-OPS owns the dispatch process, because
 * §29 is titled "READINESS STATUS" and this is the readiness status, and because
 * §29 alone distinguishes "nobody has started" from "started and not finished" —
 * a distinction the checklist needs and CMP §158 cannot express.
 *
 * CMP_158_MAP records the correspondence rather than discarding it, so a later
 * compliance report can answer in CMP's vocabulary without a second enum.
 *
 * ── WHAT IS REACHABLE ─────────────────────────────────────────────────────
 * Four of the five. OVERRIDE_REQUIRED is declared and unwired: override is P1 in
 * all three places the package mentions it (CMP-007, BRW-049, PLN-007), and
 * ticket 009 deferred PLN-007 on exactly this reasoning. Declaring it now fixes
 * the vocabulary before the ticket that needs it, without letting anything
 * produce a state this ticket cannot honour.
 */
final class PretripReadiness
{
    /** No checklist rows exist for the trip yet. */
    public const NOT_STARTED = 'not_started';

    /** Rows exist; at least one is still awaiting a result. */
    public const IN_PROGRESS = 'in_progress';

    /** Every generated check has been completed and none of them blocks. */
    public const READY = 'ready';

    /** At least one required check failed. BRW-046, OPS §30. */
    public const BLOCKED = 'blocked';

    /**
     * Blocked, but an authorized user may release it. CMP-007 / BRW-049 / §31.
     * DECLARED, NEVER RETURNED — override is P1. See the class docblock.
     */
    public const OVERRIDE_REQUIRED = 'override_required';

    /** OPS §29 order. */
    public const ALL = [
        self::NOT_STARTED, self::IN_PROGRESS, self::READY,
        self::BLOCKED, self::OVERRIDE_REQUIRED,
    ];

    /** The states this ticket can actually produce. */
    public const REACHABLE = [
        self::NOT_STARTED, self::IN_PROGRESS, self::READY, self::BLOCKED,
    ];

    /** Declared to match §29; nothing in this ticket returns them. */
    public const UNREACHABLE = [self::OVERRIDE_REQUIRED];

    /** The only status that permits allocated → pretrip_ok. */
    public const PERMITS_TRANSITION = [self::READY];

    /**
     * CMP §158's vocabulary, mapped onto §29's.
     *
     * "READY WITH EXCEPTION" has no §29 equivalent, because §29 has no concept of
     * a passed-with-warning checklist — a non-critical failure leaves the
     * checklist READY and carries its warning on the item (BRW-052). That is
     * recorded as the null below rather than smoothed over.
     */
    public const CMP_158_MAP = [
        'READY'                => self::READY,
        'READY WITH EXCEPTION' => null,
        'NOT READY'            => self::IN_PROGRESS,
        'BLOCKED'              => self::BLOCKED,
    ];

    public const LABELS = [
        self::NOT_STARTED       => 'Not started',
        self::IN_PROGRESS       => 'In progress',
        self::READY             => 'Ready',
        self::BLOCKED           => 'Blocked',
        self::OVERRIDE_REQUIRED => 'Override required',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /** BRW-046: dispatch is permitted only from READY. */
    public static function permitsTransition(string $status): bool
    {
        return in_array($status, self::PERMITS_TRANSITION, true);
    }
}
