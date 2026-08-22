<?php

namespace App\Support\Purchase;

/**
 * Purchase violation catalogue + escalation ladder — the Purchase-side mirror of
 * TPV's ViolationType (parity rule), tuned for supplier conduct. Points
 * accumulate from OPEN violations; the cumulative total maps to an escalation
 * level. Kept in its own namespace so the two modules stay isolated.
 */
class PurchaseViolationType
{
    public const TYPES = [
        'Quality_Failure', 'Late_Delivery', 'Short_Supply', 'Expired_Document',
        'Compliance_Breach', 'Pricing_Dispute', 'Unauthorised_Substitution',
        'Repeated_Non_Compliance', 'Safety_Violation', 'Other',
    ];

    public const SEVERITY_POINTS = ['Minor' => 1, 'Major' => 2, 'Critical' => 4];

    /** Cumulative open-points → escalation level (ascending; highest not-exceeded wins). */
    public const LADDER = [
        0 => 'None',
        1 => 'Warning',
        3 => 'Strike_1',
        5 => 'Strike_2',
        7 => 'Strike_3',
        10 => 'Suspension',
        13 => 'Blacklist',
    ];

    public static function typeLabel(?string $t): string
    {
        return $t ? ucwords(str_replace('_', ' ', $t)) : 'Unknown';
    }

    public static function pointsFor(?string $severity): int
    {
        return self::SEVERITY_POINTS[$severity] ?? 1;
    }

    public static function levelFor(int $points): string
    {
        $level = 'None';
        foreach (self::LADDER as $threshold => $name) {
            if ($points >= $threshold) {
                $level = $name;
            }
        }

        return $level;
    }

    public static function levelLabel(string $level): string
    {
        return str_replace('_', ' ', $level);
    }
}
