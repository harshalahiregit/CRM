<?php

namespace Tests\Feature\Hr;

use App\Jobs\Hr\RecalculateCandidateScore;
use App\Models\Hr\AirCandidateScore;
use App\Models\Hr\AirPredictionHistory;
use App\Models\Hr\AirScoringConfig;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Services\Hr\CandidateService;
use App\Services\Hr\InterviewService;
use App\Services\Hr\JobPostingService;
use App\Services\Hr\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 4: the engine is wired to application events, and nothing else computes a
 * score. Every dispatch assertion uses Queue::fake()/Bus::fake() — no worker runs.
 */
class RecalculateCandidateScoreTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    /** users.tenant_id is a real FK, so the tenant row has to exist first. */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT, 999] as $id) {
            \App\Models\Tenant::create([
                'id' => $id, 'name' => 'Tenant '.$id, 'slug' => 'tenant-'.$id,
                'subdomain' => 'tenant'.$id, 'status' => 'active',
            ]);
        }
    }

    private function job(array $posting = [], ?array $request = null): HrJobPosting
    {
        $job = HrJobPosting::create(array_merge([
            'tenant_id' => self::TENANT, 'title' => 'Senior React Developer', 'department' => 'Engineering',
            'location' => 'Bangalore', 'job_type' => 'Full-time', 'status' => 'Open',
            'requirements' => 'React, TypeScript, Node.js',
        ], $posting));

        if ($request !== null) {
            $mr = HrManpowerRequest::create(array_merge([
                'tenant_id' => self::TENANT, 'department' => 'Engineering', 'position_title' => 'Dev',
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
            'tenant_id' => self::TENANT, 'name' => 'Test Candidate', 'email' => 't'.uniqid().'@test.com',
            'stage' => 'Applied',
        ], $attrs));
    }

    private function hrUser(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'HR Person', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    // ── 1 & 2. Candidate create / update ─────────────────────────────────────

    public function test_candidate_creation_dispatches_the_job(): void
    {
        Queue::fake();
        $job = $this->job();

        $c = app(CandidateService::class)->create([
            'name' => 'Alice', 'email' => 'alice@test.com', 'stage' => 'Applied',
            'job_posting_id' => $job->id, 'skills' => ['React'],
        ], self::TENANT);

        Queue::assertPushed(RecalculateCandidateScore::class, fn ($j) => $j->candidateId === $c->id
            && $j->tenantId === self::TENANT
            && $j->trigger === RecalculateCandidateScore::TRIGGER_CANDIDATE_CREATED);
    }

    /** Creation must no longer write a score inline — it starts unscored. */
    public function test_candidate_creation_writes_no_score_inline(): void
    {
        Queue::fake();
        $job = $this->job();

        $c = app(CandidateService::class)->create([
            'name' => 'Bob', 'email' => 'bob@test.com', 'stage' => 'Applied',
            'job_posting_id' => $job->id, 'skills' => ['React', 'TypeScript', 'Node'],
        ], self::TENANT);

        $this->assertNull($c->ai_score, 'create() must not compute a score');
        $this->assertNull($c->ai_breakdown);
    }

    /** A client-supplied ai_score must be ignored, not trusted. */
    public function test_candidate_creation_strips_a_client_supplied_score(): void
    {
        Queue::fake();

        $c = app(CandidateService::class)->create([
            'name' => 'Mallory', 'email' => 'm@test.com', 'stage' => 'Applied',
            'ai_score' => 99, 'ai_breakdown' => ['forged' => true],
        ], self::TENANT);

        $this->assertNull($c->fresh()->ai_score);
    }

    public function test_candidate_update_dispatches_when_a_scoring_input_changes(): void
    {
        Queue::fake();
        $c = $this->candidate(['skills' => ['React']]);

        app(CandidateService::class)->update($c, ['skills' => ['React', 'Node']]);

        Queue::assertPushed(RecalculateCandidateScore::class, fn ($j) => $j->candidateId === $c->id
            && $j->trigger === RecalculateCandidateScore::TRIGGER_CANDIDATE_UPDATED);
    }

    public function test_candidate_update_does_not_dispatch_for_unrelated_fields(): void
    {
        Queue::fake();
        $c = $this->candidate(['skills' => ['React'], 'phone' => '111']);

        app(CandidateService::class)->update($c, ['phone' => '222']);

        Queue::assertNotPushed(RecalculateCandidateScore::class);
    }

    // ── 3 & 4. Resume upload / delete ────────────────────────────────────────

    public function test_resume_upload_dispatches_the_job(): void
    {
        Queue::fake();
        Storage::fake('hr_resumes');
        $c = $this->candidate();

        app(ResumeService::class)->upload($c, self::TENANT, UploadedFile::fake()->create('cv.pdf', 100));

        Queue::assertPushed(RecalculateCandidateScore::class, fn ($j) => $j->candidateId === $c->id
            && $j->trigger === RecalculateCandidateScore::TRIGGER_RESUME_UPLOADED);
    }

    public function test_resume_delete_dispatches_the_job(): void
    {
        Queue::fake();
        Storage::fake('hr_resumes');
        $c = $this->candidate(['resume_path' => 'tenant_1/cv.pdf']);

        app(ResumeService::class)->delete($c, self::TENANT);

        Queue::assertPushed(RecalculateCandidateScore::class, fn ($j) => $j->candidateId === $c->id
            && $j->trigger === RecalculateCandidateScore::TRIGGER_RESUME_DELETED);
    }

    // ── 5. Interview feedback ────────────────────────────────────────────────

    public function test_interview_feedback_dispatches_the_job(): void
    {
        Queue::fake();
        $job = $this->job();
        $c   = $this->candidate(['job_posting_id' => $job->id]);

        $round = HrInterviewRound::create([
            'tenant_id' => self::TENANT, 'candidate_id' => $c->id, 'round_name' => 'Technical',
            'interviewer_name' => 'Panel', 'scheduled_at' => now()->subDay(), 'status' => 'Scheduled',
        ]);

        app(InterviewService::class)->recordFeedback($round, [
            'result' => 'Passed', 'overall_score' => 88, 'notes' => 'Strong',
        ]);

        Queue::assertPushed(RecalculateCandidateScore::class, fn ($j) => $j->candidateId === $c->id
            && $j->trigger === RecalculateCandidateScore::TRIGGER_INTERVIEW_COMPLETED);
    }

    // ── 6. Job requirement change fans out as a batch ────────────────────────

    public function test_job_requirement_change_creates_a_batch(): void
    {
        Bus::fake();
        $job = $this->job();
        $this->candidate(['job_posting_id' => $job->id]);
        $this->candidate(['job_posting_id' => $job->id]);

        app(JobPostingService::class)->update($job, ['requirements' => 'Vue, Nuxt, Pinia'], $this->hrUser());

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2
            && $batch->jobs->every(fn ($j) => $j instanceof RecalculateCandidateScore));
    }

    public function test_unrelated_job_edit_creates_no_batch(): void
    {
        Bus::fake();
        $job = $this->job();
        $this->candidate(['job_posting_id' => $job->id]);

        app(JobPostingService::class)->update($job, ['title' => 'Renamed Role'], $this->hrUser());

        Bus::assertNothingBatched();
    }

    /**
     * The requisition owns required_skills / experience_required / education /
     * salary, so editing it must fan out too. Reachable only if the Draft-or-Rejected
     * guard in ManpowerRequestService::update() is ever relaxed — the wiring exists so
     * that stale scores are not the silent default when it is.
     */
    public function test_requisition_scoring_field_change_fans_out_to_its_posting(): void
    {
        Bus::fake();

        $mr = HrManpowerRequest::create([
            'tenant_id' => self::TENANT, 'department' => 'Engineering', 'position_title' => 'Dev',
            'number_of_posts' => 1, 'status' => 'Draft', 'required_skills' => ['React'],
        ]);
        $posting = HrJobPosting::create([
            'tenant_id' => self::TENANT, 'title' => 'Dev', 'department' => 'Engineering',
            'location' => 'Bangalore', 'job_type' => 'Full-time', 'status' => 'Open',
            'manpower_request_id' => $mr->id,
        ]);
        $mr->update(['job_posting_id' => $posting->id]);
        $c = $this->candidate(['job_posting_id' => $posting->id]);

        app(\App\Services\Hr\ManpowerRequestService::class)
            ->update($mr->fresh(), ['required_skills' => ['Vue', 'Nuxt']], $this->hrUser());

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
            && $batch->jobs->first()->candidateId === $c->id
            && $batch->jobs->first()->trigger === RecalculateCandidateScore::TRIGGER_JOB_UPDATED);
    }

    public function test_requisition_edit_of_a_non_scoring_field_creates_no_batch(): void
    {
        Bus::fake();

        $mr = HrManpowerRequest::create([
            'tenant_id' => self::TENANT, 'department' => 'Engineering', 'position_title' => 'Dev',
            'number_of_posts' => 1, 'status' => 'Draft', 'required_skills' => ['React'],
        ]);
        $posting = HrJobPosting::create([
            'tenant_id' => self::TENANT, 'title' => 'Dev', 'department' => 'Engineering',
            'location' => 'Bangalore', 'job_type' => 'Full-time', 'status' => 'Open',
            'manpower_request_id' => $mr->id,
        ]);
        $mr->update(['job_posting_id' => $posting->id]);
        $this->candidate(['job_posting_id' => $posting->id]);

        app(\App\Services\Hr\ManpowerRequestService::class)
            ->update($mr->fresh(), ['justification' => 'Backfill'], $this->hrUser());

        Bus::assertNothingBatched();
    }

    // ── 7 & 9. The job runs the engine, records, and mirrors ─────────────────

    public function test_job_runs_the_engine_and_persists_through_the_recorder(): void
    {
        AirScoringConfig::create([
            'tenant_id' => self::TENANT, 'job_family' => 'Engineering', 'is_active' => true,
            'is_default' => true, 'min_confidence' => 0,
        ]);

        $job = $this->job([], ['required_skills' => ['React', 'TypeScript', 'Node']]);
        $c   = $this->candidate(['job_posting_id' => $job->id, 'skills' => ['React', 'TypeScript', 'Node']]);

        (new RecalculateCandidateScore($c->id, self::TENANT, RecalculateCandidateScore::TRIGGER_MANUAL))
            ->handle(app(\App\Services\Hr\Scoring\CandidateScoringEngine::class), app(\App\Services\Hr\Scoring\ScoreRecorder::class));

        $row = AirCandidateScore::where('candidate_id', $c->id)->firstOrFail();
        $this->assertSame(100, (int) $row->skills_score);
        $this->assertNull($row->resume_score, 'unavailable stays NULL');
        $this->assertSame(RecalculateCandidateScore::TRIGGER_MANUAL, $row->scored_trigger);
        $this->assertSame(1, AirPredictionHistory::where('candidate_id', $c->id)->count());

        // 9. Backward-compatible mirror for the untouched frontend.
        $fresh = $c->fresh();
        $this->assertSame((int) $row->overall_score, (int) $fresh->ai_score);
        $this->assertSame('air.job_fit.v1', $fresh->ai_breakdown['engine']);
        $this->assertCount(10, $fresh->ai_breakdown['dimensions']);
    }

    /**
     * The mirror carries the engine's contract and NOTHING else. The legacy aliases
     * (skills_match / exp_match / overall_fit / question_score ...) existed only while
     * the UI still read them; every consumer now reads `dimensions`, so their presence
     * would mean a second, drifting contract had survived.
     */
    public function test_ai_breakdown_mirror_carries_no_legacy_aliases(): void
    {
        AirScoringConfig::create([
            'tenant_id' => self::TENANT, 'job_family' => 'Engineering', 'is_active' => true,
            'is_default' => true, 'min_confidence' => 0,
        ]);

        $job = $this->job([], ['required_skills' => ['React'], 'experience_required' => '3 years']);
        $c   = $this->candidate([
            'job_posting_id' => $job->id, 'skills' => ['React'],
            'experience_years' => 5, 'location' => 'Bangalore',
        ]);

        (new RecalculateCandidateScore($c->id, self::TENANT, RecalculateCandidateScore::TRIGGER_MANUAL))
            ->handle(app(\App\Services\Hr\Scoring\CandidateScoringEngine::class), app(\App\Services\Hr\Scoring\ScoreRecorder::class));

        $b = $c->fresh()->ai_breakdown;

        foreach ([
            'skills_match', 'skill_match', 'exp_match', 'location_match', 'jd_match',
            'education_match', 'education', 'resume_match', 'question_match',
            'question_score', 'overall_fit', 'reasons', 'recommendation_reason',
        ] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $b, "legacy alias '{$legacy}' must be gone");
        }

        $this->assertSame(
            ['overall', 'confidence', 'recommendation', 'dimensions', 'strengths', 'weaknesses', 'risk_flags', 'summary', 'engine'],
            array_keys($b),
            'The mirror must carry exactly the engine contract'
        );
        $this->assertCount(10, $b['dimensions']);
    }

    // ── 8. The old formulas are gone ─────────────────────────────────────────

    public function test_old_scoring_methods_no_longer_exist(): void
    {
        $rc = new \ReflectionClass(CandidateService::class);

        foreach ([
            'computeAiScore', 'evaluateApplication', 'scoreEducation',
            'scoreScreeningAnswers', 'skillReason', 'educationReason',
        ] as $method) {
            $this->assertFalse($rc->hasMethod($method), "CandidateService::{$method}() must be removed");
        }
    }

    public function test_only_the_recorder_writes_scores(): void
    {
        // A guard against a second writer creeping back in: no HR service other than
        // the scoring namespace may assign ai_score.
        $offenders = [];
        foreach (glob(base_path('app/Services/Hr/*.php')) as $file) {
            $src = file_get_contents($file);
            if (preg_match("/'ai_score'\s*=>/", $src) && ! preg_match('/Scoring/', $file)) {
                // Reads inside array payloads are fine; assignment into a model is not.
                if (preg_match("/(update|fill|forceFill|create)\s*\(\s*\[[^]]*'ai_score'\s*=>/s", $src)) {
                    $offenders[] = basename($file);
                }
            }
        }
        $this->assertSame([], $offenders, 'Non-engine writers found: '.implode(', ', $offenders));
    }

    // ── 10. Tenant isolation ─────────────────────────────────────────────────

    public function test_job_refuses_a_candidate_from_another_tenant(): void
    {
        $job = $this->job([], ['required_skills' => ['React']]);
        $c   = $this->candidate(['job_posting_id' => $job->id, 'skills' => ['React']]);

        // Queued under tenant 1, executed claiming tenant 2.
        (new RecalculateCandidateScore($c->id, 999, RecalculateCandidateScore::TRIGGER_MANUAL))
            ->handle(app(\App\Services\Hr\Scoring\CandidateScoringEngine::class), app(\App\Services\Hr\Scoring\ScoreRecorder::class));

        $this->assertSame(0, AirCandidateScore::where('candidate_id', $c->id)->count(),
            'A cross-tenant recalculation must write nothing');
        $this->assertNull($c->fresh()->ai_score);
    }

    public function test_job_posting_fan_out_only_touches_its_own_tenant(): void
    {
        Bus::fake();
        $job = $this->job();
        $mine = $this->candidate(['job_posting_id' => $job->id]);
        // Same posting id claimed by a different tenant — must not be swept in.
        HrCandidate::create([
            'tenant_id' => 999, 'name' => 'Other Tenant', 'email' => 'other@test.com',
            'stage' => 'Applied', 'job_posting_id' => $job->id,
        ]);

        app(JobPostingService::class)->update($job, ['requirements' => 'Go, Rust'], $this->hrUser());

        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1
            && $batch->jobs->first()->candidateId === $mine->id);
    }
}
