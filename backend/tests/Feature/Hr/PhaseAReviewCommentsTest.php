<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrDesignation;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeMovement;
use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrGrade;
use App\Models\Hr\HrManpowerRequest;
use App\Models\Hr\HrProbationPolicy;
use App\Models\Hr\HrProbationType;
use App\Models\Hr\HrPromotionRecommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeMovementService;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\EmployeeSkillService;
use App\Services\Hr\ManpowerRequestService;
use App\Support\Hr\SkillMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase A of the original 45 review comments:
 *   #5  Skills / hiring details / JD mandatory
 *   #36 Probation mandatory when adding an employee
 *   #41 Department transfer
 *   #42 Promotion / demotion action
 *   #43 Skills on the org masters + individual fit score
 */
class PhaseAReviewCommentsTest extends TestCase
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

    /* ── fixtures ─────────────────────────────────────────────────────── */

    private function probationPolicy(): HrProbationPolicy
    {
        $type = HrProbationType::create([
            'tenant_id' => self::TENANT, 'code' => 'STD', 'name' => 'Standard',
            'default_duration_days' => 90, 'confirmation_required' => true,
            'review_required' => true, 'extension_allowed' => true, 'max_extensions' => 2, 'is_active' => true,
        ]);

        return HrProbationPolicy::create([
            'tenant_id' => self::TENANT, 'name' => 'Default Policy', 'probation_type_id' => $type->id,
            'applies_to' => 'All', 'review_frequency' => 'Monthly', 'is_active' => true,
        ]);
    }

    private function employee(array $attrs = []): HrEmployee
    {
        return HrEmployee::create(array_merge([
            'tenant_id' => self::TENANT, 'name' => 'Mover', 'employee_code' => 'MV-'.uniqid(),
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ], $attrs));
    }

    /* ══ #43 — skills on masters + fit score ══════════════════════════ */

    public function test_skill_matcher_is_case_and_punctuation_insensitive(): void
    {
        // "Node.js", "node js" and "NodeJS" are one skill typed three ways — a
        // literal comparison would report two false gaps.
        $r = SkillMatcher::compare(['Node.js', 'React'], ['node js', 'REACT']);

        $this->assertEquals(100.0, $r['score']);
        $this->assertSame([], $r['missing']);
    }

    public function test_skill_matcher_reports_matched_missing_and_extra(): void
    {
        $r = SkillMatcher::compare(['PHP', 'React', 'Kubernetes'], ['PHP', 'Go']);

        $this->assertEqualsWithDelta(33.3, $r['score'], 0.1);
        $this->assertSame(['PHP'], $r['matched']);
        $this->assertSame(['React', 'Kubernetes'], $r['missing']);
        $this->assertSame(['Go'], $r['extra']);
    }

    public function test_a_position_with_no_skills_scores_null_not_zero(): void
    {
        $r = SkillMatcher::compare([], ['PHP']);

        // Nobody said what is expected, so there is nothing to fall short of.
        $this->assertNull($r['score']);
    }

    public function test_the_masters_carry_a_skill_profile(): void
    {
        $dept = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Engineering', 'skills' => ['PHP', 'React']]);
        $desig = HrDesignation::create(['tenant_id' => self::TENANT, 'name' => 'Engineer', 'skills' => ['PHP', 'Docker']]);

        $this->assertSame(['PHP', 'React'], $dept->fresh()->skills);
        $this->assertSame(['PHP', 'Docker'], $desig->fresh()->skills);
    }

    public function test_an_employee_is_scored_against_every_master_separately_and_combined(): void
    {
        $dept  = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Engineering', 'skills' => ['PHP', 'React']]);
        $desig = HrDesignation::create(['tenant_id' => self::TENANT, 'name' => 'Engineer', 'skills' => ['PHP', 'Docker']]);

        $employee = $this->employee([
            'department_id' => $dept->id, 'designation_id' => $desig->id,
            'skills' => ['PHP', 'React'],
        ]);

        $result = app(EmployeeSkillService::class)->analyse($employee, self::TENANT);

        $byType = collect($result['sources'])->keyBy('type');
        $this->assertEquals(100.0, $byType['department']['score'], 'has both department skills');
        $this->assertEquals(50.0, $byType['designation']['score'], 'missing Docker');
        $this->assertSame(['Docker'], $byType['designation']['missing']);

        // Union of {PHP,React,Docker}: two of three held.
        $this->assertEqualsWithDelta(66.7, $result['overall']['score'], 0.1);
        $this->assertSame(['Docker'], $result['overall']['missing']);
    }

    public function test_skill_fit_can_be_previewed_for_a_position_not_yet_held(): void
    {
        $target = HrDesignation::create(['tenant_id' => self::TENANT, 'name' => 'Architect', 'skills' => ['PHP', 'Kubernetes']]);
        $employee = $this->employee(['skills' => ['PHP']]);

        $preview = app(EmployeeSkillService::class)->preview($employee->id, self::TENANT, ['designation_id' => $target->id]);

        $this->assertEquals(50.0, $preview['overall']['score']);
        $this->assertSame(['Kubernetes'], $preview['overall']['missing']);
    }

    public function test_an_employee_with_no_configured_expectations_is_marked_unconfigured(): void
    {
        $employee = $this->employee(['skills' => ['PHP']]);

        $result = app(EmployeeSkillService::class)->analyse($employee, self::TENANT);

        $this->assertFalse($result['configured']);
        $this->assertNull($result['overall']['score']);
    }

    /* ══ #41 / #42 — transfer, promotion, demotion ════════════════════ */

    public function test_a_transfer_moves_the_employee_and_records_history(): void
    {
        $to = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Operations']);
        $employee = $this->employee();

        $movement = app(EmployeeMovementService::class)->move([
            'employee_id' => $employee->id, 'to_department_id' => $to->id,
            'effective_date' => '2026-05-01', 'reason' => 'Team restructure',
        ], self::TENANT, $this->actor);

        $this->assertSame(HrEmployeeMovement::TRANSFER, $movement['movement_type']);
        $this->assertSame('Engineering → Operations', $movement['summary']);

        // The employee record and the history must agree — one without the other
        // would make the movement log a lie.
        $this->assertSame('Operations', $employee->fresh()->department);
        $this->assertSame($to->id, (int) $employee->fresh()->department_id);
    }

    public function test_a_grade_increase_is_classified_as_a_promotion(): void
    {
        $junior = HrGrade::create(['tenant_id' => self::TENANT, 'name' => 'G1', 'level' => 1]);
        $senior = HrGrade::create(['tenant_id' => self::TENANT, 'name' => 'G2', 'level' => 2]);
        $employee = $this->employee(['grade_id' => $junior->id]);

        $movement = app(EmployeeMovementService::class)->move([
            'employee_id' => $employee->id, 'to_grade_id' => $senior->id, 'effective_date' => '2026-05-01',
        ], self::TENANT, $this->actor);

        $this->assertSame(HrEmployeeMovement::PROMOTION, $movement['movement_type']);
    }

    public function test_a_grade_decrease_is_classified_as_a_demotion(): void
    {
        $junior = HrGrade::create(['tenant_id' => self::TENANT, 'name' => 'G1', 'level' => 1]);
        $senior = HrGrade::create(['tenant_id' => self::TENANT, 'name' => 'G2', 'level' => 2]);
        $employee = $this->employee(['grade_id' => $senior->id]);

        $movement = app(EmployeeMovementService::class)->move([
            'employee_id' => $employee->id, 'to_grade_id' => $junior->id, 'effective_date' => '2026-05-01',
        ], self::TENANT, $this->actor);

        // "upgrade or degrade position" — the comment asks for both directions.
        $this->assertSame(HrEmployeeMovement::DEMOTION, $movement['movement_type']);
    }

    public function test_an_explicit_type_overrides_the_inferred_one(): void
    {
        $to = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Operations']);
        $employee = $this->employee();

        $movement = app(EmployeeMovementService::class)->move([
            'employee_id' => $employee->id, 'to_department_id' => $to->id,
            'movement_type' => HrEmployeeMovement::PROMOTION, 'effective_date' => '2026-05-01',
        ], self::TENANT, $this->actor);

        $this->assertSame(HrEmployeeMovement::PROMOTION, $movement['movement_type']);
    }

    public function test_a_movement_that_changes_nothing_is_refused(): void
    {
        $employee = $this->employee();

        $this->expectExceptionMessage('Nothing would change');
        app(EmployeeMovementService::class)->move([
            'employee_id' => $employee->id, 'to_department' => 'Engineering', 'effective_date' => '2026-05-01',
        ], self::TENANT, $this->actor);
    }

    public function test_history_accumulates_in_reverse_order(): void
    {
        $ops = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Operations']);
        $fin = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Finance']);
        $employee = $this->employee();
        $service = app(EmployeeMovementService::class);

        $service->move(['employee_id' => $employee->id, 'to_department_id' => $ops->id, 'effective_date' => '2026-05-01'], self::TENANT, $this->actor);
        $service->move(['employee_id' => $employee->id, 'to_department_id' => $fin->id, 'effective_date' => '2026-09-01'], self::TENANT, $this->actor);

        $history = $service->history($employee->id, self::TENANT);

        $this->assertCount(2, $history);
        $this->assertSame('Finance', $history[0]['to_department']);
        $this->assertSame('Operations', $history[0]['from_department'], 'the second move starts where the first ended');
    }

    public function test_a_promotion_recommendation_can_be_actioned_and_is_then_closed(): void
    {
        $employee = $this->employee();
        HrDesignation::create(['tenant_id' => self::TENANT, 'name' => 'Senior Engineer']);

        $rec = HrPromotionRecommendation::create([
            'tenant_id' => self::TENANT, 'employee_id' => $employee->id, 'eligible' => true,
            'current_designation' => 'Engineer', 'recommended_designation' => 'Senior Engineer',
            'reason' => 'Consistently exceeds goals', 'status' => 'Pending',
        ]);

        $movement = app(EmployeeMovementService::class)
            ->actionRecommendation($rec->id, ['effective_date' => '2026-06-01'], self::TENANT, $this->actor);

        $this->assertSame('Senior Engineer', $movement['to_designation']);
        $this->assertTrue($movement['from_recommendation']);
        $this->assertSame('Senior Engineer', $employee->fresh()->designation);
        $this->assertSame('Actioned', $rec->fresh()->status);
    }

    public function test_the_same_recommendation_cannot_be_actioned_twice(): void
    {
        $employee = $this->employee();
        $rec = HrPromotionRecommendation::create([
            'tenant_id' => self::TENANT, 'employee_id' => $employee->id, 'eligible' => true,
            'current_designation' => 'Engineer', 'recommended_designation' => 'Senior Engineer', 'status' => 'Pending',
        ]);

        app(EmployeeMovementService::class)->actionRecommendation($rec->id, [], self::TENANT, $this->actor);

        $this->expectExceptionMessage('already been actioned');
        app(EmployeeMovementService::class)->actionRecommendation($rec->id, [], self::TENANT, $this->actor);
    }

    public function test_a_movement_returns_the_skill_fit_for_the_new_position(): void
    {
        $to = HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Operations', 'skills' => ['Excel', 'SAP']]);
        $employee = $this->employee(['skills' => ['Excel']]);

        $movement = app(EmployeeMovementService::class)->move([
            'employee_id' => $employee->id, 'to_department_id' => $to->id, 'effective_date' => '2026-05-01',
        ], self::TENANT, $this->actor);

        $this->assertEquals(50.0, $movement['skill_analysis']['overall']['score']);
        $this->assertSame(['SAP'], $movement['skill_analysis']['overall']['missing']);
    }

    /* ══ #36 — probation mandatory when adding an employee ════════════ */

    public function test_an_employee_created_through_the_service_gets_a_probation(): void
    {
        $policy = $this->probationPolicy();

        $employee = app(EmployeeService::class)->create([
            'name' => 'New Hire', 'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => '2026-04-01', 'status' => 'Active',
            'probation_policy_id' => $policy->id,
        ], self::TENANT, $this->actor);

        $probation = HrEmployeeProbation::where('employee_id', $employee->id)->first();

        $this->assertNotNull($probation, 'the add-employee process is not complete without it');
        $this->assertSame(HrEmployeeProbation::ASSIGNED, $probation->current_status);
        $this->assertSame('2026-06-30', $probation->probation_end_date->toDateString(), '90 days from joining');
    }

    public function test_the_employee_is_not_created_at_all_when_probation_fails(): void
    {
        // No probation policy exists, and the caller did not mark the hire exempt.
        $before = HrEmployee::count();

        try {
            app(EmployeeService::class)->create([
                'name' => 'Doomed Hire', 'department' => 'Engineering', 'designation' => 'Engineer',
                'joining_date' => '2026-04-01', 'status' => 'Active',
            ], self::TENANT, $this->actor);
            $this->fail('Expected the create to be refused.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('probation policy', $e->getMessage());
        }

        // The transaction must roll the employee back — a half-created employee
        // with no probation is exactly the state the comment is about.
        $this->assertSame($before, HrEmployee::count());
    }

    public function test_an_exempt_hire_is_allowed_but_the_exemption_is_recorded(): void
    {
        $employee = app(EmployeeService::class)->create([
            'name' => 'Rehire', 'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => '2026-04-01', 'status' => 'Active',
            'skip_probation' => true, 'probation_skip_reason' => 'Re-hire, already confirmed',
        ], self::TENANT, $this->actor);

        $this->assertNull(HrEmployeeProbation::where('employee_id', $employee->id)->first());
        $this->assertTrue($employee->auditLogs()->where('action', 'Probation Skipped')->exists(),
            'an exemption must be visible, not silent');
    }

    public function test_the_api_refuses_an_employee_with_neither_policy_nor_exemption(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/employees', [
            'name' => 'No Probation', 'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => '2026-04-01',
        ])->assertStatus(422)->assertJsonValidationErrors('probation_policy_id');
    }

    public function test_the_api_refuses_an_exemption_with_no_reason(): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->actor);

        $this->postJson('/api/hr/employees', [
            'name' => 'No Reason', 'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => '2026-04-01', 'skip_probation' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('probation_skip_reason');
    }

    /* ══ #5 — JD / skills / hiring details mandatory ══════════════════ */

    private function manpowerRequest(array $attrs = []): HrManpowerRequest
    {
        return HrManpowerRequest::create(array_merge([
            'tenant_id' => self::TENANT, 'department' => 'Engineering',
            'position_title' => 'Engineer', 'position' => 'Engineer',
            'number_of_positions' => 1, 'status' => 'Draft', 'l1_status' => 'pending', 'l2_status' => 'pending',
            'requested_by' => $this->actor->id,
        ], $attrs));
    }

    public function test_an_incomplete_request_can_still_be_SAVED_as_a_draft(): void
    {
        // A draft is a work in progress — blocking the save would stop someone
        // putting the request down and coming back to it.
        $mr = $this->manpowerRequest();

        $this->assertSame('Draft', $mr->status);
    }

    public function test_submitting_an_incomplete_request_is_refused_and_names_every_gap(): void
    {
        $mr = $this->manpowerRequest();

        try {
            app(ManpowerRequestService::class)->submit($mr, $this->actor);
            $this->fail('Expected submission to be refused.');
        } catch (\App\Exceptions\BusinessException $e) {
            // All at once — sending someone round the loop one field at a time is
            // its own defect.
            foreach (['Job description', 'Required skills', 'Hiring manager',
                      'Employee level', 'Experience required'] as $label) {
                $this->assertStringContainsString($label, $e->getMessage());
            }
        }

        $this->assertSame('Draft', $mr->fresh()->status, 'it stays a draft');
    }

    public function test_a_complete_request_submits(): void
    {
        $manager = $this->employee();
        $mr = $this->manpowerRequest([
            'job_description'     => 'Build and maintain the platform.',
            'required_skills'     => ['PHP', 'React'],
            'hiring_manager_id'   => $manager->id,
            'employee_level'      => 'Mid',
            'experience_required' => '3-5 years',
        ]);

        $result = app(ManpowerRequestService::class)->submit($mr, $this->actor);

        $this->assertNotSame('Draft', $result->status);
    }

    public function test_the_gate_names_only_what_is_actually_missing(): void
    {
        $manager = $this->employee();
        $mr = $this->manpowerRequest([
            'job_description'   => 'Build things.',
            'hiring_manager_id' => $manager->id,
            'employee_level'    => 'Mid',
            'experience_required' => '3-5 years',
        ]);

        try {
            app(ManpowerRequestService::class)->submit($mr, $this->actor);
            $this->fail('Expected submission to be refused.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('Required skills', $e->getMessage());
            $this->assertStringNotContainsString('Job description', $e->getMessage());
        }
    }
}
