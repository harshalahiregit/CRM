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
     * threshold not exceeding the total wins. This is the shipped default; a tenant
     * may override it through TpvSettings ('violation_ladder'), which is why the
     * `*Steps` helpers below take a ladder rather than reading this constant.
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

    /** Points for a severity from a configurable map (falls back to the default). */
    public static function pointsForWith(?string $severity, ?array $map): int
    {
        $map = $map ?: self::SEVERITY_POINTS;

        return (int) ($map[$severity] ?? self::SEVERITY_POINTS[$severity] ?? 1);
    }

    /** Map a cumulative open-points total to its escalation level (default ladder). */
    public static function levelFor(int $points): string
    {
        return self::levelForSteps($points, self::ladderSteps());
    }

    /** The default ladder as an ordered list of {points, level} steps (for editing). */
    public static function ladderSteps(): array
    {
        $steps = [];
        foreach (self::LADDER as $points => $level) {
            $steps[] = ['points' => (int) $points, 'level' => $level];
        }

        return $steps;
    }

    /**
     * Map a cumulative open-points total to its escalation level using a steps
     * list (the tenant-configurable form). The highest step whose points the total
     * meets or exceeds wins; an empty/invalid ladder falls back to the default.
     */
    public static function levelForSteps(int $points, ?array $steps): string
    {
        $steps = array_values(array_filter($steps ?: [], fn ($s) => isset($s['points'], $s['level'])));
        if (empty($steps)) {
            $steps = self::ladderSteps();
        }
        usort($steps, fn ($a, $b) => (int) $a['points'] <=> (int) $b['points']);

        $level = 'None';
        foreach ($steps as $s) {
            if ($points >= (int) $s['points']) {
                $level = (string) $s['level'];
            }
        }

        return $level;
    }

    public static function levelLabel(string $level): string
    {
        return str_replace('_', ' ', $level);
    }
}
