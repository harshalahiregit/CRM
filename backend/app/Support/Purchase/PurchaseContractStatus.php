<?php

namespace App\Support\Purchase;

/**
 * Purchase contract (MSA / rate contract) lifecycle.
 *
 * Draft → Under_Review → Active → Expired / Terminated.
 * - Active: approved and within its date window; the rate card and dates FREEZE
 *   (a contract whose locked prices could still be edited isn't a contract).
 * - Expired: reached by time (past end_date) — terminal.
 * - Terminated: ended early by an admin, with a reason — terminal.
 *
 * Stored on purchase_contracts.status as a plain string. Mirrors the other
 * Purchase status enums.
 */
final class PurchaseContractStatus
{
    public const DRAFT        = 'Draft';
    public const UNDER_REVIEW = 'Under_Review';
    public const ACTIVE       = 'Active';
    public const EXPIRED      = 'Expired';
    public const TERMINATED   = 'Terminated';

    public const ALL = [self::DRAFT, self::UNDER_REVIEW, self::ACTIVE, self::EXPIRED, self::TERMINATED];

    /** Rates / dates / items may only change while in these states. */
    public const EDITABLE = [self::DRAFT, self::UNDER_REVIEW];

    /** A PO may reference a contract only while it is genuinely in force. */
    public const REFERENCEABLE = [self::ACTIVE];

    /** No further transitions. */
    public const CLOSED = [self::EXPIRED, self::TERMINATED];

    public const LABELS = [
        self::DRAFT        => 'Draft',
        self::UNDER_REVIEW => 'Under Review',
        self::ACTIVE       => 'Active',
        self::EXPIRED      => 'Expired',
        self::TERMINATED   => 'Terminated',
    ];

    /**
     * Permitted moves. Anything not listed is refused by the service, so a caller
     * cannot post a Draft straight to Active without going through review.
     */
    public const TRANSITIONS = [
        self::DRAFT        => [self::UNDER_REVIEW, self::TERMINATED],
        self::UNDER_REVIEW => [self::ACTIVE, self::DRAFT, self::TERMINATED],
        self::ACTIVE       => [self::EXPIRED, self::TERMINATED],
        self::EXPIRED      => [],
        self::TERMINATED   => [],
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

    public static function isReferenceable(?string $s): bool
    {
        return in_array($s, self::REFERENCEABLE, true);
    }

    public static function canTransition(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
