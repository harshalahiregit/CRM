<?php

namespace App\Support\Purchase;

/**
 * A vendor's state on an RFQ's recipient list.
 *
 * Invited (RFQ sent to them) → Responded (a quotation was recorded) / Declined
 * (they opted out). Kept as its own small vocabulary so the recipient grid can
 * show who has and hasn't come back.
 */
final class RfqVendorStatus
{
    public const INVITED   = 'Invited';
    public const RESPONDED = 'Responded';
    public const DECLINED  = 'Declined';

    public const ALL = [self::INVITED, self::RESPONDED, self::DECLINED];

    public const LABELS = [
        self::INVITED   => 'Invited',
        self::RESPONDED => 'Responded',
        self::DECLINED  => 'Declined',
    ];

    public static function label(?string $s): string
    {
        return self::LABELS[$s] ?? (string) $s;
    }

    public static function isValid(?string $s): bool
    {
        return in_array($s, self::ALL, true);
    }
}
