<?php

namespace App\Services\Hr\Scoring\Employee;

use App\Services\Hr\Scoring\Dimensions\DimensionResult;

/**
 * #39 — the employee engine's return value. Pure data; computing it writes
 * nothing, so it doubles as a dry run.
 *
 * Separate from the candidate ScoreResult on purpose: that one carries a hiring
 * `recommendation` ("Strong Hire") which has no meaning for somebody already
 * employed. This carries a `band` instead.
 */
final class EmployeeScoreResult
{
    /** @param DimensionResult[] $dimensions */
    public function __construct(
        public readonly ?int $overallScore,
        public readonly ?int $provisionalScore,
        public readonly int $confidence,
        public readonly ?string $band,
        public readonly array $dimensions,
        public readonly array $appliedWeights,
        public readonly string $summary,
    ) {
    }

    public function isScored(): bool
    {
        return $this->overallScore !== null;
    }

    /** Dimensions that produced a number, best first. */
    public function scored(): array
    {
        return collect($this->dimensions)
            ->filter(fn ($d) => $d->isScored())
            ->sortByDesc(fn ($d) => $d->score)
            ->values()->all();
    }

    public function toArray(): array
    {
        return [
            'overall_score'     => $this->overallScore,
            'provisional_score' => $this->provisionalScore,
            'confidence'        => $this->confidence,
            'band'              => $this->band,
            'summary'           => $this->summary,
            'applied_weights'   => $this->appliedWeights,
            'dimensions'        => array_map(fn ($d) => $d->toArray(), $this->dimensions),
        ];
    }
}
