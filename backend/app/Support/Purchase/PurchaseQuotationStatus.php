<?php

namespace App\Support\Purchase;

/**
 * A single vendor's quotation against an RFQ.
 *
 * Draft (being entered) → Received (submitted, in the comparison set) →
 * Shortlisted (a contender) → Awarded (the winner, converted to a PO) /
 * Rejected (not selected). Awarding one quotation rejects its siblings.
 *
 * Stored on purchase_quotations.status as a plain string.
 */
final class PurchaseQuotationStatus
{
    public const DRAFT       = 'Draft';
    public const RECEIVED    = 'Received';
    public const SHORTLISTED = 'Shortlisted';
    public const AWARDED     = 'Awarded';
    public const REJECTED    = 'Rejected';

    public const ALL = [self::DRAFT, self::RECEIVED, self::SHORTLISTED, self::AWARDED, self::REJECTED];

    /** Line items may still be edited while the quote is being captured. */
    public const EDITABLE = [self::DRAFT];

    /** Quotes in the running for the award / comparison. */
    public const IN_CONTENTION = [self::RECEIVED, self::SHORTLISTED];

    public const LABELS = [
        self::DRAFT       => 'Draft',
        self::RECEIVED    => 'Received',
        self::SHORTLISTED => 'Shortlisted',
        self::AWARDED     => 'Awarded',
        self::REJECTED    => 'Rejected',
    ];

    public static function label(?string $s): string
    {
        return self::LABELS[$s] ?? (string) $s;
    }

    public static function isValid(?string $s): bool
    {
        return in_array($s, self::ALL, true);
    }

    public static function isEditable(?string $s): bool
    {
        return in_array($s, self::EDITABLE, true);
    }

    /** Only a submitted, in-contention quote can be awarded. */
    public static function isAwardable(?string $s): bool
    {
        return in_array($s, self::IN_CONTENTION, true);
    }
}
