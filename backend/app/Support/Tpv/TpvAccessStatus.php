<?php

namespace App\Support\Tpv;

/**
 * Temporary TPV access lifecycle states + the server-authoritative countdown
 * projection (colour band and derived status).
 *
 * Stored values are Active / Expired / Converted. "Expiring" is a *derived*
 * projection (< 24h remaining) used for display — it is never persisted.
 */
final class TpvAccessStatus
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

    /** Countdown colour band: green > 3d, orange ≤ 3d, red < 24h, expired ≤ 0. */
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

    /** Derive the display status from the stored status + remaining seconds. */
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

    /** Expiry reminder thresholds (seconds before expiry). */
    public const REMINDER_THRESHOLDS = [
        '7d' => 7 * self::DAY,
        '3d' => 3 * self::DAY,
        '1d' => self::DAY,
        '6h' => 6 * 3600,
    ];

    /**
     * Which reminder thresholds are now due for a window with `$seconds` left,
     * given the set already sent. A threshold is due once the remaining time has
     * dropped to or below it and it has not been sent yet.
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
