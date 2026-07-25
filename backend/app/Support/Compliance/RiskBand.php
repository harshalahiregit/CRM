<?php

namespace App\Support\Compliance;

/**
 * Risk banding for a scored checklist.
 *
 * Scores are RISK POINTS: higher is worse, so Low is the good band. This
 * matches TpvMedicalFitness::bandForScore (>=10 High) and the Low/Moderate/High
 * vocabulary the workforce wizard already renders — inverting it here (scoring
 * compliance % where higher is better) would leave "Low" meaning good in one
 * module and bad in another.
 *
 * Unlike the medical screening — a fixed 0-60 questionnaire that can band on the
 * raw score — templates here are arbitrary length, so a 4-question checklist and
 * a 40-question one are not comparable on raw points. Banding is therefore on
 * PERCENT of the template's own maximum risk, which is why thresholds are
 * percentages.
 */
final class RiskBand
{
    public const LOW      = 'Low';
    public const MODERATE = 'Moderate';
    public const HIGH     = 'High';

    public const ALL = [self::LOW, self::MODERATE, self::HIGH];

    public const LABELS = [
        self::LOW      => 'Low',
        self::MODERATE => 'Moderate',
        self::HIGH     => 'High',
    ];

    /**
     * Percent-of-maximum at which each band starts. A template may override
     * these; anything below `moderate` is Low.
     */
    public const DEFAULT_THRESHOLDS = ['moderate' => 25, 'high' => 50];

    public static function label(?string $band): string
    {
        return self::LABELS[$band] ?? (string) $band;
    }

    public static function isValid(?string $band): bool
    {
        return in_array($band, self::ALL, true);
    }

    /** Merge a template's partial overrides over the defaults, clamped sanely. */
    public static function thresholds(?array $overrides): array
    {
        $t = array_merge(self::DEFAULT_THRESHOLDS, array_intersect_key($overrides ?? [], self::DEFAULT_THRESHOLDS));

        $moderate = max(0, min(100, (float) $t['moderate']));
        // A high threshold at or below moderate would make Moderate unreachable;
        // treat that as a template authoring error and keep them ordered.
        $high = max($moderate, min(100, (float) $t['high']));

        return ['moderate' => $moderate, 'high' => $high];
    }

    /**
     * Band a percent-of-maximum risk score.
     *
     * $criticalFailed short-circuits to High regardless of the arithmetic: a
     * critical control (no harness at height, no valid lift plan) that fails
     * cannot be averaged away by a long tail of compliant answers. This mirrors
     * StrikeSeverity::terminatesImmediately, where one Critical strike removes
     * access on its own without reaching the count.
     */
    public static function forPercent(float $percent, ?array $thresholds = null, bool $criticalFailed = false): string
    {
        if ($criticalFailed) {
            return self::HIGH;
        }

        $t = self::thresholds($thresholds);

        return match (true) {
            $percent >= $t['high']     => self::HIGH,
            $percent >= $t['moderate'] => self::MODERATE,
            default                    => self::LOW,
        };
    }
}
