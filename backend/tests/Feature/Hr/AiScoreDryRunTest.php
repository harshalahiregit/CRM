<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\AirCandidateScore;
use App\Models\Hr\AirPredictionHistory;
use App\Models\Hr\AirScoringConfig;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Services\Hr\Scoring\Dimensions\SkillsDimension;
use App\Services\Hr\Scoring\RecommendationEngine;
use App\Services\Hr\Scoring\SkillMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 3: the dry-run command, the raised confidence floor, and the guard that
 * stops education/experience/salary text being mined as "skills".
 */
class AiScoreDryRunTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $posting = [], ?array $request = null): HrJobPosting
    {
        $job = HrJobPosting::create(array_merge([
            'tenant_id' => 1, 'title' => 'Senior React Developer', 'department' => 'Engineering',
            'location' => 'Bangalore', 'job_type' => 'Full-time', 'status' => 'Open',
            'requirements' => 'React, TypeScript, Node.js',
        ], $posting));

        if ($request !== null) {
            $mr = HrManpowerRequest::create(array_merge([
                'tenant_id' => 1, 'department' => 'Engineering', 'position_title' => 'Dev',
                'number_of_posts' => 1, 'status' => 'Approved',
            ], $request));
            $job->manpower_request_id = $mr->id;
            $job->save();
            $job->load('manpowerRequest');
        }

        return $job;
    }

    private function candidate(array $attrs = []): HrCandidate
    {
        return HrCandidate::create(array_merge([
            'tenant_id' => 1, 'name' => 'Test Candidate', 'email' => 't'.uniqid().'@test.com',
            'stage' => 'Applied',
        ], $attrs));
    }

    // ── 1. Dry run must not modify the database ──────────────────────────────

    public function test_dry_run_does_not_modify_the_database(): void
    {
        Queue::fake();
        Bus::fake();

        $job = $this->job();
        $c = $this->candidate([
            'job_posting_id' => $job->id, 'ai_score' => 87,
            'skills' => ['React', 'TypeScript'], 'experience_years' => 5, 'location' => 'Bangalore',
        ]);

        $beforeScore     = $c->ai_score;
        $beforeBreakdown = $c->ai_breakdown;
        $beforeUpdated   = $c->updated_at;

        $this->artisan('hr:ai-score', ['--dry-run' => true])->assertSuccessful();

        $after = $c->fresh();
        $this->assertSame($beforeScore, $after->ai_score, 'ai_score must not change');
        $this->assertSame($beforeBreakdown, $after->ai_breakdown, 'ai_breakdown must not change');
        $this->assertEquals($beforeUpdated, $after->updated_at, 'the row must not be touched');

        $this->assertSame(0, AirCandidateScore::count(), 'no score row may be written');
        $this->assertSame(0, AirPredictionHistory::count(), 'no history row may be written');

        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
    }

    public function test_dry_run_issues_no_write_statements_at_all(): void
    {
        $job = $this->job();
        $this->candidate(['job_posting_id' => $job->id, 'skills' => ['React'], 'experience_years' => 3]);

        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        $this->artisan('hr:ai-score', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame([], $writes, 'Dry run issued write SQL: '.implode(' | ', $writes));
    }

    public function test_command_refuses_to_run_without_the_dry_run_flag(): void
    {
        $this->artisan('hr:ai-score')->assertFailed();
    }

    // ── 2. Confidence below the threshold ────────────────────────────────────

    public function test_confidence_below_threshold_yields_insufficient_data(): void
    {
        // Only Location can score: 6 of 100 weight.
        $job = $this->job(['requirements' => null, 'location' => 'Bangalore']);
        $c   = $this->candidate(['location' => 'Bangalore']);

        $result = app(\App\Services\Hr\Scoring\CandidateScoringEngine::class)->score($c, $job);

        $this->assertLessThan(60, $result->confidence);
        $this->assertSame(RecommendationEngine::INSUFFICIENT_DATA, $result->recommendation);
        $this->assertNull($result->overallScore, 'A 6%-confidence score must not be published');
        $this->assertSame(100, $result->provisionalScore, 'The computed value is retained for diagnosis');
    }

    public function test_default_min_confidence_is_sixty(): void
    {
        // Both the stored default and the in-memory fallback must agree.
        $this->assertSame(60, (new AirScoringConfig())->thresholds()['min_confidence']);

        $stored = AirScoringConfig::create(['tenant_id' => 1, 'job_family' => 'General', 'is_active' => true]);
        $this->assertSame(60, (int) $stored->fresh()->min_confidence);
    }

    // ── 3. Skills fallback must reject non-skills ────────────────────────────

    /** The requirement that scored a qualified Financial Analyst at 0%. */
    public function test_finance_education_requirement_does_not_become_skills(): void
    {
        $job = $this->job(['requirements' => 'CA/MBA Finance, 3+ years']);
        $c   = $this->candidate(['skills' => ['Financial Analysis', 'Excel', 'SAP']]);

        $r = (new SkillsDimension())->score($c, $job);

        $this->assertNull($r->score, 'An education requirement must not be mined for skills');
        $this->assertNull(SkillMatcher::extractSkillList('CA/MBA Finance, 3+ years'));
    }

    /** @dataProvider nonSkillTextProvider */
    public function test_non_skill_text_is_rejected(string $text): void
    {
        $this->assertNull(SkillMatcher::extractSkillList($text), "'{$text}' must not parse as a skill list");
    }

    public static function nonSkillTextProvider(): array
    {
        return [
            'degree combo'     => ['CA/MBA Finance, 3+ years'],
            'degree preferred' => ['MBA preferred'],
            'experience only'  => ['5 years experience'],
            'experience plus'  => ['3+ years'],
            'salary'           => ['12 LPA CTC'],
            'bare title'       => ['Executive'],
            'pure filler'      => ['Strong knowledge required'],
            'empty'            => [''],
        ];
    }

    /** @dataProvider skillTextProvider */
    public function test_genuine_skill_lists_are_accepted(string $text, int $expectedCount): void
    {
        $parsed = SkillMatcher::extractSkillList($text);

        $this->assertNotNull($parsed, "'{$text}' should parse as a skill list");
        $this->assertCount($expectedCount, $parsed);
    }

    public static function skillTextProvider(): array
    {
        return [
            'plain list'        => ['Laravel, PHP, MySQL', 3],
            'js list'           => ['React, Node.js, TypeScript', 3],
            'with exp prefix'   => ['5+ years React, TypeScript, Node.js', 3],
            'domain phrase'     => ['2+ years B2B sales experience', 1],
        ];
    }

    // ── 4. Matching behaviour asked for explicitly ───────────────────────────

    public function test_react_js_matches_react(): void
    {
        $this->assertTrue(SkillMatcher::matches('React', 'React.js'));
    }

    public function test_react_native_does_not_match_react(): void
    {
        $this->assertFalse(SkillMatcher::matches('React Native', 'React'));
    }

    /** The alias fix from Phase 2 must survive the new guard. */
    public function test_b2b_sales_remains_valid(): void
    {
        $job = $this->job(['requirements' => '2+ years B2B sales experience']);
        $c   = $this->candidate(['skills' => ['B2B Sales', 'CRM', 'Negotiation']]);

        $r = (new SkillsDimension())->score($c, $job);

        $this->assertSame(100, $r->score, 'B2B Sales must still satisfy a B2B sales requirement');
    }

    /** Structured required_skills stay authoritative — the guard must not filter them. */
    public function test_structured_required_skills_bypass_the_text_guard(): void
    {
        $job = $this->job(['requirements' => 'MBA preferred'], ['required_skills' => ['React', 'Node']]);
        $c   = $this->candidate(['skills' => ['React', 'Node']]);

        $r = (new SkillsDimension())->score($c, $job);

        $this->assertSame(100, $r->score);
        $this->assertSame('manpower_request.required_skills', $r->evidence['required_source']);
    }
}
