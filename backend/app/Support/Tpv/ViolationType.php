<?php

namespace App\Support\Tpv;

/**
 * TPV violation catalogue + strike escalation ladder (Sangoe TPV §26).
 *
 * Points accumulate from OPEN violations; the cumulative total maps to an
 * escalation level. Thresholds are centralised here (a Settings UI can make them
 * tenant-configurable later, Phase 3).
 */
class ViolationType
{
    public const TYPES = [
        'PPE_Violation', 'Unauthorized_Worker', 'Expired_Document', 'Unsafe_Work',
        'Gate_Violation', 'Security_Violation', 'Environmental_Violation',
        'Repeated_Non_Compliance', 'Training_Violation', 'Other',
    ];

    /** Points contributed by each severity. */
    public const SEVERITY_POINTS = ['Minor' => 1, 'Major' => 2, 'Critical' => 4];

    /**
     * Cumulative open-points → escalation level. Ordered ascending; the highest
     * threshold not exceeding the total wins.
     */
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

    /** Map a cumulative open-points total to its escalation level. */
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
