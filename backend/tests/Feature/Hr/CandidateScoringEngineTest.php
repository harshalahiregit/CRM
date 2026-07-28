<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\AirCandidateScore;
use App\Models\Hr\AirPredictionHistory;
use App\Models\Hr\AirScoringConfig;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Services\Hr\Scoring\CandidateScoringEngine;
use App\Services\Hr\Scoring\RecommendationEngine;
use App\Services\Hr\Scoring\ScoreRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Engine-level behaviour: weight renormalisation, confidence, the recommendation
 * vocabulary, persistence and the backward-compatibility mirror.
 */
class CandidateScoringEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): CandidateScoringEngine
    {
        return app(CandidateScoringEngine::class);
    }

    /**
     * Tests about renormalisation and persistence must not be coupled to the
     * production confidence floor (60), which would suppress the very scores they
     * assert on. Tests about the gate itself set their own threshold.
     */
    private function withoutConfidenceGate(): void
    {
        AirScoringConfig::create([
            'tenant_id' => 1, 'job_family' => 'General', 'is_active' => true,
            'is_default' => true, 'min_confidence' => 0,
        ]);
    }

    private function fullyScorableJob(): HrJobPosting
    {
        $mr = HrManpowerRequest::create([
            'tenant_id' => 1, 'department' => 'Engineering', 'position_title' => 'Senior React Developer',
            'number_of_posts' => 1, 'status' => 'Approved',
            'required_skills' => ['React', 'TypeScript', 'Node'],
            'experience_required' => '4 years',
            'education' => 'B.Tech',
            'target_joining_date' => now()->addDays(60)->toDateString(),
            'salary_min' => 1000000, 'salary_max' => 1500000,
        ]);

        return HrJobPosting::create([
            'tenant_id' => 1, 'title' => 'Senior React Developer', 'department' => 'Engineering',
            'location' => 'Bangalore', 'job_type' => 'Full-time', 'status' => 'Open',
            'requirements' => 'React, TypeScript, Node', 'salary_from' => 1000000, 'salary_to' => 1500000,
            'manpower_request_id' => $mr->id,
        ]);
    }

    private function candidate(array $attrs = []): HrCandidate
    {
        return HrCandidate::create(array_merge([
            'tenant_id' => 1, 'name' => 'Test Candidate', 'email' => 't'.uniqid().'@test.com',
            'stage' => 'Applied',
        ], $attrs));
    }

    // ── The core guarantee ───────────────────────────────────────────────────

    /**
     * An empty candidate must not be scored at all. The old heuristic gave such a
     * profile roughly 53% ("Hold") out of pure baselines.
     */
    public function test_a_candidate_with_no_data_is_not_scored(): void
    {
        $result = $this->engine()->score($this->candidate(), $this->fullyScorableJob());

        $this->assertFalse($result->isScored());
        $this->assertNull($result->overallScore);
        $this->assertSame(RecommendationEngine::INSUFFICIENT_DATA, $result->recommendation);
    }

    public function test_missing_dimensions_are_dropped_not_scored_as_zero(): void
    {
        $this->withoutConfidenceGate();
        $job = $this->fullyScorableJob();
        // Skills perfect, everything else absent.
        $c = $this->candidate(['skills' => ['React', 'TypeScript', 'Node']]);

        $result = $this->engine()->score($c, $job);

        $this->assertSame(100, $result->dimensionScore('skills'));
        $this->assertNull($result->dimensionScore('education'));
        $this->assertNull($result->dimensionScore('salary'));
        $this->assertNull($result->dimensionScore('resume'));

        // Renormalised over what scored: a perfect skills score must not be dragged
        // toward zero by dimensions that had nothing to measure.
        $this->assertSame(100, $result->overallScore,
            'Unavailable dimensions must be excluded, not counted as 0');
    }

    public function test_confidence_reflects_the_share_of_weight_with_data(): void
    {
        $job = $this->fullyScorableJob();

        $thin = $this->engine()->score($this->candidate(['skills' => ['React']]), $job);
        $rich = $this->engine()->score($this->candidate([
            'skills' => ['React', 'TypeScript', 'Node'],
            'experience_years' => 5,
            'location' => 'Bangalore',
            'expected_ctc' => 1200000,
            'notice_period' => '30 days',
            'education' => [['degree' => 'B.Tech']],
        ]), $job);

        $this->assertGreaterThan($thin->confidence, $rich->confidence);
        $this->assertLessThanOrEqual(100, $rich->confidence);
        $this->assertGreaterThan(0, $thin->confidence);
    }

    public function test_confidence_is_never_100_while_resume_and_screening_are_unavailable(): void
    {
        $job = $this->fullyScorableJob();
        $c = $this->candidate([
            'skills' => ['React', 'TypeScript', 'Node'], 'experience_years' => 5,
            'location' => 'Bangalore', 'expected_ctc' => 1200000, 'notice_period' => '30 days',
            'education' => [['degree' => 'B.Tech']],
        ]);

        $result = $this->engine()->score($c, $job);

        $this->assertLessThan(100, $result->confidence);
        $codes = array_column($result->riskFlags, 'code');
        $this->assertContains('missing_data', $codes);
    }

    // ── Recommendation ───────────────────────────────────────────────────────

    public function test_recommendation_uses_configured_thresholds(): void
    {
        AirScoringConfig::create([
            'tenant_id' => 1, 'job_family' => 'General', 'is_active' => true, 'is_default' => true,
            'highly_recommended_threshold' => 90, 'recommended_threshold' => 75,
            'consider_threshold' => 60, 'min_confidence' => 0,
        ]);

        $job = $this->fullyScorableJob();

        // Perfect skills only -> renormalises to 100 -> Highly Recommended.
        $top = $this->engine()->score($this->candidate(['skills' => ['React', 'TypeScript', 'Node']]), $job);
        $this->assertSame(RecommendationEngine::HIGHLY_RECOMMENDED, $top->recommendation);

        // One of three required skills -> 33 -> Not Recommended.
        $low = $this->engine()->score($this->candidate(['skills' => ['React']]), $job);
        $this->assertSame(33, $low->overallScore);
        $this->assertSame(RecommendationEngine::NOT_RECOMMENDED, $low->recommendation);
    }

    /**
     * A high score built on almost no evidence must not be published at all — not as
     * a band, and not as a number. Renormalising over a sliver of the weight produced
     * a candidate scoring 100 off Location alone (6% of the weight), which the
     * compatibility mirror would have rendered as a green 100% in the UI.
     */
    public function test_low_confidence_suppresses_the_score_and_the_band(): void
    {
        AirScoringConfig::create([
            'tenant_id' => 1, 'job_family' => 'General', 'is_active' => true, 'is_default' => true,
            'min_confidence' => 90,
        ]);

        $result = $this->engine()->score(
            $this->candidate(['skills' => ['React', 'TypeScript', 'Node']]),
            $this->fullyScorableJob()
        );

        $this->assertNull($result->overallScore, 'A thin score must not be published');
        $this->assertFalse($result->isScored());
        $this->assertSame(RecommendationEngine::INSUFFICIENT_DATA, $result->recommendation);

        // The computed value is retained for diagnosis, never for display.
        $this->assertSame(100, $result->provisionalScore);
        $this->assertStringContainsString('%', $result->recommendationReason);
    }

    public function test_every_dimension_reports_a_reason(): void
    {
        $result = $this->engine()->score($this->candidate(), $this->fullyScorableJob());

        $this->assertCount(10, $result->dimensions);
        foreach ($result->dimensions as $d) {
            $this->assertNotSame('', $d->reason, "Dimension {$d->key} must explain itself");
        }
    }

    // ── Persistence ──────────────────────────────────────────────────────────

    public function test_recorder_persists_dimensions_and_mirrors_ai_score(): void
    {
        $this->withoutConfidenceGate();
        $job = $this->fullyScorableJob();
        $c = $this->candidate(['job_posting_id' => $job->id, 'skills' => ['React', 'TypeScript', 'Node'], 'experience_years' => 5]);

        $result = $this->engine()->score($c->fresh(), $job);
        app(ScoreRecorder::class)->record($c->fresh(), $result, 'test');

        $row = AirCandidateScore::where('candidate_id', $c->id)->firstOrFail();
        $this->assertSame(100, (int) $row->skills_score);
        $this->assertNull($row->resume_score, 'Unavailable dimension must persist as NULL, not 0');
        $this->assertNull($row->education_score);
        $this->assertSame($result->overallScore, (int) $row->overall_score);
        $this->assertSame($result->recommendation, $row->recommendation);
        $this->assertCount(10, $row->dimension_details);

        // Backward-compatibility mirror.
        $this->assertSame($result->overallScore, (int) $c->fresh()->ai_score);
        $this->assertSame('air.job_fit.v1', $c->fresh()->ai_breakdown['engine']);
    }

    public function test_recorder_writes_history_before_replacing_a_score(): void
    {
        $this->withoutConfidenceGate();
        $job = $this->fullyScorableJob();
        $c = $this->candidate(['job_posting_id' => $job->id, 'skills' => ['React']]);
        $recorder = app(ScoreRecorder::class);

        $first = $this->engine()->score($c->fresh(), $job);
        $recorder->record($c->fresh(), $first, 'create');

        // Improve the candidate, re-score.
        $c->update(['skills' => ['React', 'TypeScript', 'Node']]);
        $second = $this->engine()->score($c->fresh(), $job);
        $recorder->record($c->fresh(), $second, 'candidate_updated');

        $history = AirPredictionHistory::where('candidate_id', $c->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertNull($history[0]->previous_score, 'First run has no prior score');
        $this->assertSame($first->overallScore, (int) $history[1]->previous_score);
        $this->assertSame($second->overallScore, (int) $history[1]->new_score);
        $this->assertSame('candidate_updated', $history[1]->trigger);

        // Still exactly one live row for this candidate+job.
        $this->assertSame(1, AirCandidateScore::where('candidate_id', $c->id)->count());
    }

    public function test_rescoring_reflects_updated_candidate_data(): void
    {
        $this->withoutConfidenceGate();
        $job = $this->fullyScorableJob();
        $c = $this->candidate(['job_posting_id' => $job->id, 'skills' => ['React']]);

        $before = $this->engine()->score($c->fresh(), $job)->overallScore;
        $c->update(['skills' => ['React', 'TypeScript', 'Node']]);
        $after = $this->engine()->score($c->fresh(), $job)->overallScore;

        $this->assertGreaterThan($before, $after,
            'Editing skills must change the score — the old engine never recalculated');
    }
}
