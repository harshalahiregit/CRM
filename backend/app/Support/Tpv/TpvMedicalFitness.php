<?php

namespace App\Support\Tpv;

/**
 * Outcome of a worker's medical examination. This is a hard gate: an Unfit
 * worker can never be issued a site badge.
 */
final class TpvMedicalFitness
{
    public const FIT                  = 'Fit';
    public const FIT_WITH_RESTRICTIONS = 'Fit_With_Restrictions';
    public const UNFIT                = 'Unfit';

    public const ALL = [
        self::FIT, self::FIT_WITH_RESTRICTIONS, self::UNFIT,
    ];

    /** Outcomes that allow a badge to be issued. */
    public const PASSING = [
        self::FIT, self::FIT_WITH_RESTRICTIONS,
    ];

    public const LABELS = [
        self::FIT                   => 'Fit',
        self::FIT_WITH_RESTRICTIONS => 'Fit with Restrictions',
        self::UNFIT                 => 'Unfit',
    ];

    /** Mental-health screening bands (informational triage, not a hard gate). */
    public const BANDS = ['Low', 'Moderate', 'High'];

    /**
     * Band a screening score. Kept server-side so the questionnaire's scoring
     * rule lives in one place rather than being trusted from the client.
     */
    public static function bandForScore(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        return $score >= 10 ? 'High' : ($score >= 5 ? 'Moderate' : 'Low');
    }

    public static function label(?string $v): string
    {
        return self::LABELS[$v] ?? (string) $v;
    }

    public static function isPassing(?string $v): bool
    {
        return in_array($v, self::PASSING, true);
    }

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }
}
