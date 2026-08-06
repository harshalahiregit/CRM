<?php

namespace Tests\Feature\Hr;

use App\Models\Helpdesk\KbArticle;
use App\Models\Helpdesk\KbCategory;
use App\Models\Helpdesk\Ticket;
use App\Models\Hr\HrDepartment;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrJobPublication;
use App\Models\Hr\HrLoanInstallment;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Project\Project;
use App\Models\Project\ProjectMember;
use App\Models\Task\Task;
use App\Models\Task\TaskAssignee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeLifecycleService;
use App\Services\Hr\JobPublishingService;
use App\Services\Hr\LoanRecoveryService;
use App\Services\Hr\LoanService;
use App\Services\Hr\PayrollService;
use App\Services\Hr\Publishing\TrulyTalentsChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase C of the original 45 review comments:
 *   #13 TrulyTalents publishing channel
 *   #37 Projects / Tasks / Tickets / KB in the employee lifecycle
 *   #38 Loan recovery visible across the payroll ecosystem
 */
class PhaseCReviewCommentsTest extends TestCase
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
            'tenant_id' => self::TENANT, 'name' => 'Worker', 'employee_code' => 'W-'.uniqid(),
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01',
        ], $attrs));
    }

    /* ══ #13 — TrulyTalents channel ══════════════════════════════════ */

    private function job(): HrJobPosting
    {
        return HrJobPosting::create([
            'tenant_id' => self::TENANT, 'title' => 'Backend Engineer', 'department' => 'Engineering',
            'location' => 'Pune', 'status' => \App\Support\Hr\JobPostingStatus::PUBLISHED, 'description' => 'Build things.',
            'number_of_positions' => 2, 'created_by' => $this->actor->id,
        ]);
    }

    private function configureChannel(): void
    {
        config()->set('hr_publishing.trulytalents.base_url', 'https://api.trulytalents.test');
        config()->set('hr_publishing.trulytalents.api_key', 'test-key');
    }

    public function test_the_channel_is_registered_as_integrated(): void
    {
        // Before this comment it sat in the config with class => null, which the
        // UI renders as "not yet integrated".
        $channels = collect(app(JobPublishingService::class)->channels())->keyBy('key');

        $this->assertTrue($channels['trulytalents']['integrated']);
        $this->assertSame(TrulyTalentsChannel::class, config('hr_publishing.channels.trulytalents.class'));
    }

    public function test_an_unconfigured_channel_fails_loudly_rather_than_silently_succeeding(): void
    {
        config()->set('hr_publishing.trulytalents.base_url', null);
        config()->set('hr_publishing.trulytalents.api_key', null);
        $job = $this->job();

        try {
            app(JobPublishingService::class)->publish($job, 'trulytalents', $this->actor);
            $this->fail('Expected publishing to fail.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('TRULYTALENTS_BASE_URL', $e->getMessage());
        }

        // Telling a recruiter the job is live on a board it never reached is the
        // failure this guards against — the ledger records it as failed.
        $publication = HrJobPublication::where('job_posting_id', $job->id)->where('channel', 'trulytalents')->first();
        $this->assertSame('failed', $publication->status);
        $this->assertStringContainsString('not configured', $publication->error_message);
    }

    public function test_a_successful_publish_stores_the_external_reference_and_url(): void
    {
        $this->configureChannel();
        Http::fake(['*' => Http::response(['id' => 'TT-9911', 'url' => 'https://trulytalents.test/j/9911'], 201)]);

        $publication = app(JobPublishingService::class)->publish($this->job(), 'trulytalents', $this->actor);

        $this->assertSame('published', $publication->status);
        $this->assertSame('TT-9911', $publication->external_ref);
        $this->assertSame('https://trulytalents.test/j/9911', $publication->external_url);
        $this->assertNull($publication->error_message);
    }

    public function test_a_rejected_publish_is_recorded_with_the_remote_reason(): void
    {
        $this->configureChannel();
        Http::fake(['*' => Http::response(['message' => 'Salary range is required'], 422)]);

        try {
            app(JobPublishingService::class)->publish($this->job(), 'trulytalents', $this->actor);
            $this->fail('Expected publishing to fail.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('422', $e->getMessage());
        }

        $publication = HrJobPublication::where('channel', 'trulytalents')->first();
        $this->assertSame('failed', $publication->status);
        // The remote body is the useful half of the diagnosis.
        $this->assertStringContainsString('Salary range is required', $publication->error_message);
    }

    public function test_a_success_with_no_reference_is_treated_as_a_failure(): void
    {
        $this->configureChannel();
        // 200, but nothing to track or withdraw by.
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        try {
            app(JobPublishingService::class)->publish($this->job(), 'trulytalents', $this->actor);
            $this->fail('Expected publishing to fail.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('no reference', $e->getMessage());
        }

        $this->assertSame('failed', HrJobPublication::where('channel', 'trulytalents')->first()->status);
    }

    public function test_the_response_reference_key_is_configurable(): void
    {
        $this->configureChannel();
        config()->set('hr_publishing.trulytalents.response_ref_key', 'data.posting.reference');
        Http::fake(['*' => Http::response(['data' => ['posting' => ['reference' => 'NESTED-1']]], 201)]);

        $publication = app(JobPublishingService::class)->publish($this->job(), 'trulytalents', $this->actor);

        // A change in their payload shape is a config edit, not a code change.
        $this->assertSame('NESTED-1', $publication->external_ref);
    }

    public function test_unpublishing_without_a_stored_reference_is_refused(): void
    {
        $this->configureChannel();
        $job = $this->job();

        $this->expectExceptionMessage('cannot be withdrawn');
        (new TrulyTalentsChannel)->unpublish($job);
    }

    public function test_a_remote_404_on_withdrawal_is_treated_as_already_gone(): void
    {
        $this->configureChannel();
        $job = $this->job();
        HrJobPublication::create([
            'tenant_id' => self::TENANT, 'job_posting_id' => $job->id, 'channel' => 'trulytalents',
            'status' => 'published', 'external_ref' => 'TT-1', 'published_at' => now(),
        ]);
        Http::fake(['*' => Http::response('', 404)]);

        // Erroring here would leave the job stuck as published with nothing to withdraw.
        (new TrulyTalentsChannel)->unpublish($job->fresh());
        $this->assertTrue(true);
    }

    /* ══ #37 — employee lifecycle ════════════════════════════════════ */

    public function test_an_employee_with_no_user_account_is_told_why_the_lists_are_empty(): void
    {
        $employee = $this->employee();   // user_id is null

        $result = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT);

        // Four empty lists would read as "this person has done nothing".
        $this->assertFalse($result['linked']);
        $this->assertStringContainsString('no linked user account', $result['reason']);
        $this->assertSame(0, $result['projects']['total']);
    }

    public function test_projects_tasks_and_tickets_are_resolved_through_the_linked_user(): void
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Worker', 'email' => 'w'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        $employee = $this->employee(['user_id' => $user->id]);

        $project = Project::create(['tenant_id' => self::TENANT, 'name' => 'Platform', 'status' => 2, 'start_date' => '2026-01-01', 'created_by' => $this->actor->id]);
        ProjectMember::create(['tenant_id' => self::TENANT, 'project_id' => $project->id, 'user_id' => $user->id]);

        $task = Task::create(['tenant_id' => self::TENANT, 'name' => 'Ship it', 'status' => 'in_progress', 'priority' => 2, 'start_date' => '2026-01-01', 'created_by' => $this->actor->id]);
        TaskAssignee::create(['tenant_id' => self::TENANT, 'task_id' => $task->id, 'user_id' => $user->id]);

        Ticket::create([
            'tenant_id' => self::TENANT, 'subject' => 'Laptop broken', 'description' => 'It is broken',
            'assigned_to' => $user->id, 'created_by' => $this->actor->id,
        ]);

        $result = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT);

        $this->assertTrue($result['linked']);
        $this->assertSame(1, $result['projects']['total']);
        $this->assertSame('Platform', $result['projects']['items'][0]['title']);
        $this->assertSame(1, $result['tasks']['total']);
        $this->assertSame(1, $result['tickets']['total']);
        $this->assertSame('Laptop broken', $result['tickets']['items'][0]['title']);
    }

    public function test_every_row_carries_a_jump_link_to_its_own_module(): void
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Worker', 'email' => 'w'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        $employee = $this->employee(['user_id' => $user->id]);
        $project = Project::create(['tenant_id' => self::TENANT, 'name' => 'Platform', 'status' => 2, 'start_date' => '2026-01-01', 'created_by' => $this->actor->id]);
        ProjectMember::create(['tenant_id' => self::TENANT, 'project_id' => $project->id, 'user_id' => $user->id]);

        $result = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT);

        // "they can jump to the relevant section from here itself"
        $this->assertSame("/app/projects/{$project->id}", $result['projects']['items'][0]['link']);
        $this->assertSame('/app/projects', $result['projects']['link']);
    }

    public function test_open_task_counts_use_date_finished_not_a_status_slug(): void
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Worker', 'email' => 'w'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        $employee = $this->employee(['user_id' => $user->id]);

        $open = Task::create(['tenant_id' => self::TENANT, 'name' => 'Open', 'status' => 'in_progress', 'start_date' => '2026-01-01', 'created_by' => $this->actor->id]);
        $done = Task::create(['tenant_id' => self::TENANT, 'name' => 'Done', 'status' => 'anything_at_all',
                              'start_date' => '2026-01-01', 'created_by' => $this->actor->id, 'date_finished' => now()->subDay()]);
        foreach ([$open, $done] as $t) {
            TaskAssignee::create(['tenant_id' => self::TENANT, 'task_id' => $t->id, 'user_id' => $user->id]);
        }

        $result = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT);

        // tasks.status is a free-form slug, so counting on it would miscount the
        // moment a tenant added one.
        $this->assertSame(2, $result['tasks']['total']);
        $this->assertSame(1, $result['tasks']['open']);
    }

    public function test_the_knowledge_base_section_is_department_based_and_says_so(): void
    {
        HrDepartment::create(['tenant_id' => self::TENANT, 'name' => 'Engineering']);
        $employee = $this->employee(['department' => 'Engineering']);

        // kb_articles.department_id points at TICKET_DEPARTMENTS — a separate id
        // space from hr_departments — so the two are bridged by name.
        $ticketDeptId = \Illuminate\Support\Facades\DB::table('ticket_departments')->insertGetId([
            'tenant_id' => self::TENANT, 'name' => 'Engineering', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherDeptId = \Illuminate\Support\Facades\DB::table('ticket_departments')->insertGetId([
            'tenant_id' => self::TENANT, 'name' => 'Facilities', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $category = KbCategory::create(['tenant_id' => self::TENANT, 'name' => 'Engineering Docs', 'slug' => 'engineering-docs']);

        KbArticle::create([
            'tenant_id' => self::TENANT, 'category_id' => $category->id, 'title' => 'Deploy guide', 'content' => '…',
            'department_id' => $ticketDeptId, 'is_published' => true, 'published_at' => now(),
        ]);
        KbArticle::create([
            'tenant_id' => self::TENANT, 'category_id' => $category->id, 'title' => 'Other dept', 'content' => '…',
            'department_id' => $otherDeptId, 'is_published' => true, 'published_at' => now(),
        ]);

        $kb = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT)['knowledge_base'];

        $this->assertSame(1, $kb['total']);
        $this->assertSame('Deploy guide', $kb['items'][0]['title']);
        // kb_articles has no author column, so this must not read as authorship.
        $this->assertStringContainsString('records no author', $kb['basis']);
        $this->assertStringContainsString('matched by name', $kb['basis']);
    }

    /* ══ #38 — loan recovery across the payroll ecosystem ════════════ */

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

    private function disbursedLoan(HrEmployee $employee): array
    {
        $type = app(LoanService::class)->saveType(null, [
            'name' => 'Personal', 'max_amount' => 1000000, 'max_tenure_months' => 60,
            'interest_rate' => 0, 'requires_approval' => true,
        ], self::TENANT, $this->actor);

        $loan = app(LoanService::class)->save(null, [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => 120000, 'tenure_months' => 12, 'interest_rate' => 0,
        ], self::TENANT, $this->actor);

        app(LoanService::class)->submit($loan['id'], self::TENANT);
        app(LoanService::class)->approve($loan['id'], self::TENANT);

        return app(LoanService::class)->disburse($loan['id'], ['start_period' => '2026-04'], self::TENANT);
    }

    private function runPayroll(int $month): HrPayrollRun
    {
        $run = HrPayrollRun::create([
            'tenant_id' => self::TENANT, 'payroll_month' => $month, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, self::TENANT);

        return $run->refresh();
    }

    public function test_recovery_reports_scheduled_collected_and_outstanding(): void
    {
        $employee = $this->employeeWithSalary();
        $loan = $this->disbursedLoan($employee);
        $this->runPayroll(4);
        $this->runPayroll(5);

        $recovery = app(LoanRecoveryService::class)->forLoan($loan['id'], self::TENANT)['recovery'];

        $this->assertEquals(120000, $recovery['scheduled_total']);
        $this->assertEquals(20000, $recovery['collected_total']);
        $this->assertEquals(100000, $recovery['outstanding_total']);
        $this->assertSame(2, $recovery['installments_collected']);
        $this->assertEquals(16.7, $recovery['percent_recovered']);
    }

    public function test_each_collected_instalment_names_the_payroll_run_that_took_it(): void
    {
        $employee = $this->employeeWithSalary();
        $loan = $this->disbursedLoan($employee);
        $run = $this->runPayroll(4);

        $detail = app(LoanRecoveryService::class)->forLoan($loan['id'], self::TENANT);
        $first = collect($detail['installments'])->firstWhere('period', '2026-04');

        $this->assertSame(HrLoanInstallment::DEDUCTED, $first['status']);
        $this->assertSame($run->id, $first['payroll_run']['run_id']);
        // The SangoeTrack link: which attendance source underpinned that run.
        $this->assertNotNull($first['payroll_run']['attendance_source']);
    }

    public function test_a_missed_period_is_reported_as_an_arrear(): void
    {
        $employee = $this->employeeWithSalary();
        $loan = $this->disbursedLoan($employee);

        // April and May pass with no payroll run at all.
        $outstanding = app(LoanRecoveryService::class)->outstanding(self::TENANT, ['period' => '2026-06']);

        $row = collect($outstanding)->firstWhere('loan_id', $loan['id']);
        $this->assertSame(2, $row['arrear_count'], 'April and May were never collected');
        $this->assertEquals(20000, $row['arrear_amount']);
    }

    public function test_the_outstanding_queue_lists_only_loans_being_repaid(): void
    {
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);

        // A second loan left at Draft must not appear — nothing is being recovered.
        $type = app(LoanService::class)->types(self::TENANT)[0];
        app(LoanService::class)->save(null, [
            'employee_id' => $employee->id, 'loan_type_id' => $type['id'],
            'principal' => 12000, 'tenure_months' => 12, 'interest_rate' => 0,
        ], self::TENANT, $this->actor);

        $outstanding = app(LoanRecoveryService::class)->outstanding(self::TENANT);

        $this->assertCount(1, $outstanding);
        $this->assertSame(HrEmployeeLoan::DISBURSED,
            HrEmployeeLoan::find($outstanding[0]['loan_id'])->status);
    }

    public function test_a_payroll_run_reports_what_it_recovered(): void
    {
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);
        $run = $this->runPayroll(4);

        $result = app(LoanRecoveryService::class)->forRun($run->id, self::TENANT);

        $this->assertEquals(10000, $result['total_recovered']);
        $this->assertSame(1, $result['employees_count']);
        $this->assertSame('2026-04', $result['run']['period']);
        $this->assertEquals(50000, $result['rows'][0]['net_payable'], '60,000 net less the 10,000 instalment');
        $this->assertSame('2026-04', $result['rows'][0]['installments'][0]['period']);
    }

    public function test_recovery_reporting_changes_no_payroll_figure(): void
    {
        $employee = $this->employeeWithSalary();
        $loan = $this->disbursedLoan($employee);
        $run = $this->runPayroll(4);

        $before = \App\Models\Hr\HrPayrollRecord::where('payroll_run_id', $run->id)->first()->toArray();

        // Reading recovery must be exactly that — read-only. "Do not break current
        // payroll" is the requirement this protects.
        app(LoanRecoveryService::class)->forLoan($loan['id'], self::TENANT);
        app(LoanRecoveryService::class)->forRun($run->id, self::TENANT);
        app(LoanRecoveryService::class)->outstanding(self::TENANT);

        $after = \App\Models\Hr\HrPayrollRecord::where('payroll_run_id', $run->id)->first()->toArray();
        $this->assertSame($before, $after);
    }

    public function test_loan_recovery_requires_hr_permission(): void
    {
        $employee = $this->employeeWithSalary();
        $loan = $this->disbursedLoan($employee);

        $viewer = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Viewer', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($viewer);

        // It exposes debt.
        $this->getJson("/api/hr/loans/{$loan['id']}/recovery")->assertStatus(403);
    }
}
