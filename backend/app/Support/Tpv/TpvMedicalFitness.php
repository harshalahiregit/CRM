<?php

namespace App\Support\Tpv;

/**
 * Outcome of a worker's medical examination. This is a hard gate: an Unfit
 * worker can never be issued a site badge.
 */
final class TpvMedicalFitness
{
    public const PENDING              = 'Pending';
    public const FIT                  = 'Fit';
    public const FIT_WITH_RESTRICTIONS = 'Fit_With_Restrictions';
    public const UNFIT                = 'Unfit';
    // A stored terminal state for a lapsed certificate. The record can still be
    // DERIVED as expired from valid_until (see TpvWorkerMedical::isExpired), but
    // capturing it as an explicit outcome lets a sign-off be marked expired
    // without disturbing the original fitness verdict.
    public const EXPIRED              = 'Expired';

    public const ALL = [
        self::PENDING, self::FIT, self::FIT_WITH_RESTRICTIONS, self::UNFIT, self::EXPIRED,
    ];

    /** Outcomes that allow a badge to be issued. */
    public const PASSING = [
        self::FIT, self::FIT_WITH_RESTRICTIONS,
    ];

    public const LABELS = [
        self::PENDING               => 'Pending',
        self::FIT                   => 'Fit',
        self::FIT_WITH_RESTRICTIONS => 'Fit with Restrictions',
        self::UNFIT                 => 'Unfit',
        self::EXPIRED               => 'Expired',
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
