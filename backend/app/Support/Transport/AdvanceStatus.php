<?php

namespace App\Support\Transport;

/**
 * Trip advance lifecycle (SNG-TRN-011).
 *
 * ── WHICH DOCUMENT WINS, AND WHY IT IS NOT THE USUAL ANSWER ───────────────
 * Step 9 is the highest product authority, and TripStatus follows it without
 * argument because Step 11's twelve trip states are a strict subset of Step 9's
 * sixteen — both are satisfied by one list. Advances are not like that. The two
 * documents genuinely diverge:
 *
 *   Step 9   DRAFT → PENDING_APPROVAL → APPROVED → PAID → ADJUSTMENT_PENDING
 *            → ADJUSTED → CLOSED / REJECTED                        (8 states)
 *   ENUM-002 requested | approved | rejected | paid | adjusted | recovery
 *                                                                  (6 values)
 *
 * Only four overlap. Step 9 has `draft`, `pending_approval`,
 * `adjustment_pending` and `closed` with no ENUM-002 value; ENUM-002 has
 * `requested` and `recovery` with no Step 9 state. "Step 9 wins" cannot be
 * applied literally, because the column has to hold something and Step 11 says
 * what: FLD-012 specifies `trip_advances.status VARCHAR(40) NOT NULL DEFAULT
 * 'requested'`, and `requested` is an ENUM-002 value that Step 9 does not
 * contain.
 *
 * So the stored vocabulary is ENUM-002, on the strength of the field registry
 * naming its own default, and Step 9's lifecycle is what that vocabulary is
 * understood to mean. Recorded as D-57 rather than reconciled here, because
 * choosing which of the four unmapped Step 9 states to drop is a product
 * decision and inventing one is FORBID-001.
 *
 * ── DECLARED IS NOT WIRED ─────────────────────────────────────────────────
 * Same discipline as TripStatus. All six values are declared so the vocabulary
 * is fixed before anything writes the column; an edge appears in TRANSITIONS
 * only once the ticket owning its preconditions exists. Declaring an unguarded
 * edge would let a caller mark an advance `paid` with none of the settlement
 * checks that state exists to represent.
 *
 *   SNG-TRN-011  requested → approved                       PERM-007
 *                requested → rejected                       PERM-007
 *
 * Still dead, and owned elsewhere:
 *   approved → paid            payment execution, BR-P0-006
 *   paid → adjusted|recovery   SNG-TRN-017 settlement, DEP-008
 *
 * Transport-owned. Stored on trip_advances.status as a plain string.
 */
final class AdvanceStatus
{
    /** ENUM-002, verbatim and in registry order. */
    public const REQUESTED = 'requested';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const PAID      = 'paid';
    public const ADJUSTED  = 'adjusted';
    public const RECOVERY  = 'recovery';

    public const ALL = [
        self::REQUESTED, self::APPROVED, self::REJECTED,
        self::PAID, self::ADJUSTED, self::RECOVERY,
    ];

    /** FLD-012 names this as the column default. */
    public const INITIAL = self::REQUESTED;

    /**
     * Edges this ticket has built the preconditions for.
     *
     * A state absent as a key is not "terminal" — it is unbuilt. `approved` has
     * no outgoing edge here because paying an advance is BR-P0-006's concern,
     * not this ticket's.
     */
    public const TRANSITIONS = [
        self::REQUESTED => [self::APPROVED, self::REJECTED],
    ];

    /**
     * States after which the money is committed.
     *
     * BR-P0-005 counts exposure against these: an advance that has been approved
     * but not yet paid is still exposure, because approving it a second time is
     * how a driver is funded twice for one trip.
     */
    public const COMMITTED = [self::APPROVED, self::PAID, self::ADJUSTED, self::RECOVERY];

    /** Nothing further will be spent on a rejected request. */
    public const TERMINAL = [self::REJECTED];

    public const LABELS = [
        self::REQUESTED => 'Requested',
        self::APPROVED  => 'Approved',
        self::REJECTED  => 'Rejected',
        self::PAID      => 'Paid',
        self::ADJUSTED  => 'Adjusted',
        self::RECOVERY  => 'Recovery',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Does this status still hold money against the trip's exposure? */
    public static function isCommitted(string $status): bool
    {
        return in_array($status, self::COMMITTED, true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
