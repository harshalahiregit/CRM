<?php

namespace App\Support\Purchase;

/**
 * Outcome of a Purchase worker's medical examination. Purchase-owned mirror of
 * App\Support\Tpv\TpvMedicalFitness — a hard gate: an Unfit worker can never be
 * issued an entry badge, but "Fit with Restrictions" passes (the worker is fit
 * subject to the recorded restrictions).
 */
final class PurchaseMedicalFitness
{
    public const PENDING               = 'Pending';
    public const FIT                   = 'Fit';
    public const FIT_WITH_RESTRICTIONS = 'Fit_With_Restrictions';
    public const UNFIT                 = 'Unfit';
    public const EXPIRED               = 'Expired';

    public const ALL = [
        self::PENDING, self::FIT, self::FIT_WITH_RESTRICTIONS, self::UNFIT, self::EXPIRED,
    ];

    /** Outcomes that allow a badge to be issued (parity with TPV PASSING). */
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
     * Band a screening score. Kept server-side, and identical to the TPV rule,
     * so the same answers score the same on both sides.
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
