<?php

namespace App\Support\Vendor;

/**
 * Vendor risk classification tier (gap report area 2).
 *
 * A forward-looking judgement of how much risk engaging this vendor carries,
 * set at qualification from weighted factors (see config/vendor_risk.php). This
 * is NOT the VRS performance band (A–D), which measures past performance — risk
 * drives monitoring depth, performance rates delivery.
 */
final class RiskTier
{
    public const CRITICAL = 'Critical';
    public const HIGH     = 'High';
    public const MEDIUM   = 'Medium';
    public const LOW      = 'Low';

    /** Highest first — the order the score is banded in. */
    public const ALL = [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW];

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }

    public static function label(?string $v): string
    {
        return self::isValid($v) ? $v : '—';
    }

    /** How closely a vendor at this tier is monitored (config-driven). */
    public static function monitoringDepth(?string $tier): ?string
    {
        return config('vendor_risk.monitoring.'.$tier);
    }
}
