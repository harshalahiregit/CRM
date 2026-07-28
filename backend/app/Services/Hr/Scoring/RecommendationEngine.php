<?php

namespace App\Services\Hr\Scoring;

use App\Models\Hr\AirScoringConfig;

/**
 * The ONE recommendation vocabulary.
 *
 * Four existed before — CandidateService (Recommended / Recommended with Training /
 * Hold / Reject at 60/50), CompanyPortalService (Strongly Recommended … at 85/70/50),
 * constants.js aiBand() (Highly Recommended … at 90/70/50) and CandidateProfile's
 * REC_STYLE — and two of them rendered in the same card, so an 87% could read
 * "Recommended" on one page and "Strongly Recommended" on another.
 *
 * Thresholds come from air_scoring_config. Nothing here is hardcoded, and the
 * frontend derives no labels of its own.
 */
class RecommendationEngine
{
    public const HIGHLY_RECOMMENDED = 'Highly Recommended';
    public const RECOMMENDED        = 'Recommended';
    public const CONSIDER           = 'Consider';
    public const NOT_RECOMMENDED    = 'Not Recommended';
    public const INSUFFICIENT_DATA  = 'Insufficient Data';

    public const ALL = [
        self::HIGHLY_RECOMMENDED, self::RECOMMENDED, self::CONSIDER,
        self::NOT_RECOMMENDED, self::INSUFFICIENT_DATA,
    ];

    /**
     * A score built on too little evidence is reported as such rather than dressed
     * up as a verdict — a 78% derived from one dimension is not "Recommended".
     */
    public function recommend(?int $overall, ?int $confidence, AirScoringConfig $config): string
    {
        $t = $config->thresholds();

        if ($overall === null) {
            return self::INSUFFICIENT_DATA;
        }
        if ($confidence !== null && $confidence < $t['min_confidence']) {
            return self::INSUFFICIENT_DATA;
        }

        return match (true) {
            $overall >= $t['highly_recommended'] => self::HIGHLY_RECOMMENDED,
            $overall >= $t['recommended']        => self::RECOMMENDED,
            $overall >= $t['consider']           => self::CONSIDER,
            default                              => self::NOT_RECOMMENDED,
        };
    }

    /** Plain-English basis for the label, so it never has to be inferred. */
    public function reason(?int $overall, ?int $confidence, AirScoringConfig $config): string
    {
        $t = $config->thresholds();

        if ($overall === null) {
            return 'No dimension had enough data to produce a score.';
        }
        if ($confidence !== null && $confidence < $t['min_confidence']) {
            return sprintf(
                'Scored %d%%, but only %d%% of the scoring weight had data behind it (minimum %d%%). Capture more candidate detail before relying on this.',
                $overall, $confidence, $t['min_confidence']
            );
        }

        return match (true) {
            $overall >= $t['highly_recommended'] => sprintf('Overall fit %d%% — at or above the %d%% bar for a strong match.', $overall, $t['highly_recommended']),
            $overall >= $t['recommended']        => sprintf('Overall fit %d%% — meets the %d%% bar for this role.', $overall, $t['recommended']),
            $overall >= $t['consider']           => sprintf('Overall fit %d%% — borderline; compare against other applicants.', $overall),
            default                              => sprintf('Overall fit %d%% — below the %d%% bar for this role.', $overall, $t['consider']),
        };
    }
}
