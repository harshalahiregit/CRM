<?php

namespace Tests\Feature\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrEmployeeVariableEarning;
use App\Models\Hr\HrExitQuestionnaire;
use App\Models\Hr\HrExitType;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\ExitInterviewService;
use App\Services\Hr\ExitQuestionnaireService;
use App\Services\Hr\JobPublishingService;
use App\Services\Hr\OrgChartService;
use App\Services\Hr\PayrollService;
use App\Services\Hr\Publishing\IndeedChannel;
use App\Services\Hr\Publishing\LinkedInChannel;
use App\Services\Hr\Publishing\NaukriChannel;
use App\Services\Hr\Statutory\StatutoryEngine;
use App\Services\Hr\Statutory\StatutoryRuleService;
use App\Services\Hr\VariableEarningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase D2 of the original 45 review comments:
 *   #12 Publish Channels — complete the remaining boards
 *   #29 Organization Chart
 *   #30 WCP + Mediclaim
 *   #31 Commission / Incentives
 *   #44 Exit Questionnaire types
 */
class PhaseD2ReviewCommentsTest extends TestCase
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
            'tenant_id' => self::TENANT, 'name' => 'Emp'.uniqid(),
            'employee_code' => 'E'.substr(uniqid(), -6),
            'department' => 'Engineering', 'designation' => 'Engineer',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ], $attrs));
    }

    /* ── #12 — remaining publish channels ─────────────────────────────── */

    public function test_every_board_is_now_an_integrated_channel(): void
    {
        $channels = collect(app(JobPublishingService::class)->channels())->keyBy('key');

        foreach (['careers', 'linkedin', 'naukri', 'indeed', 'trulytalents'] as $key) {
            $this->assertTrue($channels[$key]['integrated'],
                "{$key} should no longer be a 'Coming Soon' placeholder");
        }
    }

    public function test_every_rest_board_can_report_status(): void
    {
        $syncable = app(JobPublishingService::class)->syncableChannels();

        foreach (['linkedin', 'naukri', 'indeed', 'trulytalents'] as $key) {
            $this->assertContains($key, $syncable);
        }

        // The career portal is our own page, not a board with an API to poll.
        $this->assertNotContains('careers', $syncable);
    }

    public function test_each_board_names_its_own_env_var_when_unconfigured(): void
    {
        foreach ([[LinkedInChannel::class, 'LINKEDIN_BASE_URL'],
                  [NaukriChannel::class, 'NAUKRI_BASE_URL'],
                  [IndeedChannel::class, 'INDEED_BASE_URL']] as [$class, $env]) {
            try {
                app($class)->publish(new \App\Models\Hr\HrJobPosting(['id' => 1, 'title' => 'X']));
                $this->fail("{$class} should refuse to publish while unconfigured");
            } catch (\Throwable $e) {
                $this->assertStringContainsString($env, $e->getMessage());
            }
        }
    }

    public function test_a_board_renames_payload_fields_from_config_not_code(): void
    {
        config()->set('hr_publishing.linkedin.base_url', 'https://api.linkedin.test');
        config()->set('hr_publishing.linkedin.api_key', 'k');
        Http::fake(['*' => Http::response(['id' => 'LI-1', 'url' => 'https://li.test/1'], 201)]);

        app(LinkedInChannel::class)->publish($this->jobPosting());

        Http::assertSent(function ($request) {
            $body = $request->data();

            // field_map renamed these; the canonical names must be gone.
            return isset($body['jobTitle']) && ! isset($body['title'])
                && isset($body['externalJobPostingId']);
        });
    }

    public function test_the_board_payload_carries_salary_skills_and_experience(): void
    {
        // Regression: the payload originally read salary_min / required_skills /
        // experience_required / number_of_positions, none of which exist on
        // hr_job_postings — so every board received a posting with no salary, no
        // skills, no experience and no headcount, silently dropped by array_filter.
        $mr = \App\Models\Hr\HrManpowerRequest::create([
            'tenant_id' => self::TENANT, 'department' => 'Engineering',
            'position_title' => 'Engineer', 'position' => 'Engineer',
            'number_of_positions' => 1, 'status' => 'Job_Posted', 'requested_by' => $this->actor->id,
            'required_skills' => ['PHP'], 'experience_required' => '3-5 years',
        ]);
        $job = \App\Models\Hr\HrJobPosting::create([
            'tenant_id' => self::TENANT, 'manpower_request_id' => $mr->id,
            'title' => 'Engineer', 'department' => 'Engineering', 'location' => 'Pune',
            'status' => 'Published', 'number_of_openings' => 3,
            'salary_from' => 50000, 'salary_to' => 90000, 'description' => 'Build things.',
        ]);

        config()->set('hr_publishing.naukri.base_url', 'https://api.naukri.test');
        config()->set('hr_publishing.naukri.api_key', 'k');
        Http::fake(['*' => Http::response(['data' => ['jobId' => 'NK-1']], 201)]);

        app(NaukriChannel::class)->publish($job);

        Http::assertSent(function ($request) {
            $b = $request->data();

            return ($b['salary_min'] ?? null) == 50000
                && ($b['salary_max'] ?? null) == 90000
                && ($b['skills'] ?? null) === ['PHP']
                && ($b['experienceRange'] ?? null) === '3-5 years'   // field_map renamed
                && ($b['vacancies'] ?? null) == 3;                    // field_map renamed
        });
    }

    public function test_a_boards_own_status_vocabulary_is_config_driven(): void
    {
        config()->set('hr_publishing.linkedin.base_url', 'https://api.linkedin.test');
        config()->set('hr_publishing.linkedin.api_key', 'k');
        Http::fake(['*' => Http::response(['status' => 'LISTED'], 200)]);

        // "LISTED" means nothing to the default map — status_map teaches it.
        $result = app(LinkedInChannel::class)->syncStatus($this->jobPosting(), 'LI-1');

        $this->assertSame('published', $result['status']);
    }

    private function jobPosting(): \App\Models\Hr\HrJobPosting
    {
        return \App\Models\Hr\HrJobPosting::create([
            'tenant_id' => self::TENANT, 'title' => 'Engineer', 'department' => 'Engineering',
            'location' => 'Pune', 'status' => 'Published', 'number_of_positions' => 1,
        ]);
    }

    /* ── #29 — organisation chart ─────────────────────────────────────── */

    public function test_the_chart_is_built_from_the_reporting_manager_column(): void
    {
        $ceo  = $this->employee(['name' => 'CEO']);
        $lead = $this->employee(['name' => 'Lead', 'reporting_manager_id' => $ceo->id]);
        $this->employee(['name' => 'Dev', 'reporting_manager_id' => $lead->id]);

        $tree = app(OrgChartService::class)->tree(self::TENANT);

        $this->assertCount(1, $tree['roots']);
        $this->assertSame('CEO', $tree['roots'][0]['name']);
        $this->assertSame(3, $tree['total']);
        $this->assertSame(3, $tree['max_depth']);
        // The whole sub-tree, not just direct reports.
        $this->assertSame(2, $tree['roots'][0]['reports_count']);
        $this->assertSame(1, $tree['roots'][0]['direct_count']);
    }

    public function test_consultants_and_freelancers_appear_alongside_employees(): void
    {
        $head = $this->employee(['name' => 'Head']);
        $this->employee(['name' => 'Consultant A', 'worker_type' => 'consultant', 'reporting_manager_id' => $head->id]);
        $this->employee(['name' => 'Freelancer B', 'worker_type' => 'freelancer', 'reporting_manager_id' => $head->id]);

        $tree = app(OrgChartService::class)->tree(self::TENANT);

        $this->assertSame(['employee' => 1, 'consultant' => 1, 'freelancer' => 1], $tree['legend']);
        $this->assertSame(2, $tree['roots'][0]['direct_count']);
    }

    public function test_someone_excluded_from_the_chart_is_left_out(): void
    {
        $this->employee(['name' => 'Shown']);
        $this->employee(['name' => 'Hidden', 'include_in_org_chart' => false]);

        $tree = app(OrgChartService::class)->tree(self::TENANT);

        $this->assertSame(1, $tree['total']);
        $this->assertSame('Shown', $tree['roots'][0]['name']);
    }

    public function test_a_reporting_cycle_is_reported_instead_of_hanging(): void
    {
        $a = $this->employee(['name' => 'A']);
        $b = $this->employee(['name' => 'B', 'reporting_manager_id' => $a->id]);
        $a->update(['reporting_manager_id' => $b->id]);   // A → B → A

        $tree = app(OrgChartService::class)->tree(self::TENANT);

        // The point of the test is that we reach this line at all.
        $this->assertNotEmpty($tree['issues']);
        $this->assertSame('reporting_cycle', $tree['issues'][0]['type']);
        $this->assertSame(2, $tree['total'], 'nobody may disappear from the chart because of a cycle');
    }

    public function test_an_unreachable_manager_leaves_the_report_at_the_top_not_missing(): void
    {
        $manager = $this->employee(['name' => 'Manager', 'status' => 'Inactive']);
        $this->employee(['name' => 'Orphan', 'reporting_manager_id' => $manager->id]);

        $tree = app(OrgChartService::class)->tree(self::TENANT);

        $this->assertSame(1, $tree['total']);
        $this->assertSame('Orphan', $tree['roots'][0]['name']);
        $this->assertSame('missing_manager', $tree['issues'][0]['type']);
    }

    public function test_the_org_chart_endpoint_responds(): void
    {
        Sanctum::actingAs($this->actor);
        $this->employee(['name' => 'Solo']);

        $this->getJson('/api/hr/org-chart')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonStructure(['roots', 'total', 'max_depth', 'issues', 'legend']);
    }

    /* ── #30 — WCP + Mediclaim ────────────────────────────────────────── */

    private function premiumRule(string $type, array $config): HrStatutoryRule
    {
        return HrStatutoryRule::create([
            'tenant_id' => self::TENANT, 'rule_type' => $type, 'effective_from' => '2020-01-01',
            'config' => $config, 'is_active' => true,
        ]);
    }

    private function earningLines(float $gross = 50000): array
    {
        return [[
            'code' => 'BASIC', 'name' => 'Basic', 'type' => 'Earning',
            'computed_amount' => $gross, 'taxable' => true,
            'pf_applicable' => true, 'esic_applicable' => true,
        ]];
    }

    public function test_a_percentage_premium_splits_between_employee_and_employer(): void
    {
        $this->premiumRule('mediclaim', ['mode' => 'percentage', 'employee_rate' => 1, 'employer_rate' => 2]);

        $s = app(StatutoryEngine::class)->forSalary($this->earningLines(50000), self::TENANT);

        $this->assertEquals(500, $s['mediclaim_employee']);
        $this->assertEquals(1000, $s['mediclaim_employer']);
    }

    public function test_a_flat_premium_with_only_an_amount_is_borne_by_the_employer(): void
    {
        // WCP is usually wholly an employer cost; a bare `amount` must not be
        // quietly deducted from someone's pay.
        $this->premiumRule('wcp', ['mode' => 'fixed', 'amount' => 300]);

        $s = app(StatutoryEngine::class)->forSalary($this->earningLines(), self::TENANT);

        $this->assertEquals(0, $s['wcp_employee']);
        $this->assertEquals(300, $s['wcp_employer']);
    }

    public function test_only_the_employee_share_is_deducted_from_pay(): void
    {
        $this->premiumRule('wcp', ['mode' => 'fixed', 'employee_amount' => 100, 'employer_amount' => 400]);
        $this->premiumRule('mediclaim', ['mode' => 'fixed', 'employee_amount' => 250, 'employer_amount' => 750]);

        $s = app(StatutoryEngine::class)->forSalary($this->earningLines(), self::TENANT);

        // 100 + 250; the 1,150 of employer money must not touch the deduction total.
        $this->assertEquals(350, $s['statutory_deductions']);
    }

    public function test_a_premium_above_its_ceiling_does_not_apply(): void
    {
        $this->premiumRule('mediclaim', ['mode' => 'percentage', 'employee_rate' => 1, 'gross_threshold' => 20000]);

        $s = app(StatutoryEngine::class)->forSalary($this->earningLines(50000), self::TENANT);

        $this->assertEquals(0, $s['mediclaim_employee']);
        $this->assertStringContainsString('ceiling', $s['statutory_meta']['mediclaim']);
    }

    public function test_an_unconfigured_premium_is_zero_and_says_why(): void
    {
        $s = app(StatutoryEngine::class)->forSalary($this->earningLines(), self::TENANT);

        $this->assertEquals(0, $s['wcp_employee']);
        $this->assertEquals(0, $s['mediclaim_employer']);
        $this->assertStringContainsString('not configured', $s['statutory_meta']['wcp']);
    }

    public function test_a_premium_rule_with_no_rates_is_refused(): void
    {
        $this->expectException(BusinessException::class);

        app(StatutoryRuleService::class)->create([
            'rule_type' => 'wcp', 'effective_from' => '2026-01-01',
            'config' => ['mode' => 'percentage'],
        ], self::TENANT, $this->actor);
    }

    public function test_the_new_rule_types_are_accepted_by_the_master(): void
    {
        foreach (['wcp', 'mediclaim'] as $type) {
            $this->assertContains($type, HrStatutoryRule::TYPES);
        }

        $rule = app(StatutoryRuleService::class)->create([
            'rule_type' => 'mediclaim', 'effective_from' => '2026-01-01',
            'config' => ['mode' => 'fixed', 'employee_amount' => 200],
        ], self::TENANT, $this->actor);

        $this->assertSame('mediclaim', $rule['rule_type']);
    }

    /* ── #31 — commissions and incentives ─────────────────────────────── */

    private function employeeWithSalary(float $gross = 60000): HrEmployee
    {
        $employee = $this->employee();

        $component = HrSalaryComponent::create([
            'tenant_id' => self::TENANT, 'name' => 'Basic', 'code' => 'BASIC', 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true, 'taxable' => true,
        ]);
        $structure = HrSalaryStructure::create([
            'tenant_id' => self::TENANT, 'name' => 'Std', 'code' => 'STD', 'is_active' => true,
        ]);
        $structure->lines()->create([
            'component_id' => $component->id, 'amount' => $gross, 'calculation_type' => 'Fixed', 'sort_order' => 1,
        ]);
        HrEmployeeSalary::create([
            'tenant_id' => self::TENANT, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-01-01',
            'annual_ctc' => $gross * 12, 'monthly_ctc' => $gross, 'gross_salary' => $gross,
            'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => $gross,
            'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $employee;
    }

    private function commissionComponent(): HrSalaryComponent
    {
        return HrSalaryComponent::create([
            'tenant_id' => self::TENANT, 'name' => 'Sales Commission', 'code' => 'COMM',
            'type' => 'Earning', 'calculation_type' => 'Manual', 'is_active' => true,
            'taxable' => true, 'pf_applicable' => false, 'esic_applicable' => false,
        ]);
    }

    private function commission(HrEmployee $employee, float $amount, string $period = '2026-04', bool $approve = true): HrEmployeeVariableEarning
    {
        $service = app(VariableEarningService::class);
        $earning = $service->save([
            'employee_id' => $employee->id, 'component_id' => $this->commissionComponent()->id,
            'period' => $period, 'amount' => $amount,
        ], self::TENANT, $this->actor);

        return $approve ? $service->approve($earning->id, self::TENANT, $this->actor) : $earning;
    }

    private function runPayroll(int $month = 4): \App\Models\Hr\HrPayrollRun
    {
        $run = \App\Models\Hr\HrPayrollRun::create([
            'tenant_id' => self::TENANT, 'payroll_month' => $month, 'payroll_year' => 2026,
            'status' => \App\Models\Hr\HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, self::TENANT, $this->actor);

        return $run->refresh();
    }

    public function test_a_commission_must_sit_on_an_earning_component(): void
    {
        $employee = $this->employeeWithSalary();
        $deduction = HrSalaryComponent::create([
            'tenant_id' => self::TENANT, 'name' => 'Canteen', 'code' => 'CANT', 'type' => 'Deduction',
            'calculation_type' => 'Fixed', 'is_active' => true,
        ]);

        $this->expectException(BusinessException::class);
        app(VariableEarningService::class)->save([
            'employee_id' => $employee->id, 'component_id' => $deduction->id,
            'period' => '2026-04', 'amount' => 5000,
        ], self::TENANT, $this->actor);
    }

    public function test_only_an_approved_commission_is_paid(): void
    {
        $employee = $this->employeeWithSalary();
        $this->commission($employee, 5000, '2026-04', approve: false);

        $run = $this->runPayroll();
        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $this->assertEquals(0, $record->variable_earnings);
    }

    public function test_an_approved_commission_is_paid_and_marked_paid(): void
    {
        $employee = $this->employeeWithSalary();
        $earning = $this->commission($employee, 5000, '2026-04');

        $run = $this->runPayroll();
        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $this->assertEquals(5000, $record->variable_earnings);
        $this->assertSame(HrEmployeeVariableEarning::PAID, $earning->fresh()->status);
        $this->assertSame($record->id, $earning->fresh()->payroll_record_id);
    }

    public function test_a_commission_raises_net_payable_without_touching_the_frozen_snapshot(): void
    {
        $employee = $this->employeeWithSalary(60000);
        $this->commission($employee, 10000, '2026-04');

        $run = $this->runPayroll();
        $record = collect(app(PayrollService::class)->records($run->id, self::TENANT))
            ->firstWhere('employee_id', $employee->id);

        // The frozen structure snapshot is untouched — every existing consumer of
        // gross_salary/net_salary reads exactly what it read before.
        $this->assertEquals(60000, $record['gross_salary']);
        $this->assertEquals(60000, $record['net_salary']);
        // …and the payable figure is the one that moved.
        $this->assertEquals(10000, $record['variable_earnings']);
        $this->assertEquals(70000, $record['net_payable']);
    }

    public function test_a_commission_is_taxed_as_the_income_it_is(): void
    {
        // Without this, TDS would be computed on salary alone and the employee
        // would carry the shortfall to year end.
        $employee = $this->employeeWithSalary(60000);
        $this->commission($employee, 10000, '2026-04');

        $run = $this->runPayroll();
        $record = HrPayrollRecord::where('payroll_run_id', $run->id)->first();

        $this->assertEquals(70000, $record->taxable_earnings);
    }

    public function test_a_run_with_no_commissions_is_completely_unaffected(): void
    {
        $employee = $this->employeeWithSalary(60000);

        $run = $this->runPayroll();
        $record = collect(app(PayrollService::class)->records($run->id, self::TENANT))
            ->firstWhere('employee_id', $employee->id);

        $this->assertEquals(0, $record['variable_earnings']);
        $this->assertEquals(60000, $record['net_payable']);
        $this->assertSame(0, app(PayrollService::class)->showRun($run->id, self::TENANT)['variable_earnings']['employees_count']);
    }

    public function test_reprocessing_a_run_releases_the_commission_rather_than_stranding_it(): void
    {
        $employee = $this->employeeWithSalary();
        $earning = $this->commission($employee, 5000, '2026-04');

        $run = $this->runPayroll();
        $this->assertSame(HrEmployeeVariableEarning::PAID, $earning->fresh()->status);

        // A COMPLETED run is final and refuses reprocessing, so reopening it to
        // Draft is the only way back in — the state a half-finished run is left in.
        $run->update(['status' => \App\Models\Hr\HrPayrollRun::DRAFT]);
        app(PayrollService::class)->process($run->id, self::TENANT, $this->actor);

        // Reprocessing deletes the old records. Without the release, the earning
        // would still read Paid against a record that no longer exists and would
        // never be paid at all; instead it is collected again by the new record.
        $this->assertSame(HrEmployeeVariableEarning::PAID, $earning->fresh()->status);
        $newRecord = HrPayrollRecord::where('payroll_run_id', $run->id)->first();
        $this->assertEquals(5000, $newRecord->variable_earnings);
        $this->assertSame($newRecord->id, $earning->fresh()->payroll_record_id);
    }

    public function test_a_paid_commission_can_no_longer_be_edited(): void
    {
        $employee = $this->employeeWithSalary();
        $earning = $this->commission($employee, 5000, '2026-04');
        $this->runPayroll();

        $this->expectException(BusinessException::class);
        app(VariableEarningService::class)->save([
            'id' => $earning->id, 'employee_id' => $employee->id,
            'component_id' => $earning->component_id, 'period' => '2026-04', 'amount' => 9999,
        ], self::TENANT, $this->actor);
    }

    public function test_editing_an_approved_commission_withdraws_its_approval(): void
    {
        $employee = $this->employeeWithSalary();
        $earning = $this->commission($employee, 5000, '2026-04');

        $updated = app(VariableEarningService::class)->save([
            'id' => $earning->id, 'employee_id' => $employee->id,
            'component_id' => $earning->component_id, 'period' => '2026-04', 'amount' => 8000,
        ], self::TENANT, $this->actor);

        $this->assertSame(HrEmployeeVariableEarning::PENDING, $updated->status);
    }

    public function test_variable_earnings_require_hr_permission(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'V', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]));

        $this->getJson('/api/hr/variable-earnings')->assertForbidden();
    }

    /* ── #44 — exit questionnaire types ───────────────────────────────── */

    private function questionnaire(array $attrs = [], array $questions = []): array
    {
        return app(ExitQuestionnaireService::class)->save(array_merge([
            'name' => 'Standard Exit', 'is_default' => true,
            'questions' => $questions ?: [
                ['question_text' => 'Why are you leaving?', 'question_type' => 'text', 'is_required' => true],
                ['question_text' => 'Rate your manager', 'question_type' => 'rating', 'rating_max' => 5],
            ],
        ], $attrs), self::TENANT, $this->actor);
    }

    public function test_multiple_questionnaires_can_exist(): void
    {
        $this->questionnaire(['name' => 'Resignation']);
        $this->questionnaire(['name' => 'Retirement', 'is_default' => false]);

        $this->assertCount(2, app(ExitQuestionnaireService::class)->list(self::TENANT));
    }

    public function test_only_one_questionnaire_stays_the_default(): void
    {
        $first = $this->questionnaire(['name' => 'A']);
        $this->questionnaire(['name' => 'B']);   // also is_default => true

        $this->assertFalse(HrExitQuestionnaire::find($first['id'])->is_default,
            'a second default must displace the first, or "the default" means nothing');
    }

    public function test_the_questionnaire_for_an_exit_type_wins_over_the_default(): void
    {
        $type = HrExitType::create(['tenant_id' => self::TENANT, 'name' => 'Retirement', 'code' => 'RET']);
        $this->questionnaire(['name' => 'Generic']);
        $this->questionnaire(['name' => 'Retirement Form', 'is_default' => false, 'exit_type_id' => $type->id]);

        $resolved = app(ExitQuestionnaireService::class)->resolveFor($type->id, self::TENANT);

        $this->assertSame('Retirement Form', $resolved['name']);
    }

    public function test_the_default_is_used_when_no_type_specific_form_exists(): void
    {
        $type = HrExitType::create(['tenant_id' => self::TENANT, 'name' => 'Termination', 'code' => 'TER']);
        $this->questionnaire(['name' => 'Generic']);

        $this->assertSame('Generic', app(ExitQuestionnaireService::class)->resolveFor($type->id, self::TENANT)['name']);
    }

    public function test_no_questionnaire_at_all_is_a_valid_answer(): void
    {
        // A tenant that defines none keeps the original fixed form.
        $this->assertNull(app(ExitQuestionnaireService::class)->resolveFor(null, self::TENANT));
    }

    public function test_a_choice_question_with_no_options_is_refused(): void
    {
        $this->expectException(BusinessException::class);

        $this->questionnaire([], [
            ['question_text' => 'Pick one', 'question_type' => 'single_choice', 'options' => []],
        ]);
    }

    public function test_answers_are_stored_against_the_interview(): void
    {
        $form = $this->questionnaire();
        $employee = $this->employee();

        app(ExitInterviewService::class)->save($employee, [
            'questionnaire_id' => $form['id'],
            'answers' => [
                ['question_id' => $form['questions'][0]['id'], 'answer_text' => 'Relocating'],
                ['question_id' => $form['questions'][1]['id'], 'answer_rating' => 4],
            ],
        ], $this->actor);

        $interview = \App\Models\Hr\HrExitInterview::where('employee_id', $employee->id)->first();
        $answers = collect(app(ExitQuestionnaireService::class)->answersFor($interview));

        $this->assertSame($form['id'], $interview->questionnaire_id);
        $this->assertSame('Relocating', $answers->firstWhere('question_type', 'text')['answer_text']);
        // A rating stays numeric so exit reporting can average it.
        $this->assertSame(4, $answers->firstWhere('question_type', 'rating')['answer_rating']);
    }

    public function test_a_draft_may_be_saved_with_required_questions_unanswered(): void
    {
        $form = $this->questionnaire();
        $employee = $this->employee();

        $interview = app(ExitInterviewService::class)->save($employee, [
            'questionnaire_id' => $form['id'], 'answers' => [],
        ], $this->actor);

        $this->assertNotNull($interview->id);
    }

    public function test_submitting_with_a_required_question_unanswered_is_refused(): void
    {
        $form = $this->questionnaire();
        $employee = $this->employee();

        $this->expectException(BusinessException::class);
        app(ExitInterviewService::class)->save($employee, [
            'questionnaire_id' => $form['id'],
            'answers' => [['question_id' => $form['questions'][1]['id'], 'answer_rating' => 3]],
        ], $this->actor, submit: true);
    }

    public function test_an_interview_on_the_original_fixed_form_still_works(): void
    {
        // Backward compatibility: no questionnaire_id, no answers, unchanged path.
        $employee = $this->employee();

        $interview = app(ExitInterviewService::class)->save($employee, [
            'reason_for_leaving' => 'Better offer', 'rating' => 4,
        ], $this->actor, submit: true);

        $this->assertNull($interview->questionnaire_id);
        $this->assertSame('Submitted', $interview->status);
    }

    public function test_a_used_questionnaire_is_deactivated_rather_than_deleted(): void
    {
        $form = $this->questionnaire();
        $employee = $this->employee();
        app(ExitInterviewService::class)->save($employee, [
            'questionnaire_id' => $form['id'],
            'answers' => [['question_id' => $form['questions'][0]['id'], 'answer_text' => 'x']],
        ], $this->actor);

        app(ExitQuestionnaireService::class)->destroy($form['id'], self::TENANT, $this->actor);

        // Deleting would cascade the answers away and rewrite a completed interview.
        $this->assertNotNull(HrExitQuestionnaire::find($form['id']));
        $this->assertFalse(HrExitQuestionnaire::find($form['id'])->is_active);
    }

    public function test_an_unused_questionnaire_is_deleted_outright(): void
    {
        $form = $this->questionnaire();

        app(ExitQuestionnaireService::class)->destroy($form['id'], self::TENANT, $this->actor);

        $this->assertNull(HrExitQuestionnaire::find($form['id']));
    }

    public function test_the_exit_interview_endpoint_round_trips_templated_answers(): void
    {
        Sanctum::actingAs($this->actor);
        $form = $this->questionnaire();
        $employee = $this->employee();

        $this->postJson("/api/hr/employees/{$employee->id}/exit-interview", [
            'questionnaire_id' => $form['id'],
            'answers' => [
                ['question_id' => $form['questions'][0]['id'], 'answer_text' => 'Relocating'],
                ['question_id' => $form['questions'][1]['id'], 'answer_rating' => 5],
            ],
        ])->assertCreated();

        // Reopening the draft must show what was already answered, not a blank form.
        $this->getJson("/api/hr/employees/{$employee->id}/exit-interview")
            ->assertOk()
            ->assertJsonPath('record.questionnaire_id', $form['id'])
            ->assertJsonPath('record.answers.0.answer_text', 'Relocating');
    }

    public function test_authoring_a_questionnaire_requires_hr_permission(): void
    {
        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'V', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]));

        $this->postJson('/api/hr/exit-questionnaires', ['name' => 'Sneaky'])->assertForbidden();
        // …but the leaver filling in the form must still be able to read it.
        $this->getJson('/api/hr/exit-questionnaires/resolve')->assertOk();
    }
}
