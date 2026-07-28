<?php

namespace App\Services\Hr\Scoring;

use App\Models\Hr\AirCandidateScore;
use App\Models\Hr\AirPredictionHistory;
use App\Models\Hr\HrCandidate;
use App\Services\Hr\Scoring\Dimensions\DimensionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only writer of scoring results.
 *
 * Three things happen, in one transaction:
 *  1. the previous score is appended to air_prediction_history (before it is lost);
 *  2. the live row in air_candidate_scores is upserted on (candidate_id, job_id);
 *  3. hr_candidates.ai_score is mirrored for backward compatibility, so existing
 *     reports, the Assessment stage gate and the current UI keep working untouched.
 *
 * The mirror is deliberately one-way and lossy: ai_score cannot express "unscored
 * with 30% confidence". Everything richer lives in air_candidate_scores, and new
 * code should read there.
 */
class ScoreRecorder
{
    public function record(HrCandidate $candidate, ScoreResult $result, string $trigger = 'manual'): AirCandidateScore
    {
        return DB::transaction(function () use ($candidate, $result, $trigger) {
            $jobId = $candidate->job_posting_id;

            $existing = AirCandidateScore::where('candidate_id', $candidate->id)
                ->where(fn ($q) => $jobId === null ? $q->whereNull('job_id') : $q->where('job_id', $jobId))
                ->first();

            // History first — capture what the score WAS before it is overwritten.
            AirPredictionHistory::create([
                'tenant_id'               => $candidate->tenant_id,
                'candidate_id'            => $candidate->id,
                'job_id'                  => $jobId,
                'previous_score'          => $existing?->overall_score,
                'new_score'               => $result->overallScore,
                'previous_recommendation' => $existing?->recommendation,
                'new_recommendation'      => $result->recommendation,
                'confidence_level'        => $result->confidence,
                'trigger'                 => $trigger,
                // The forecast columns from the original AIR migration: filled so an
                // outcome can be compared back later, accuracy left null until then.
                'predicted_score'          => $result->overallScore,
                'predicted_recommendation' => $result->recommendation,
                'prediction_date'          => now(),
            ]);

            $score = $existing ?: new AirCandidateScore([
                'candidate_id' => $candidate->id,
                'job_id'       => $jobId,
            ]);

            $score->fill([
                'tenant_id'         => $candidate->tenant_id,
                'candidate_id'      => $candidate->id,
                'job_id'            => $jobId,
                'overall_score'     => $result->overallScore,
                'confidence_level'  => $result->confidence,
                'recommendation'    => $result->recommendation,
                'dimension_details' => array_map(fn (DimensionResult $d) => $d->toArray(), $result->dimensions),
                'applied_weights'   => $result->appliedWeights,
                'risk_flags'        => $result->riskFlags,
                'strengths'         => $result->strengths,
                'weaknesses'        => $result->weaknesses,
                'ai_summary'        => $result->summary,
                'scoring_config_id' => $result->configId,
                'scored_trigger'    => $trigger,
                'scored_at'         => now(),
            ]);

            // Per-dimension columns, so the table is queryable without unpacking JSON.
            foreach ($result->dimensions as $d) {
                $column = $d->key.'_score';
                $score->{$column} = $d->score;   // null stays null — never coerced to 0
            }

            $score->save();

            $this->mirrorToCandidate($candidate, $result);

            Log::channel('hr')->info('Candidate scored', [
                'candidate_id' => $candidate->id, 'job_id' => $jobId,
                'overall' => $result->overallScore, 'confidence' => $result->confidence,
                'recommendation' => $result->recommendation, 'trigger' => $trigger,
            ]);

            return $score;
        });
    }

    /**
     * Backward-compatibility mirror. `ai_score` is in CandidateService's
     * UPDATE_PROTECTED list, so it is written here with saveQuietly() to bypass the
     * generic update path without re-opening mass assignment.
     */
    private function mirrorToCandidate(HrCandidate $candidate, ScoreResult $result): void
    {
        $candidate->forceFill([
            'ai_score' => $result->overallScore,
            // The mirror carries exactly the engine's contract -- no legacy aliases.
            // Those existed only while the UI still read skills_match / exp_match /
            // overall_fit and friends; every consumer now reads `dimensions`.
            'ai_breakdown' => [
                'overall'        => $result->overallScore,
                'confidence'     => $result->confidence,
                'recommendation' => $result->recommendation,
                'dimensions'     => array_map(fn (DimensionResult $d) => $d->toArray(), $result->dimensions),
                'strengths'      => $result->strengths,
                'weaknesses'     => $result->weaknesses,
                'risk_flags'     => $result->riskFlags,
                'summary'        => $result->summary,
                'engine'         => 'air.job_fit.v1',
            ],
        ])->saveQuietly();
    }
}
