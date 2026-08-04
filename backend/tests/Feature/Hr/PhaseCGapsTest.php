<?php

namespace Tests\Feature\Hr;

use App\Models\Helpdesk\Ticket;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrJobPublication;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Task\Task;
use App\Models\Task\TaskAssignee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Hr\EmployeeLifecycleService;
use App\Services\Hr\JobPublishingService;
use App\Services\Hr\LoanRecoveryService;
use App\Services\Hr\LoanService;
use App\Services\Hr\PayrollService;
use App\Services\Hr\Publishing\CareerPortalChannel;
use App\Services\Hr\Publishing\SyncableChannel;
use App\Services\Hr\Publishing\TrulyTalentsChannel;
use App\Support\Hr\JobPostingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The three Phase C requirements that the first pass did not cover:
 *   #13 sync status
 *   #37 timeline / activity references
 *   #38 loan deduction status on the employee and payroll screens
 */
class PhaseCGapsTest extends TestCase
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

    /* ══ #13 — sync status ═══════════════════════════════════════════ */

    private function job(): HrJobPosting
    {
        return HrJobPosting::create([
            'tenant_id' => self::TENANT, 'title' => 'Backend Engineer', 'department' => 'Engineering',
            'location' => 'Pune', 'status' => JobPostingStatus::PUBLISHED, 'description' => 'Build things.',
            'number_of_positions' => 2, 'created_by' => $this->actor->id,
        ]);
    }

    private function configureChannel(): void
    {
        config()->set('hr_publishing.trulytalents.base_url', 'https://api.trulytalents.test');
        config()->set('hr_publishing.trulytalents.api_key', 'test-key');
    }

    /**
     * A job with an existing publication row, so there is a reference to sync
     * against.
     *
     * The row is written directly rather than by calling publish(). Two reasons:
     * a sync test should not fail because publish broke, and Http::fake() APPENDS
     * stubs rather than replacing them — a wildcard left over from publish would
     * keep answering the sync request with the publish body.
     */
    private function publishedJob(): array
    {
        $this->configureChannel();
        $job = $this->job();

        $publication = HrJobPublication::create([
            'tenant_id' => self::TENANT, 'job_posting_id' => $job->id, 'channel' => 'trulytalents',
            'status' => 'published', 'external_ref' => 'TT-1', 'external_url' => 'https://tt.test/j/1',
            'published_at' => now(), 'last_synced_at' => now(),
        ]);

        return [$job, $publication];
    }

    public function test_only_channels_that_can_answer_are_marked_syncable(): void
    {
        $channels = collect(app(JobPublishingService::class)->channels())->keyBy('key');

        // The Career Portal IS the local database — asking it for remote status
        // would be asking ourselves, so it must not advertise Sync.
        $this->assertTrue($channels['trulytalents']['syncable']);
        $this->assertFalse($channels['careers']['syncable']);
        $this->assertInstanceOf(SyncableChannel::class, new TrulyTalentsChannel);
        $this->assertNotInstanceOf(SyncableChannel::class, new CareerPortalChannel);
    }

    public function test_syncing_a_channel_that_cannot_report_status_is_refused(): void
    {
        $job = $this->job();

        $this->expectExceptionMessage('does not report posting status');
        app(JobPublishingService::class)->sync($job, 'careers', $this->actor);
    }

    public function test_syncing_a_job_that_was_never_published_is_refused(): void
    {
        $this->configureChannel();
        $job = $this->job();

        $this->expectExceptionMessage('never been published');
        app(JobPublishingService::class)->sync($job, 'trulytalents', $this->actor);
    }

    public function test_a_sync_updates_the_same_publication_row(): void
    {
        [$job, $publication] = $this->publishedJob();
        Http::fake(['*' => Http::response(['status' => 'live', 'url' => 'https://tt.test/j/1-updated'], 200)]);

        $synced = app(JobPublishingService::class)->sync($job, 'trulytalents', $this->actor);

        // Same row — there is no second store of channel state.
        $this->assertSame($publication->id, $synced->id);
        $this->assertSame('published', $synced->status);
        $this->assertSame('https://tt.test/j/1-updated', $synced->external_url);
        $this->assertNotNull($synced->last_synced_at);
        // The original publish established these; a sync must not rewrite them.
        $this->assertSame('TT-1', $synced->external_ref);
        $this->assertNotNull($synced->published_at);
    }

    public function test_a_remote_404_marks_the_posting_removed(): void
    {
        [$job] = $this->publishedJob();
        Http::fake(['*' => Http::response('', 404)]);

        $synced = app(JobPublishingService::class)->sync($job, 'trulytalents', $this->actor);

        // The CRM would otherwise go on claiming the job is live on a board that
        // dropped it weeks ago.
        $this->assertSame('removed', $synced->status);
    }

    public function test_an_unreachable_channel_does_not_change_the_status(): void
    {
        [$job] = $this->publishedJob();
        Http::fake(['*' => Http::response('gateway down', 502)]);

        try {
            app(JobPublishingService::class)->sync($job, 'trulytalents', $this->actor);
            $this->fail('Expected the sync to fail.');
        } catch (\App\Exceptions\BusinessException $e) {
            $this->assertStringContainsString('Could not sync', $e->getMessage());
        }

        $publication = HrJobPublication::where('job_posting_id', $job->id)->first();
        // "We could not reach them" is not "the job was removed".
        $this->assertSame('published', $publication->status);
        $this->assertNotNull($publication->last_synced_at, 'the attempt is still recorded');
        $this->assertStringContainsString('502', $publication->error_message);
    }

    public function test_an_unrecognised_remote_status_becomes_unknown_not_removed(): void
    {
        [$job] = $this->publishedJob();
        Http::fake(['*' => Http::response(['status' => 'pending_moderation'], 200)]);

        $synced = app(JobPublishingService::class)->sync($job, 'trulytalents', $this->actor);

        // Guessing would risk withdrawing a live job.
        $this->assertSame('unknown', $synced->status);
        $this->assertSame('pending_moderation', $synced->meta['last_sync']['remote_status']);
    }

    public function test_only_a_status_change_is_audited(): void
    {
        [$job] = $this->publishedJob();

        // A SEQUENCE, not two Http::fake() calls: fake() appends stubs, so a second
        // wildcard never takes effect while the first still matches.
        Http::fakeSequence()
            ->push(['status' => 'live'], 200)   // unchanged — still published
            ->push('', 404);                    // now gone from their side

        app(JobPublishingService::class)->sync($job, 'trulytalents', $this->actor);
        $this->assertSame(0, $job->auditLogs()->where('action', 'like', 'Status on%')->count(),
            'a nightly no-op sync must not bury the real events');

        app(JobPublishingService::class)->sync($job, 'trulytalents', $this->actor);
        $this->assertSame(1, $job->fresh()->auditLogs()->where('action', 'like', 'Status on%')->count());
    }

    public function test_the_sync_endpoint_is_reachable(): void
    {
        Sanctum::actingAs($this->actor);
        [$job] = $this->publishedJob();
        Http::fake(['*' => Http::response(['status' => 'live'], 200)]);

        $this->postJson("/api/hr/jobs/{$job->id}/sync/trulytalents")
            ->assertOk()->assertJsonPath('status', 'published');
    }

    /* ══ #37 — timeline / activity references ════════════════════════ */

    private function linkedEmployee(): array
    {
        $user = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Worker', 'email' => 'w'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);

        return [$this->employee(['user_id' => $user->id]), $user];
    }

    public function test_dated_records_become_activity_references(): void
    {
        [$employee, $user] = $this->linkedEmployee();

        $task = Task::create([
            'tenant_id' => self::TENANT, 'name' => 'Ship it', 'status' => 'in_progress',
            'start_date' => '2026-01-01', 'due_date' => '2026-03-10', 'created_by' => $this->actor->id,
        ]);
        TaskAssignee::create(['tenant_id' => self::TENANT, 'task_id' => $task->id, 'user_id' => $user->id]);

        Ticket::create([
            'tenant_id' => self::TENANT, 'subject' => 'Laptop broken', 'description' => 'x',
            'assigned_to' => $user->id, 'created_by' => $this->actor->id,
            'due_date' => '2026-05-20', 'resolved_at' => now(),
        ]);

        $activity = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT)['activity'];

        $this->assertCount(2, $activity);
        // Newest first, matching how the HR audit timeline already reads.
        $this->assertSame('2026-05-20', $activity[0]['date']);
        $this->assertSame('ticket', $activity[0]['type']);
        $this->assertSame('Ticket resolved', $activity[0]['detail']);
        $this->assertSame('Task due', $activity[1]['detail']);
        // Every reference jumps to the record that owns it.
        $this->assertStringContainsString('/app/tasks', $activity[1]['link']);
    }

    public function test_undated_records_are_left_off_the_timeline(): void
    {
        [$employee, $user] = $this->linkedEmployee();

        $task = Task::create([
            'tenant_id' => self::TENANT, 'name' => 'No due date', 'status' => 'in_progress',
            'start_date' => '2026-01-01', 'created_by' => $this->actor->id,
        ]);
        TaskAssignee::create(['tenant_id' => self::TENANT, 'task_id' => $task->id, 'user_id' => $user->id]);

        $result = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT);

        // It still counts as a task; it just has no place on a dated timeline.
        // Dating it "today" would misrepresent it.
        $this->assertSame(1, $result['tasks']['total']);
        $this->assertSame([], $result['activity']);
    }

    public function test_an_unlinked_employee_still_returns_the_activity_key(): void
    {
        $employee = $this->employee();   // no user_id

        $result = app(EmployeeLifecycleService::class)->forEmployee($employee->id, self::TENANT);

        // Same shape in both branches so the caller never has to check.
        $this->assertArrayHasKey('activity', $result);
        $this->assertSame([], $result['activity']);
    }

    /* ══ #38 — loan status on the employee and payroll screens ═══════ */

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

    public function test_the_payroll_run_summary_reports_what_it_recovered(): void
    {
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);
        $run = $this->runPayroll(4);

        $summary = app(PayrollService::class)->showRun($run->id, self::TENANT);

        $this->assertEquals(10000, $summary['loan_recovery']['total_recovered']);
        $this->assertSame(1, $summary['loan_recovery']['employees_count']);
    }

    public function test_a_run_with_no_loans_reports_zero_rather_than_omitting_the_block(): void
    {
        $this->employeeWithSalary();
        $run = $this->runPayroll(4);

        $summary = app(PayrollService::class)->showRun($run->id, self::TENANT);

        $this->assertEquals(0, $summary['loan_recovery']['total_recovered']);
        $this->assertSame(0, $summary['loan_recovery']['employees_count']);
    }

    public function test_the_employee_loan_summary_reports_position_and_arrears(): void
    {
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);
        $this->runPayroll(4);

        $loans = app(LoanRecoveryService::class)->forEmployee($employee->id, self::TENANT);

        $this->assertTrue($loans['has_loans']);
        $this->assertSame(1, $loans['active_count']);
        $this->assertEquals(10000, $loans['monthly_emi']);
        $this->assertEquals(110000, $loans['total_outstanding']);
        $this->assertEquals(8.3, $loans['loans'][0]['percent_recovered']);
    }

    public function test_an_employee_with_no_loans_is_reported_as_such(): void
    {
        $employee = $this->employeeWithSalary();

        $loans = app(LoanRecoveryService::class)->forEmployee($employee->id, self::TENANT);

        $this->assertFalse($loans['has_loans']);
        $this->assertSame([], $loans['loans']);
    }

    public function test_the_profile_endpoint_carries_the_loan_block_for_hr(): void
    {
        Sanctum::actingAs($this->actor);
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);

        $this->getJson("/api/hr/employees/{$employee->id}/lifecycle")
            ->assertOk()
            ->assertJsonPath('loans.has_loans', true)
            ->assertJsonPath('loans.active_count', 1);
    }

    public function test_the_standalone_loan_summary_endpoint_serves_the_profile_card(): void
    {
        Sanctum::actingAs($this->actor);
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);

        $this->getJson("/api/hr/employees/{$employee->id}/loans")
            ->assertOk()
            ->assertJsonPath('has_loans', true)
            ->assertJsonPath('active_count', 1)
            ->assertJsonStructure(['total_outstanding', 'monthly_emi', 'arrear_count', 'loans']);
    }

    public function test_the_standalone_loan_summary_is_gated(): void
    {
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Viewer', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]));

        // Unlike the profile read, this endpoint is ONLY debt — 403 is right here.
        $this->getJson("/api/hr/employees/{$employee->id}/loans")->assertForbidden();
    }

    public function test_a_non_hr_user_gets_the_lifecycle_without_the_loan_block(): void
    {
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);

        $viewer = User::create([
            'tenant_id' => self::TENANT, 'name' => 'Viewer', 'email' => 'v'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'employee', 'status' => 'active',
        ]);
        Sanctum::actingAs($viewer);

        // Debt is gated, but gating it must not 403 the whole profile page.
        $response = $this->getJson("/api/hr/employees/{$employee->id}/lifecycle")->assertOk();
        $this->assertArrayNotHasKey('loans', $response->json());
        $this->assertArrayHasKey('projects', $response->json());
    }

    public function test_surfacing_recovery_changes_no_payroll_figure(): void
    {
        $employee = $this->employeeWithSalary();
        $this->disbursedLoan($employee);
        $run = $this->runPayroll(4);

        $before = \App\Models\Hr\HrPayrollRecord::where('payroll_run_id', $run->id)->first()->toArray();

        app(PayrollService::class)->showRun($run->id, self::TENANT);
        app(LoanRecoveryService::class)->forEmployee($employee->id, self::TENANT);

        $after = \App\Models\Hr\HrPayrollRecord::where('payroll_run_id', $run->id)->first()->toArray();
        $this->assertSame($before, $after);
    }
}
