<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
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
use App\Services\Hr\Scoring\SkillMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dimension-level tests.
 *
 * The single most important assertion in this file is repeated for every dimension:
 * MISSING DATA RETURNS NULL. The engine this replaces fabricated 70 for education,
 * 50 for skills, 60 for location and 100 for screening, and those constants
 * dominated the result. A regression here would quietly reintroduce that.
 */
class ScoringDimensionsTest extends TestCase
{
    use RefreshDatabase;

    private function job(array $posting = [], ?array $request = null): HrJobPosting
    {
        $job = HrJobPosting::create(array_merge([
            'tenant_id' => 1, 'title' => 'Senior React Developer', 'department' => 'Engineering',
            'location' => 'Bangalore', 'job_type' => 'Full-time', 'status' => 'Open',
            'requirements' => '5+ years React, TypeScript, Node.js',
        ], $posting));

        if ($request !== null) {
            $mr = HrManpowerRequest::create(array_merge([
                'tenant_id' => 1, 'department' => 'Engineering', 'position_title' => 'Senior React Developer',
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

    // ── Skills ───────────────────────────────────────────────────────────────

    public function test_skills_scores_full_coverage_of_required_skills(): void
    {
        $job = $this->job([], ['required_skills' => ['React', 'TypeScript', 'Node']]);
        $c   = $this->candidate(['skills' => ['React', 'TypeScript', 'Node']]);

        $r = (new SkillsDimension())->score($c, $job);

        $this->assertSame(100, $r->score);
        $this->assertSame([], $r->evidence['missing']);
    }

    public function test_skills_scores_partial_coverage_and_names_the_gaps(): void
    {
        $job = $this->job([], ['required_skills' => ['React', 'TypeScript', 'Node', 'GraphQL']]);
        $c   = $this->candidate(['skills' => ['React', 'TypeScript']]);

        $r = (new SkillsDimension())->score($c, $job);

        $this->assertSame(50, $r->score);
        $this->assertEqualsCanonicalizing(['Node', 'GraphQL'], $r->evidence['missing']);
    }

    /** The exact bug that made a React developer score 40%. */
    public function test_skills_normalises_react_js_to_react(): void
    {
        $job = $this->job([], ['required_skills' => ['React', 'Node']]);
        $c   = $this->candidate(['skills' => ['React.js', 'Node.js']]);

        $this->assertSame(100, (new SkillsDimension())->score($c, $job)->score);
    }

    /** The denominator must be required skills, never the candidate's own count. */
    public function test_skills_listing_extra_skills_does_not_lower_the_score(): void
    {
        $job  = $this->job([], ['required_skills' => ['React', 'Node']]);
        $lean = $this->candidate(['skills' => ['React', 'Node']]);
        $wide = $this->candidate(['skills' => ['React', 'Node', 'Figma', 'Excel', 'Cooking', 'Chess']]);

        $d = new SkillsDimension();
        $this->assertSame($d->score($lean, $job)->score, $d->score($wide, $job)->score);
    }

    public function test_skills_is_null_when_the_role_lists_no_requirements(): void
    {
        $job = $this->job(['requirements' => null]);
        $c   = $this->candidate(['skills' => ['React']]);

        $r = (new SkillsDimension())->score($c, $job);

        $this->assertNull($r->score, 'No requirement must NOT fall back to a 50 baseline');
    }

    public function test_skills_is_null_when_candidate_has_no_skills(): void
    {
        $job = $this->job([], ['required_skills' => ['React']]);
        $this->assertNull((new SkillsDimension())->score($this->candidate(), $job)->score);
    }

    // ── Experience ───────────────────────────────────────────────────────────

    public function test_experience_at_or_above_requirement_scores_full(): void
    {
        $job = $this->job([], ['experience_required' => '4+ years']);

        $d = new ExperienceDimension();
        $this->assertSame(100, $d->score($this->candidate(['experience_years' => 4]), $job)->score);
        $this->assertSame(100, $d->score($this->candidate(['experience_years' => 9]), $job)->score);
    }

    public function test_experience_below_requirement_scales_with_the_shortfall(): void
    {
        $job = $this->job([], ['experience_required' => '4 years']);

        $r = (new ExperienceDimension())->score($this->candidate(['experience_years' => 2]), $job);
        $this->assertSame(50, $r->score);
    }

    public function test_experience_is_null_without_a_stated_requirement(): void
    {
        $job = $this->job([], ['experience_required' => null]);
        $r = (new ExperienceDimension())->score($this->candidate(['experience_years' => 6]), $job);

        $this->assertNull($r->score, 'Six years must not score 100 against an unstated requirement');
    }

    public function test_experience_is_null_when_candidate_has_none_recorded(): void
    {
        $job = $this->job([], ['experience_required' => '3 years']);
        $this->assertNull((new ExperienceDimension())->score($this->candidate(), $job)->score);
    }

    // ── Education ────────────────────────────────────────────────────────────

    public function test_education_matches_candidate_record_against_requirement(): void
    {
        $job = $this->job([], ['education' => 'MBA']);
        $c   = $this->candidate(['education' => [['degree' => 'MBA', 'institution' => 'IIM']]]);

        $this->assertSame(100, (new EducationDimension())->score($c, $job)->score);
    }

    /** Was hardcoded to 70 and excluded from the weighted sum. */
    public function test_education_is_null_when_candidate_has_none(): void
    {
        $job = $this->job([], ['education' => 'MBA']);
        $r = (new EducationDimension())->score($this->candidate(), $job);

        $this->assertNull($r->score, 'Education must never fall back to the hardcoded 70');
    }

    public function test_education_is_null_when_the_role_states_no_requirement(): void
    {
        $job = $this->job([], ['education' => null]);
        $c   = $this->candidate(['education' => [['degree' => 'MBA']]]);

        $this->assertNull((new EducationDimension())->score($c, $job)->score);
    }

    // ── Location ─────────────────────────────────────────────────────────────

    public function test_location_same_city_scores_full(): void
    {
        $job = $this->job(['location' => 'Bangalore']);
        $c   = $this->candidate(['location' => 'Bangalore, KA']);

        $this->assertSame(100, (new LocationDimension())->score($c, $job)->score);
    }

    public function test_location_remote_role_is_scored_regardless_of_candidate_city(): void
    {
        $job = $this->job(['location' => 'Bangalore', 'work_mode' => 'Remote']);
        $c   = $this->candidate(['location' => 'Chennai']);

        $this->assertSame(100, (new LocationDimension())->score($c, $job)->score);
    }

    public function test_location_different_city_scores_low_not_null(): void
    {
        $job = $this->job(['location' => 'Bangalore']);
        $r = (new LocationDimension())->score($this->candidate(['location' => 'Chennai']), $job);

        $this->assertNotNull($r->score);
        $this->assertLessThan(50, $r->score);
    }

    public function test_location_is_null_when_candidate_location_missing(): void
    {
        $job = $this->job(['location' => 'Bangalore']);
        $r = (new LocationDimension())->score($this->candidate(), $job);

        $this->assertNull($r->score, 'Location must not fall back to the hardcoded 60');
    }

    // ── Salary ───────────────────────────────────────────────────────────────

    public function test_salary_within_band_scores_full(): void
    {
        $job = $this->job(['salary_from' => 1000000, 'salary_to' => 1500000]);
        $c   = $this->candidate(['expected_ctc' => 1200000]);

        $this->assertSame(100, (new SalaryDimension())->score($c, $job)->score);
    }

    public function test_salary_above_band_decays_with_the_overrun(): void
    {
        $job = $this->job(['salary_from' => 1000000, 'salary_to' => 1000000]);

        $d = new SalaryDimension();
        // 25% over -> halfway to the 50% cutoff -> 50.
        $this->assertSame(50, $d->score($this->candidate(['expected_ctc' => 1250000]), $job)->score);
        // 50%+ over -> 0.
        $this->assertSame(0, $d->score($this->candidate(['expected_ctc' => 1600000]), $job)->score);
    }

    public function test_salary_is_null_without_expectation_or_band(): void
    {
        $withBand = $this->job(['salary_from' => 100, 'salary_to' => 200]);
        $noBand   = $this->job(['salary_from' => null, 'salary_to' => null]);

        $d = new SalaryDimension();
        $this->assertNull($d->score($this->candidate(), $withBand)->score);
        $this->assertNull($d->score($this->candidate(['expected_ctc' => 500000]), $noBand)->score);
    }

    // ── Notice period ────────────────────────────────────────────────────────

    public function test_notice_within_window_scores_full(): void
    {
        $job = $this->job([], ['target_joining_date' => now()->addDays(60)->toDateString()]);
        $c   = $this->candidate(['notice_period' => '30 days']);

        $this->assertSame(100, (new NoticePeriodDimension())->score($c, $job)->score);
    }

    public function test_notice_beyond_window_scores_lower(): void
    {
        $job = $this->job([], ['target_joining_date' => now()->addDays(30)->toDateString()]);
        $c   = $this->candidate(['notice_period' => '3 months']);

        $r = (new NoticePeriodDimension())->score($c, $job);
        $this->assertNotNull($r->score);
        $this->assertLessThan(100, $r->score);
    }

    public function test_notice_is_null_when_text_cannot_be_parsed(): void
    {
        $job = $this->job([], ['target_joining_date' => now()->addDays(30)->toDateString()]);
        $c   = $this->candidate(['notice_period' => 'negotiable']);

        $this->assertNull((new NoticePeriodDimension())->score($c, $job)->score);
    }

    public function test_notice_is_null_without_a_target_joining_date(): void
    {
        $job = $this->job([], ['target_joining_date' => null]);
        $c   = $this->candidate(['notice_period' => '30 days']);

        $this->assertNull((new NoticePeriodDimension())->score($c, $job)->score);
    }

    // ── Resume / Screening / Interview ───────────────────────────────────────

    /** A stored file is not evidence of fit. */
    public function test_resume_is_always_null_while_no_parser_exists(): void
    {
        $job = $this->job();
        $d = new ResumeDimension();

        $this->assertNull($d->score($this->candidate(['resume_path' => 'x/y.pdf']), $job)->score);
        $this->assertNull($d->score($this->candidate(), $job)->score);
    }

    /** Previously returned 100 for the 25 of 26 jobs with no questions. */
    public function test_screening_is_null_when_the_job_has_no_questions(): void
    {
        $job = $this->job(['screening_questions' => []]);
        $r = (new ScreeningDimension())->score($this->candidate(), $job);

        $this->assertNull($r->score, 'No questions must NOT score 100');
    }

    public function test_screening_is_null_when_candidate_has_not_answered(): void
    {
        $job = $this->job(['screening_questions' => [['id' => 'q1', 'type' => 'yes_no']]]);
        $this->assertNull((new ScreeningDimension())->score($this->candidate(), $job)->score);
    }

    public function test_screening_grades_against_an_answer_key_when_present(): void
    {
        $job = $this->job(['screening_questions' => [
            ['id' => 'q1', 'type' => 'yes_no', 'expected_answer' => 'yes'],
            ['id' => 'q2', 'type' => 'yes_no', 'expected_answer' => 'yes'],
        ]]);
        $c = $this->candidate(['screening_answers' => [
            ['question_id' => 'q1', 'value' => 'yes'],
            ['question_id' => 'q2', 'value' => 'no'],
        ]]);

        $r = (new ScreeningDimension())->score($c, $job);
        $this->assertSame(50, $r->score);
        $this->assertSame(2, $r->evidence['graded']);
    }

    public function test_interview_is_null_without_a_completed_scored_round(): void
    {
        $this->assertNull((new InterviewDimension())->score($this->candidate(), $this->job())->score);
    }

    // ── Skill matcher ────────────────────────────────────────────────────────

    public function test_skill_matcher_equivalences(): void
    {
        $this->assertTrue(SkillMatcher::matches('React', 'React.js'));
        $this->assertTrue(SkillMatcher::matches('React', 'react js'));
        $this->assertTrue(SkillMatcher::matches('Node', 'Node.js'));
        $this->assertTrue(SkillMatcher::matches('JavaScript', 'JS'));
        $this->assertTrue(SkillMatcher::matches('Kubernetes', 'k8s'));
    }

    public function test_skill_matcher_does_not_over_match(): void
    {
        $this->assertFalse(SkillMatcher::matches('Python', 'Java'));
        $this->assertFalse(SkillMatcher::matches('Excel', ''));
    }

    /**
     * Matching is directional: matches(required, candidate). A specialisation on the
     * candidate side satisfies a broader requirement, but not the reverse — listing
     * "React" must not clear a "React Native" requirement.
     */
    public function test_skill_matcher_is_directional(): void
    {
        $this->assertFalse(SkillMatcher::matches('React Native', 'React'));
        $this->assertTrue(SkillMatcher::matches('React', 'React Developer'));
    }
}
