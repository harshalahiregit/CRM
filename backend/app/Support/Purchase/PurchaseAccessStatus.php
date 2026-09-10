<?php

namespace App\Support\Purchase;

/**
 * Temporary Purchase Vendor access lifecycle states, plus the countdown
 * projection the portal and the admin badge read.
 *
 * Purchase-owned. TPV has its own App\Support\Tpv\TpvAccessStatus with the same
 * shape; neither references the other, which is the module rule. The thresholds
 * and bands match deliberately, so a temporary vendor is warned on the same
 * schedule whichever engine they were registered in — a supplier working under
 * both should not get 7 days' notice from one and none from the other.
 *
 * Stored values are Active / Expired / Converted. "Expiring" is DERIVED from the
 * remaining seconds and never persisted: a stored "Expiring" is wrong the moment
 * the clock moves past it.
 */
final class PurchaseAccessStatus
{
    public const ACTIVE    = 'Active';
    public const EXPIRING  = 'Expiring';
    public const EXPIRED   = 'Expired';
    public const CONVERTED = 'Converted';

    public const ALL = [self::ACTIVE, self::EXPIRING, self::EXPIRED, self::CONVERTED];

    public const LABELS = [
        self::ACTIVE    => 'Active',
        self::EXPIRING  => 'Expiring',
        self::EXPIRED   => 'Expired',
        self::CONVERTED => 'Converted',
    ];

    private const DAY = 86400;

    /** Countdown colour band: green > 3d, orange ≤ 3d, red ≤ 24h, expired ≤ 0. */
    public static function band(int $secondsRemaining): string
    {
        if ($secondsRemaining <= 0) {
            return 'expired';
        }
        if ($secondsRemaining <= self::DAY) {
            return 'red';
        }
        if ($secondsRemaining <= 3 * self::DAY) {
            return 'orange';
        }

        return 'green';
    }

    /** Display status, from the stored value plus what is actually left. */
    public static function derive(?string $stored, int $secondsRemaining): string
    {
        if ($stored === self::CONVERTED) {
            return self::CONVERTED;
        }
        if ($stored === self::EXPIRED || $secondsRemaining <= 0) {
            return self::EXPIRED;
        }
        if ($secondsRemaining <= self::DAY) {
            return self::EXPIRING;
        }

        return self::ACTIVE;
    }

    /** Expiry reminder thresholds, in seconds before the window closes. */
    public const REMINDER_THRESHOLDS = [
        '7d' => 7 * self::DAY,
        '3d' => 3 * self::DAY,
        '1d' => self::DAY,
        '6h' => 6 * 3600,
    ];

    /**
     * Which thresholds are due now, given what has already gone out.
     *
     * A threshold falls due once the remaining time has dropped to or below it
     * and it has not been sent. The already-sent list is what stops an hourly
     * sweep from re-sending the same warning every hour for a week.
     */
    public static function dueReminders(int $seconds, array $alreadySent): array
    {
        if ($seconds <= 0) {
            return [];
        }

        $due = [];
        foreach (self::REMINDER_THRESHOLDS as $key => $threshold) {
            if ($seconds <= $threshold && ! in_array($key, $alreadySent, true)) {
                $due[] = $key;
            }
        }

        return $due;
    }

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? (string) $status;
    }

    public static function isValid(?string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
