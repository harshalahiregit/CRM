<?php

namespace App\Support\Purchase;

/**
 * Single source of truth for the RFQ (Request for Quotation) lifecycle.
 *
 * Draft (building + choosing vendors) → Sent (issued, awaiting quotes) →
 * Under_Review (≥1 quotation in, comparing) → Awarded (a quote won, PO created),
 * with Cancelled as the abandon path.
 *
 * "Compared" is intentionally NOT a status — comparison is a read-only action
 * over the received quotations, it doesn't mutate the RFQ. Persisting it would
 * only drift from reality; the RFQ stays Under_Review until it is awarded.
 *
 * Stored on purchase_rfqs.status as a plain string. Mirrors PurchaseRequestStatus.
 */
final class PurchaseRfqStatus
{
    public const DRAFT        = 'Draft';
    public const SENT         = 'Sent';
    public const UNDER_REVIEW = 'Under_Review';
    public const AWARDED      = 'Awarded';
    public const CANCELLED    = 'Cancelled';

    public const ALL = [self::DRAFT, self::SENT, self::UNDER_REVIEW, self::AWARDED, self::CANCELLED];

    /** Line items / vendor list may still be edited only while Draft. */
    public const EDITABLE = [self::DRAFT];

    /** Quotes may be recorded against these — the RFQ is live with vendors. */
    public const OPEN_FOR_QUOTES = [self::SENT, self::UNDER_REVIEW];

    /** Nothing further happens without a reopen. */
    public const CLOSED = [self::AWARDED, self::CANCELLED];

    public const LABELS = [
        self::DRAFT        => 'Draft',
        self::SENT         => 'Sent to Vendors',
        self::UNDER_REVIEW => 'Under Review',
        self::AWARDED      => 'Awarded',
        self::CANCELLED    => 'Cancelled',
    ];

    /**
     * Permitted moves. Anything not listed is refused by the service, so a caller
     * cannot post an RFQ straight from Draft to Awarded without quotes.
     */
    public const TRANSITIONS = [
        self::DRAFT        => [self::SENT, self::CANCELLED],
        self::SENT         => [self::UNDER_REVIEW, self::CANCELLED],
        self::UNDER_REVIEW => [self::AWARDED, self::CANCELLED],
        self::AWARDED      => [],
        self::CANCELLED    => [self::DRAFT],   // reopen an abandoned RFQ
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

    public static function isOpenForQuotes(?string $s): bool
    {
        return in_array($s, self::OPEN_FOR_QUOTES, true);
    }

    public static function canTransition(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
