<?php

namespace App\Support\Vendor;

/**
 * §5/§7 Vendor risk factors — the inputs the doc lists for classifying a vendor's
 * risk. Selections are stored on vendors.risk_factors (a JSON array); this
 * catalogue is the vocabulary the risk-assessment UI offers, so the factors are
 * explicit rather than free-form.
 */
final class VendorRiskFactor
{
    public const REGULATORY        = 'Regulatory_Requirements';
    public const PREVIOUS_INCIDENTS = 'Previous_Incidents';
    public const COMPLIANCE_HISTORY = 'Compliance_History';
    public const VENDOR_PERFORMANCE = 'Vendor_Performance';
    // Existing, already implied factors kept for completeness.
    public const FINANCIAL         = 'Financial_Stability';
    public const OPERATIONAL       = 'Operational_Capability';
    public const HSE               = 'HSE_Record';

    public const ALL = [
        self::REGULATORY, self::PREVIOUS_INCIDENTS, self::COMPLIANCE_HISTORY,
        self::VENDOR_PERFORMANCE, self::FINANCIAL, self::OPERATIONAL, self::HSE,
    ];

    public static function label(?string $v): string
    {
        return $v ? str_replace('_', ' ', $v) : (string) $v;
    }
}
