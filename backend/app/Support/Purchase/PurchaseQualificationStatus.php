<?php

namespace App\Support\Purchase;

/**
 * Purchase vendor prequalification outcome — the Purchase-side mirror of
 * App\Support\Vendor\QualificationStatus.
 *
 * Derived from a scored questionnaire: a vendor scoring at/above the Qualified
 * threshold is fit to engage; Conditional means fit with reservations; below
 * that, Not Qualified. Pending = never assessed. Thresholds live in
 * config/purchase_prequalification.php.
 */
final class PurchaseQualificationStatus
{
    public const PENDING       = 'Pending';
    public const QUALIFIED     = 'Qualified';
    public const CONDITIONAL   = 'Conditional';
    public const NOT_QUALIFIED = 'Not_Qualified';

    public const ALL = [self::PENDING, self::QUALIFIED, self::CONDITIONAL, self::NOT_QUALIFIED];

    public const LABELS = [
        self::PENDING       => 'Pending',
        self::QUALIFIED     => 'Qualified',
        self::CONDITIONAL   => 'Conditional',
        self::NOT_QUALIFIED => 'Not Qualified',
    ];

    public static function isValid(?string $v): bool
    {
        return in_array($v, self::ALL, true);
    }

    public static function label(?string $v): string
    {
        return self::LABELS[$v] ?? '—';
    }

    /** Band a 0–100 score to the highest outcome whose lower bound it meets. */
    public static function fromScore(int $score): string
    {
        $outcomes = config('purchase_prequalification.outcomes', []);
        arsort($outcomes);

        foreach ($outcomes as $status => $min) {
            if ($score >= $min) {
                return $status;
            }
        }

        return self::NOT_QUALIFIED;
    }

    /** True when this outcome may proceed to approval. */
    public static function isPassing(?string $v): bool
    {
        return in_array($v, [self::QUALIFIED, self::CONDITIONAL], true);
    }
}
