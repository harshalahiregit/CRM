<?php

namespace App\Services\Hr\Scoring;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;
use App\Services\Hr\Scoring\Dimensions\EducationDimension;
use App\Services\Hr\Scoring\Dimensions\ExperienceDimension;
use App\Services\Hr\Scoring\Dimensions\InterviewDimension;
use App\Services\Hr\Scoring\Dimensions\JdMatchDimension;
use App\Services\Hr\Scoring\Dimensions\LocationDimension;
use App\Services\Hr\Scoring\Dimensions\NoticePeriodDimension;
use App\Services\Hr\Scoring\Dimensions\ResumeDimension;
use App\Services\Hr\Scoring\Dimensions\SalaryDimension;
use App\Services\Hr\Scoring\Dimensions\ScreeningDimension;
use App\Services\Hr\Scoring\Dimensions\SkillsDimension;

/**
 * The ONE candidate job-fit scoring entry point.
 *
 * Nothing else in the application may compute an AI score. The two formulas this
 * replaces (CandidateService::computeAiScore and ::evaluateApplication) wrote the
 * same column with different weights, so the same candidate scored differently
 * depending on whether HR typed them in or they applied through the career portal.
 *
 * The rule that shapes everything here: a dimension with no input returns null and
 * is DROPPED from the weighted average, with the remaining weights renormalised.
 * Confidence reports how much of the total weight actually had data. Missing data
 * lowers confidence; it never invents a score.
 *
 * score() is pure — it reads and computes but writes nothing. Persistence is
 * ScoreRecorder's job, which is what makes the dry run trustworthy.
 */
class CandidateScoringEngine
{
    /** Dimension objects keyed by their weight key. Order drives display order. */
    private array $dimensions;

    public function __construct(
        private readonly ScoringConfigResolver $configs,
        private readonly RecommendationEngine $recommendations,
    ) {
        $this->dimensions = [
            SkillsDimension::KEY       => new SkillsDimension(),
            ExperienceDimension::KEY   => new ExperienceDimension(),
            JdMatchDimension::KEY      => new JdMatchDimension(),
            InterviewDimension::KEY    => new InterviewDimension(),
            EducationDimension::KEY    => new EducationDimension(),
            LocationDimension::KEY     => new LocationDimension(),
            SalaryDimension::KEY       => new SalaryDimension(),
            NoticePeriodDimension::KEY => new NoticePeriodDimension(),
            ScreeningDimension::KEY    => new ScreeningDimension(),
            ResumeDimension::KEY       => new ResumeDimension(),
        ];
    }

    public function score(HrCandidate $candidate, ?HrJobPosting $job = null): ScoreResult
    {
        $job ??= $candidate->jobPosting;
        // The requisition supplies required_skills / experience_required / education /
        // salary band / target joining date — eager-load once for all dimensions.
        $job?->loadMissing('manpowerRequest');

        $config  = $this->configs->resolve($candidate->tenant_id, $job?->department);
        $weights = $config->weights();

        /** @var DimensionResult[] $results */
        $results = [];
        foreach ($this->dimensions as $key => $dimension) {
            $results[] = $dimension->score($candidate, $job);
        }

        // ── Renormalise over the dimensions that actually measured something ──
        $availableWeight = 0;
        $totalWeight     = 0;
        $weightedSum     = 0.0;
        $applied         = [];

        foreach ($results as $r) {
            $w = (int) ($weights[$r->key] ?? 0);
            $totalWeight += $w;
            if ($r->isScored() && $w > 0) {
                $availableWeight += $w;
                $weightedSum     += $r->score * $w;
                $applied[$r->key] = $w;
            }
        }

        $provisional = $availableWeight > 0
            ? (int) round($weightedSum / $availableWeight)
            : null;

        $confidence = $totalWeight > 0
            ? (int) round(($availableWeight / $totalWeight) * 100)
            : 0;

        // Below the confidence floor NO overall score is published. Renormalising
        // over a sliver of the weight yields technically-correct nonsense: a
        // candidate whose only scored dimension is Location (6% of the weight)
        // computes to 100, which the compatibility mirror would then render as a
        // green 100% in the UI. Suppressing the headline is the same rule that makes
        // the recommendation "Insufficient Data" — a number this thin is fabrication.
        // The provisional value and every dimension score are retained for diagnosis.
        $minConfidence = $config->thresholds()['min_confidence'];
        $overall = ($provisional !== null && $confidence >= $minConfidence) ? $provisional : null;

        // Both derive from the PROVISIONAL score so the reason can explain the
        // suppression ("scored 100% on 6% of the weight") rather than claim that
        // nothing could be measured.
        $recommendation = $this->recommendations->recommend($provisional, $confidence, $config);
        $reason         = $this->recommendations->reason($provisional, $confidence, $config);

        return new ScoreResult(
            overallScore: $overall,
            provisionalScore: $provisional,
            confidence: $confidence,
            recommendation: $recommendation,
            recommendationReason: $reason,
            dimensions: $results,
            strengths: $this->strengths($results),
            weaknesses: $this->weaknesses($results),
            riskFlags: $this->riskFlags($results, $confidence, $config->thresholds()['min_confidence']),
            summary: $this->summary($overall, $confidence, $results),
            appliedWeights: $applied,
            configId: $config->exists ? $config->id : null,
        );
    }

