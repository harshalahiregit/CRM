<?php

namespace App\Services\Hr\Scoring;

use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * The engine's return value. Pure data — computing it performs no writes, so
 * `score()` can be used for a dry run without touching the database.
 */
final class ScoreResult
{
    /**
     * @param  ?int  $overallScore  The PUBLISHED score. Null when nothing could be
     *                              measured, and also null when confidence fell below
     *                              the configured floor — see CandidateScoringEngine.
     * @param  ?int  $provisionalScore  What the weighted average came to before the
     *                              confidence floor was applied. Diagnostic only:
     *                              never display it as the candidate's score.
     * @param  DimensionResult[]  $dimensions
     */
    public function __construct(
        public readonly ?int $overallScore,
        public readonly ?int $provisionalScore,
        public readonly int $confidence,
        public readonly string $recommendation,
        public readonly string $recommendationReason,
        public readonly array $dimensions,
        public readonly array $strengths,
        public readonly array $weaknesses,
        public readonly array $riskFlags,
        public readonly string $summary,
        public readonly array $appliedWeights,
        public readonly ?int $configId,
    ) {
    }

    public function isScored(): bool
    {
        return $this->overallScore !== null;
    }

    /** Score for one dimension key, or null if it had no data. */
    public function dimensionScore(string $key): ?int
    {
        foreach ($this->dimensions as $d) {
            if ($d->key === $key) {
                return $d->score;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'is_scored'              => $this->isScored(),
            'overall_score'          => $this->overallScore,
            'provisional_score'      => $this->provisionalScore,
            'confidence'             => $this->confidence,
            'recommendation'         => $this->recommendation,
            'recommendation_reason'  => $this->recommendationReason,
            'dimensions'             => array_map(fn (DimensionResult $d) => $d->toArray(), $this->dimensions),
            'strengths'              => $this->strengths,
            'weaknesses'             => $this->weaknesses,
            'risk_flags'             => $this->riskFlags,
            'summary'                => $this->summary,
            'applied_weights'        => $this->appliedWeights,
        ];
    }
}
