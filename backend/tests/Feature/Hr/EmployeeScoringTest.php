<?php

namespace Tests\Feature\Hr;

use App\Contracts\AI\AIProviderInterface;
use App\Exceptions\AIException;
use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrEmployeeScore;
use App\Models\Hr\HrEmployeeScoreHistory;
use App\Models\Hr\HrEmployeeTraining;
use App\Models\Hr\HrPerformanceReview;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeInsightService;
use App\Services\Hr\Scoring\Employee\EmployeeScoreRecorder;
use App\Services\Hr\Scoring\Employee\EmployeeScoringEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Review comments #39 (employee overall score) and #40 (positive / improvement /
 * risk factors).
 *
 * The properties that make the number trustworthy, not just present:
 *   - a dimension with no data is DROPPED, never scored zero;
 *   - below the confidence floor NO score is published;
 *   - recalculation appends to history and never rewrites it;
 *   - every insight is backed by evidence from a scored dimension.
 */
class EmployeeScoringTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        $this->actor = User::create([
            'tenant_id' => self::TENANT, 'name' => 'HR', 'email' => 'hr'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Asha Rao',
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => now()->subYears(3)->toDateString(), 'status' => 'Active',
        ], $attrs));
    }

    private function review(HrEmployee $e, float $rating, string $status = 'Approved'): HrPerformanceReview
    {
        return HrPerformanceReview::create([
            'tenant_id' => self::TENANT, 'employee_id' => $e->id,
            'reviewer_name' => 'Manager', 'review_type' => 'Annual',
            'period_month' => 3, 'period_year' => 2026, 'period_label' => 'Q1 2026',
            'overall_rating' => $rating, 'status' => $status,
        ]);
    }

    /** hr_attendance is unique on (tenant, employee, date), so days never repeat. */
    private int $dayOffset = 0;

    private function attendance(HrEmployee $e, string $status, int $days = 1): void
    {
        for ($i = 0; $i < $days; $i++) {
            HrAttendance::create([
                'tenant_id' => self::TENANT, 'employee_id' => $e->id,
                'date' => now()->subDays(++$this->dayOffset)->toDateString(), 'status' => $status,
            ]);
        }
    }

    /** A training assignment, with the master rows its NOT NULL columns require. */
    private function training(HrEmployee $e, string $status, ?string $dueDate = null): HrEmployeeTraining
    {
        $category = \App\Models\Hr\HrTrainingCategory::firstOrCreate(
            ['tenant_id' => self::TENANT, 'code' => 'GEN'], ['name' => 'General']);
        $type = \App\Models\Hr\HrTrainingType::firstOrCreate(
            ['tenant_id' => self::TENANT, 'code' => 'CLS'], ['name' => 'Classroom']);
        $provider = \App\Models\Hr\HrTrainingProvider::firstOrCreate(
            ['tenant_id' => self::TENANT, 'name' => 'In-house']);
        $program = \App\Models\Hr\HrTrainingProgram::firstOrCreate(
            ['tenant_id' => self::TENANT, 'program_code' => 'P1'],
            ['program_name' => 'Induction', 'category_id' => $category->id,
             'training_type_id' => $type->id, 'provider_id' => $provider->id]);
        $session = \App\Models\Hr\HrTrainingSession::firstOrCreate(
            ['tenant_id' => self::TENANT, 'training_program_id' => $program->id],
            ['trainer_name' => 'Trainer', 'start_at' => now()->subMonth(), 'end_at' => now()->subMonth()->addHours(4)]);

        return HrEmployeeTraining::create([
            'tenant_id' => self::TENANT, 'employee_id' => $e->id,
            'training_program_id' => $program->id, 'training_session_id' => $session->id,
            'status' => $status, 'due_date' => $dueDate,
        ]);
    }

    /** A probation record, with the policy/type its NOT NULL columns require. */
    private function probation(HrEmployee $e, string $status, int $extensions = 0): HrEmployeeProbation
    {
        $type = \App\Models\Hr\HrProbationType::firstOrCreate(
            ['tenant_id' => self::TENANT, 'code' => 'STD'], ['name' => 'Standard']);
        $policy = \App\Models\Hr\HrProbationPolicy::firstOrCreate(
            ['tenant_id' => self::TENANT, 'name' => 'Std Policy'], ['probation_type_id' => $type->id]);

        return HrEmployeeProbation::create([
            'tenant_id' => self::TENANT, 'employee_id' => $e->id,
            'probation_policy_id' => $policy->id, 'probation_type_id' => $type->id,
            'joining_date' => now()->subMonths(6)->toDateString(),
            'probation_start_date' => now()->subMonths(6)->toDateString(),
            'probation_end_date' => now()->subMonths(3)->toDateString(),
            'current_status' => $status, 'extension_count' => $extensions,
        ]);
    }

    private function engine(): EmployeeScoringEngine
    {
        return app(EmployeeScoringEngine::class);
    }

    /* ── #39 — the honesty rules ──────────────────────────────────────── */

    public function test_an_employee_with_no_data_gets_no_score_rather_than_zero(): void
    {
        // Only tenure is measurable (3% of the weight) — far below the floor.
        $employee = $this->employee();

        $result = $this->engine()->score($employee);

        $this->assertNull($result->overallScore, 'a score built on 3% of the weight is fabrication');
        $this->assertNotNull($result->provisionalScore, 'the provisional value is kept for diagnosis');
        $this->assertLessThan(EmployeeScoringEngine::MIN_CONFIDENCE, $result->confidence);
        $this->assertStringContainsString('no overall score yet', $result->summary);
    }

    public function test_an_unmeasurable_dimension_is_dropped_not_scored_zero(): void
    {
        $employee = $this->employee();

        $result = $this->engine()->score($employee);
        $byKey  = collect($result->dimensions)->keyBy('key');

        // No reviews, no training, no attendance → all unavailable, with a reason.
        foreach (['performance', 'training', 'attendance'] as $key) {
            $this->assertFalse($byKey[$key]->isScored(), "{$key} must be unavailable, not 0");
            $this->assertNotSame('', $byKey[$key]->reason, "{$key} must say WHY it could not be measured");
        }

        // …and none of them contributed weight.
        $this->assertArrayNotHasKey('performance', $result->appliedWeights);
    }

    public function test_an_unlinked_employee_is_not_penalised_for_having_no_tasks(): void
    {
        // user_id is null for every employee in this product unless linked.
        $employee = $this->employee(['user_id' => null]);

        $contribution = collect($this->engine()->score($employee)->dimensions)
            ->firstWhere('key', 'contribution');

        $this->assertFalse($contribution->isScored());
        $this->assertStringContainsString('no linked user account', $contribution->reason);
    }

    public function test_a_well_documented_employee_gets_a_score(): void
    {
        $employee = $this->employee();
        $this->review($employee, 4.5);
        $this->attendance($employee, 'Present', 20);
        $this->training($employee, HrEmployeeTraining::COMPLETED);

        $result = $this->engine()->score($employee);

        $this->assertNotNull($result->overallScore);
        $this->assertGreaterThanOrEqual(EmployeeScoringEngine::MIN_CONFIDENCE, $result->confidence);
        $this->assertContains($result->band, ['Excellent', 'Strong', 'Steady', 'Needs Support', 'At Risk']);
    }

    public function test_a_draft_review_is_not_counted(): void
    {
        // A draft rating is a manager's work in progress, not a company judgement.
        $employee = $this->employee();
        $this->review($employee, 5.0, 'Draft');

        $performance = collect($this->engine()->score($employee)->dimensions)
            ->firstWhere('key', 'performance');

        $this->assertFalse($performance->isScored());
    }

    public function test_approved_leave_days_do_not_lower_the_attendance_score(): void
    {
        $employee = $this->employee();
        $this->attendance($employee, 'Present', 10);
        $this->attendance($employee, 'Leave', 5);      // approved leave
        $this->attendance($employee, 'Holiday', 3);

        $attendance = collect($this->engine()->score($employee)->dimensions)
            ->firstWhere('key', 'attendance');

        // 10 working days, all present — leave and holidays leave the denominator.
        $this->assertSame(10, $attendance->evidence['working_days']);
        $this->assertSame(100, $attendance->score);
    }

    public function test_the_skill_gap_reuses_the_shared_matcher(): void
    {
        $designation = HrDesignation::create([
            'tenant_id' => self::TENANT, 'name' => 'Engineer', 'code' => 'ENG',
            'is_active' => true, 'skills' => ['PHP', 'Laravel', 'React', 'SQL'],
        ]);
        $employee = $this->employee(['designation_id' => $designation->id, 'skills' => ['PHP', 'Laravel']]);

        $skill = collect($this->engine()->score($employee)->dimensions)->firstWhere('key', 'skill_fit');

        $this->assertSame(50, $skill->score);            // 2 of 4
        $this->assertEqualsCanonicalizing(['React', 'SQL'], $skill->evidence['missing']);
    }

    public function test_probation_still_in_progress_has_no_outcome_to_score(): void
    {
        $employee = $this->employee();
        $this->probation($employee, HrEmployeeProbation::ACTIVE);

        $probation = collect($this->engine()->score($employee)->dimensions)->firstWhere('key', 'probation');

        $this->assertFalse($probation->isScored());
        $this->assertStringContainsString('still in progress', $probation->reason);
    }

    public function test_scoring_writes_nothing(): void
    {
        // score() must be a safe dry run.
        $employee = $this->employee();
        $this->review($employee, 4.0);

        $this->engine()->score($employee);

        $this->assertSame(0, HrEmployeeScore::count());
        $this->assertSame(0, HrEmployeeScoreHistory::count());
    }

    /* ── #39 — history and recalculation ──────────────────────────────── */

    public function test_recalculation_appends_history_and_never_overwrites_it(): void
    {
        $employee = $this->employee();
        $this->review($employee, 2.0);
        $this->attendance($employee, 'Present', 20);

        $recorder = app(EmployeeScoreRecorder::class);
        $recorder->record($employee, $this->engine()->score($employee), 'manual', $this->actor);
        $first = HrEmployeeScore::first()->overall_score;

        // The employee improves, and is rescored.
        $this->review($employee, 5.0);
        $recorder->record($employee, $this->engine()->score($employee->fresh()), 'manual', $this->actor);

        // One CURRENT row, two history rows — the old score is still readable.
        $this->assertSame(1, HrEmployeeScore::count());
        $this->assertSame(2, HrEmployeeScoreHistory::count());

        $history = HrEmployeeScoreHistory::orderBy('id')->get();
        $this->assertNull($history[0]->previous_score, 'the first run had nothing before it');
        $this->assertSame($first, $history[1]->previous_score, 'the second run records what it replaced');
    }

    public function test_history_reports_the_delta(): void
    {
        $employee = $this->employee();
        $this->review($employee, 2.0);
        $this->attendance($employee, 'Present', 20);

        $recorder = app(EmployeeScoreRecorder::class);
        $recorder->record($employee, $this->engine()->score($employee), 'manual', $this->actor);
        $this->review($employee, 5.0);
        $recorder->record($employee, $this->engine()->score($employee->fresh()), 'scheduled', $this->actor);

        $rows = $recorder->history($employee);

        $this->assertNotNull($rows[0]['delta'], 'the trend is why history is kept');
        $this->assertGreaterThan(0, $rows[0]['delta']);
        $this->assertSame('scheduled', $rows[0]['trigger']);
    }

    /* ── #40 — insights ───────────────────────────────────────────────── */

    private function fakeAi(?string $response): void
    {
        $this->app->bind(AIProviderInterface::class, fn () => new class($response) implements AIProviderInterface
        {
            public function __construct(private ?string $response)
            {
            }

            public function complete(string $prompt, array $options = []): string
            {
                if ($this->response === null) {
                    throw new AIException('provider down');
                }

                return $this->response;
            }

            public function name(): string
            {
                return 'stub';
            }

            public function model(): string
            {
                return 'stub-1';
            }
        });
    }

    public function test_strengths_are_derived_from_high_scoring_dimensions(): void
    {
        $this->fakeAi('A short note.');
        $employee = $this->employee();
        $this->review($employee, 5.0);
        $this->attendance($employee, 'Present', 20);

        $score = app(EmployeeInsightService::class)->generate($employee, withAi: false);

        $this->assertNotEmpty($score->positives);
        // Every positive names the dimension and carries its evidence.
        foreach ($score->positives as $p) {
            $this->assertArrayHasKey('evidence', $p);
            $this->assertGreaterThanOrEqual(75, $p['score']);
        }
    }

    public function test_a_skill_gap_becomes_an_actionable_improvement(): void
    {
        $this->fakeAi('A short note.');
        $designation = HrDesignation::create([
            'tenant_id' => self::TENANT, 'name' => 'Engineer', 'code' => 'ENG',
            'is_active' => true, 'skills' => ['PHP', 'Laravel', 'React', 'SQL'],
        ]);
        $employee = $this->employee(['designation_id' => $designation->id, 'skills' => ['PHP']]);

        $score = app(EmployeeInsightService::class)->generate($employee, withAi: false);

        $gap = collect($score->improvements)->firstWhere('key', 'skill_fit');
        $this->assertNotNull($gap);
        // "improve skill fit" is not an action; the missing skills are.
        $this->assertStringContainsString('Close the skill gap', $gap['action']);
    }

    public function test_overdue_training_is_reported_as_a_compliance_risk(): void
    {
        $this->fakeAi('A short note.');
        $employee = $this->employee();
        $this->training($employee, HrEmployeeTraining::ASSIGNED, now()->subMonth()->toDateString());

        $score = app(EmployeeInsightService::class)->generate($employee, withAi: false);

        $risk = collect($score->risks)->firstWhere('key', 'training_compliance');
        $this->assertNotNull($risk);
        $this->assertSame('medium', $risk['severity']);
    }

    public function test_high_absence_is_reported_as_an_attendance_risk(): void
    {
        $this->fakeAi('A short note.');
        $employee = $this->employee();
        $this->attendance($employee, 'Present', 15);
        $this->attendance($employee, 'Absent', 5);      // 25% absent

        $score = app(EmployeeInsightService::class)->generate($employee, withAi: false);

        $risk = collect($score->risks)->firstWhere('key', 'attendance');
        $this->assertNotNull($risk);
        $this->assertSame('high', $risk['severity']);
        // The fact, not a prediction.
        $this->assertStringContainsString('% of working days', $risk['detail']);
    }

    public function test_insights_work_with_no_ai_at_all(): void
    {
        $this->fakeAi(null);   // provider throws
        $employee = $this->employee();
        $this->review($employee, 4.5);
        $this->attendance($employee, 'Present', 20);

        $score = app(EmployeeInsightService::class)->generate($employee, withAi: true);

        // The facts survive; only the prose is missing.
        $this->assertNotEmpty($score->positives);
        $this->assertNull($score->insight_narrative);
        $this->assertSame('rules', $score->insight_source);
    }

    public function test_an_ai_narrative_records_its_provenance(): void
    {
        $this->fakeAi('Asha is performing strongly and attends reliably.');
        $employee = $this->employee();
        $this->review($employee, 4.5);
        $this->attendance($employee, 'Present', 20);

        $score = app(EmployeeInsightService::class)->generate($employee, withAi: true);

        $this->assertSame('ai', $score->insight_source);
        $this->assertSame('stub', $score->insight_meta['provider']);
        // The exact facts the prose was written from, so it can be checked.
        $this->assertArrayHasKey('facts', $score->insight_meta);
        $this->assertNotNull($score->insights_generated_at);
    }

    public function test_regenerating_insights_does_not_wipe_the_score(): void
    {
        $this->fakeAi('A short note.');
        $employee = $this->employee();
        $this->review($employee, 4.5);
        $this->attendance($employee, 'Present', 20);

        app(EmployeeScoreRecorder::class)->record($employee, $this->engine()->score($employee), 'manual', $this->actor);
        $scored = HrEmployeeScore::first()->overall_score;

        app(EmployeeInsightService::class)->generate($employee, withAi: false);

        $this->assertSame($scored, HrEmployeeScore::first()->overall_score);
    }

    public function test_recalculating_does_not_wipe_insights(): void
    {
        $this->fakeAi('A short note.');
        $employee = $this->employee();
        $this->review($employee, 4.5);
        $this->attendance($employee, 'Present', 20);

        app(EmployeeInsightService::class)->generate($employee, withAi: true);
        app(EmployeeScoreRecorder::class)->record($employee, $this->engine()->score($employee), 'manual', $this->actor);

        // A recalculation must not blank insights the manager is still reading.
        $this->assertNotEmpty(HrEmployeeScore::first()->positives);
        $this->assertNotNull(HrEmployeeScore::first()->insight_narrative);
    }

    /* ── Endpoints ────────────────────────────────────────────────────── */

    public function test_the_endpoints_score_and_report(): void
    {
        Sanctum::actingAs($this->actor);
        $employee = $this->employee();
        $this->review($employee, 4.0);
        $this->attendance($employee, 'Present', 20);

        // A read never computes.
        $this->getJson("/api/hr/employees/{$employee->id}/score")
            ->assertOk()->assertJsonPath('scored', false);

        $this->postJson("/api/hr/employees/{$employee->id}/score/recalculate")
            ->assertOk()->assertJsonStructure(['score' => ['overall_score', 'confidence', 'band', 'dimensions'], 'history']);

        $this->getJson("/api/hr/employees/{$employee->id}/score")
            ->assertOk()->assertJsonPath('scored', true);
    }

    public function test_preview_is_a_dry_run(): void
    {
        Sanctum::actingAs($this->actor);
        $employee = $this->employee();
        $this->review($employee, 4.0);

        $this->getJson("/api/hr/employees/{$employee->id}/score/preview")->assertOk();

        $this->assertSame(0, HrEmployeeScore::count(), 'preview must not persist');
    }

    public function test_scores_require_hr_permission(): void
    {
        $employee = $this->employee();
        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Peer', 'email' => 'p'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]));

        // A score and a risk factor are judgements about a person.
        $this->getJson("/api/hr/employees/{$employee->id}/score")->assertStatus(403);
        $this->postJson("/api/hr/employees/{$employee->id}/insights")->assertStatus(403);
    }

    public function test_another_tenants_employee_is_not_reachable(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'T2', 'slug' => 't2', 'subdomain' => 't2', 'status' => 'active',
        ])->save();
        $foreign = $this->employee(['tenant_id' => 2]);

        Sanctum::actingAs($this->actor);

        $this->getJson("/api/hr/employees/{$foreign->id}/score")->assertStatus(404);
    }
}