    /** @param DimensionResult[] $results */
    private function strengths(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            if ($r->isScored() && $r->score >= 75) {
                $out[] = $r->label.': '.$r->reason;
            }
        }

        return $out;
    }

    /** @param DimensionResult[] $results */
    private function weaknesses(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            if ($r->isScored() && $r->score < 50) {
                $out[] = $r->label.': '.$r->reason;
            }
        }

        return $out;
    }

    /**
     * Risks describe the RELIABILITY of this score, not personality traits — the
     * psychometric risk columns on air_candidate_scores belong to a different engine.
     *
     * @param DimensionResult[] $results
     */
    private function riskFlags(array $results, int $confidence, int $minConfidence): array
    {
        $flags = [];

        $missing = array_values(array_map(
            fn (DimensionResult $r) => $r->label,
            array_filter($results, fn (DimensionResult $r) => ! $r->isScored())
        ));

        if ($confidence < $minConfidence) {
            $flags[] = [
                'code'    => 'low_confidence',
                'label'   => 'Low confidence',
                'detail'  => sprintf('Only %d%% of the scoring weight had data behind it.', $confidence),
            ];
        }
        if ($missing !== []) {
            $flags[] = [
                'code'   => 'missing_data',
                'label'  => 'Missing data',
                'detail' => 'Not scored: '.implode(', ', $missing).'.',
            ];
        }

        $skills = null;
        foreach ($results as $r) {
            if ($r->key === SkillsDimension::KEY) {
                $skills = $r;
            }
        }
        if ($skills?->isScored() && $skills->score < 50) {
            $flags[] = [
                'code'   => 'skill_gap',
                'label'  => 'Skill gap',
                'detail' => $skills->reason,
            ];
        }

        return $flags;
    }

    /** @param DimensionResult[] $results */
    private function summary(?int $overall, int $confidence, array $results): string
    {
        if ($overall === null) {
            $missing = array_values(array_map(
                fn (DimensionResult $r) => $r->label,
                array_filter($results, fn (DimensionResult $r) => ! $r->isScored())
            ));

            return sprintf(
                'Not scored: only %d%% of the scoring weight had data behind it. Not measured: %s.',
                $confidence,
                $missing === [] ? 'none' : implode(', ', $missing)
            );
        }

        $scored = array_filter($results, fn (DimensionResult $r) => $r->isScored());

        return sprintf(
            'Overall job fit %d%%, computed from %d of %d dimensions (%d%% confidence). %s',
            $overall,
            count($scored),
            count($results),
            $confidence,
            $confidence >= 70
                ? 'Enough of the profile is populated to compare this candidate meaningfully.'
                : 'Treat with caution — a large share of the scoring weight had no data behind it.'
        );
    }
}
