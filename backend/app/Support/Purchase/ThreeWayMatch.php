<?php

namespace App\Support\Purchase;

/**
 * The 3-way match policy: invoice billed vs PO ordered vs GRN accepted.
 *
 * The control a procure-to-pay system exists to enforce — you should not pay for
 * more than you ordered, nor for goods you never received. Per-line verdicts roll
 * up to an invoice verdict; approval is blocked on an over-billing verdict.
 *
 * Policy (confirmed with the user): block over-billing beyond tolerance, warn on
 * under-billing. Under-billing is a vendor billing you for less than delivered —
 * not your problem to stop, but worth surfacing.
 *
 * Tolerances absorb rounding and legitimate small differences (part-unit pricing,
 * tax rounding) so a ₹0.01 drift doesn't block a real invoice.
 */
final class ThreeWayMatch
{
    /* ── Per-line verdicts ──────────────────────────────────────────── */
    public const MATCHED      = 'Matched';       // within tolerance on qty and price
    public const OVER_BILLED  = 'Over_Billed';   // billed qty/price exceeds ordered or received — BLOCKS
    public const UNDER_BILLED = 'Under_Billed';  // billed less than received — warn only
    public const PRICE_VARIANCE = 'Price_Variance'; // qty ok, rate above PO beyond tolerance — BLOCKS
    public const UNMATCHED    = 'Unmatched';     // no PO line to compare against — warn only

    public const LABELS = [
        self::MATCHED        => 'Matched',
        self::OVER_BILLED    => 'Over-billed',
        self::UNDER_BILLED   => 'Under-billed',
        self::PRICE_VARIANCE => 'Price variance',
        self::UNMATCHED      => 'Unmatched',
    ];

    /** Verdicts that block invoice approval. */
    public const BLOCKING = [self::OVER_BILLED, self::PRICE_VARIANCE];

    /** Verdicts that are fine to approve but worth flagging. */
    public const WARNING = [self::UNDER_BILLED, self::UNMATCHED];

    /* ── Tolerances ─────────────────────────────────────────────────── */
    /** Quantity slack, in absolute units — covers part-unit rounding. */
    public const QTY_TOLERANCE = 0.01;

    /** Price slack, as a fraction of the PO rate (1% = 0.01). */
    public const PRICE_TOLERANCE_PCT = 0.01;

    public static function label(?string $verdict): string
    {
        return self::LABELS[$verdict] ?? (string) $verdict;
    }

    public static function isBlocking(?string $verdict): bool
    {
        return in_array($verdict, self::BLOCKING, true);
    }

    public static function isWarning(?string $verdict): bool
    {
        return in_array($verdict, self::WARNING, true);
    }

    /**
     * The worst verdict wins when rolling lines up to an invoice verdict:
     * one over-billed line makes the whole invoice over-billed.
     */
    public static function rollUp(array $verdicts): string
    {
        foreach ([self::OVER_BILLED, self::PRICE_VARIANCE, self::UNMATCHED, self::UNDER_BILLED] as $rank) {
            if (in_array($rank, $verdicts, true)) {
                return $rank;
            }
        }

        return self::MATCHED;
    }
}
