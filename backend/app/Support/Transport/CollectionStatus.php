<?php

namespace App\Support\Transport;

/**
 * Where a trip's receivable has got to.  SNG-TRN-016.  D-61.
 *
 * ── CONSTRUCTED ─────────────────────────────────────────────────────────
 * IDX-009 is `INDEX (company_id, due_date, status)` for "collections ageing",
 * so the registry plainly expects a `status` column — and then never says what
 * may go in it. None of the eight registered enums describes a collection, and
 * the four state machines are SM-ADV, SM-EXC, SM-ORD and SM-TRP.
 *
 * FLD-017's rule reference `COL-001` resolves through TRC-007 to `BR-COL-001`
 * and `FRS-COL-001` — business RULE documents in the reference-only tier, which
 * define no vocabulary. So this is a narrower gap than CST-001's dangling
 * pointer, and it still has to be filled by hand. Recorded as D-61.
 *
 * ── THREE STATES, AND THEY ARE ABOUT MONEY ──────────────────────────────
 * Only the amount decides the status. Whether somebody is chasing it, and
 * whether it is stuck, are recorded in `next_follow_up_on` and
 * `blocker_reason` — NOT here.
 *
 * That separation is deliberate and it is the useful part. A "blocked" status
 * would hide how much is owed, and a receivable can perfectly well be part-paid
 * and blocked at the same time — which is precisely the row somebody has to
 * chase. Collapsing the two axes into one column would make that case
 * unrepresentable, and it is the case the ticket exists for.
 *
 * ── DERIVED, NOT SET ────────────────────────────────────────────────────
 * The status is computed from amount_due and amount_received rather than
 * assigned by a caller. A receivable whose status disagrees with its arithmetic
 * is worse than one with no status at all, and TripCollectionService::recompute()
 * is the only thing that writes it.
 */
final class CollectionStatus
{
    /** Nothing received yet. */
    public const PENDING = 'pending';

    /** Some received, a balance remains. */
    public const PART_PAID = 'part_paid';

    /** Received in full. Terminal for this module. */
    public const SETTLED = 'settled';

    /** @var list<string> */
    public const ALL = [self::PENDING, self::PART_PAID, self::SETTLED];

    public const INITIAL = self::PENDING;

    /** Statuses that still owe money — what the ageing report reads. */
    public const OUTSTANDING = [self::PENDING, self::PART_PAID];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /**
     * The status this receivable's arithmetic implies.
     *
     * bccomp, never `<=`. These are decimal strings and a float comparison here
     * would call a fully-paid receivable part-paid at the edges — FIN-06.
     */
    public static function fromAmounts(string $due, string $received): string
    {
        if (bccomp($received, '0.00', 2) <= 0) {
            return self::PENDING;
        }

        return bccomp($received, $due, 2) >= 0 ? self::SETTLED : self::PART_PAID;
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING   => 'Outstanding',
            self::PART_PAID => 'Part paid',
            self::SETTLED   => 'Settled',
            default         => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
