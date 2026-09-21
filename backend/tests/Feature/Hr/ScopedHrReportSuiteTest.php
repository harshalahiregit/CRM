<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hr\DataScope;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Exit, Probation, Training and Salary reports, scoped (Phase 6).
 *
 * Phases 4 and 5 closed the Employees, Leave and Payroll doors. These four were
 * the rest of the corridor: an actor who could no longer open one colleague's
 * record could still pull every resignation, every probation rating, every
 * training assessment and every CTC in the company out of a reports endpoint,
 * or export them to CSV.
 *
 * All four are covered in one file because they share a shape and a fixture:
 * an Ops actor, an Ops colleague, and a Sales outsider whose figures are an
 * order of magnitude larger so they cannot hide inside a leaked total.
 *
 * Permission is untouched throughout — every actor here holds HR authority.
 * What changes is whose records appear.
 */
class ScopedHrReportSuiteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'S6', 'slug' => 'scoped-suite-6', 'status' => 'active']);
    }

    /* ── shared fixture ───────────────────────────────────────────────── */

    private function hrUser(string $email, string $scope, string $accountType = 'staff'): User
    {
        $role = StaffRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'R '.substr(md5($email), 0, 6),
            'slug' => 'r_'.substr(md5($email), 0, 6),
            'permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL]],
            'scope' => $scope, 'is_system' => false,
        ]);

        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'U', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => $accountType,
            'status' => 'active', 'staff_role_id' => $role->id,
        ]);
    }

    private function employee(string $code, ?User $user = null, string $dept = 'Ops', ?int $managerId = null): HrEmployee
    {
        return HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
        ]);
    }

    /** Actor in Ops, a colleague in Ops, an outsider in Sales. */
    private function cast(string $scope, string $tag): array
    {
        $user = $this->hrUser("{$tag}-{$scope}@s6.test", $scope);

        return [
            $user,
            $this->employee($tag.'-1', $user, 'Ops'),
            $this->employee($tag.'-2', null, 'Ops'),
            $this->employee($tag.'-3', null, 'Sales'),
        ];
    }

    /** Not json(): Laravel's TestCase declares a public json() and PHP refuses the override. */
    private function report(string $path, array $query = []): array
    {
        $url = "/api/hr/{$path}";
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $this->getJson($url)->assertOk()->json();
    }

    private function streamed(string $url): string
    {
        $response = $this->get($url);
        $response->assertOk();

        return $response->streamedContent();
    }

    private function now(): string
    {
        return now()->toDateTimeString();
    }

    /* ── Exit fixtures ────────────────────────────────────────────────── */

    private function exitType(): int
    {
        return DB::table('hr_exit_types')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Resignation', 'code' => 'RES',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
    }

    /** A resignation with a settled settlement and a completed clearance. */
    private function resigned(HrEmployee $e, int $typeId, float $settlement, int $noticeDays): void
    {
        $reqId = DB::table('hr_exit_requests')->insertGetId([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'exit_type_id' => $typeId,
            'request_date' => now()->toDateString(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => $noticeDays, 'status' => 'Approved',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        DB::table('hr_exit_settlements')->insert([
            'tenant_id' => $this->tenant->id, 'exit_request_id' => $reqId, 'employee_id' => $e->id,
            'settlement_month' => now()->format('Y-m'), 'gross_earnings' => $settlement,
            'total_recoveries' => 0, 'net_settlement' => $settlement, 'status' => 'Settled',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        $clearanceId = DB::table('hr_exit_clearances')->insertGetId([
            'tenant_id' => $this->tenant->id, 'exit_request_id' => $reqId, 'employee_id' => $e->id,
            'status' => 'Completed', 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        DB::table('hr_exit_clearance_items')->insert([
            'tenant_id' => $this->tenant->id, 'clearance_id' => $clearanceId, 'department' => 'IT',
            'status' => 'Cleared', 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
    }

    private function exitCast(string $scope): array
    {
        [$user, $me, $mate, $out] = $this->cast($scope, 'X');
        $type = $this->exitType();

        $this->resigned($me, $type, 100, 30);
        $this->resigned($mate, $type, 200, 30);
        $this->resigned($out, $type, 9000, 90);   // distinctive

        return [$user, $me, $mate, $out];
    }

    /* ── Exit ─────────────────────────────────────────────────────────── */

    public function test_exit_employee_report_excludes_another_department(): void
    {
        [$user] = $this->exitCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $names = collect($this->report('exit/reports/employees'))->pluck('employee_name')->all();

        $this->assertContains('EX-1', $names);
        $this->assertContains('EX-2', $names);
        $this->assertNotContains('EX-3', $names);
    }

    public function test_exit_dashboard_totals_come_from_the_scoped_set(): void
    {
        [$user] = $this->exitCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->report('exit/reports/dashboard');

        $this->assertSame(2, $d['total_requests']);
        $this->assertSame(2, $d['approved_exits']);
        $this->assertSame(2, $d['completed_clearances']);
        $this->assertSame(2, $d['settled_employees']);
        // 100 + 200, not 100 + 200 + 9000.
        $this->assertEquals(300.0, $d['total_settlement_amount']);
        // Notice averages 30 in Ops; the outsider's 90 would drag it to 50.
        $this->assertEquals(30.0, $d['avg_notice_days']);
    }

    public function test_exit_own_scope_sees_only_itself(): void
    {
        [$user] = $this->exitCast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->assertSame(['EX-1'], collect($this->report('exit/reports/employees'))->pluck('employee_name')->all());
        $this->assertEquals(100.0, $this->report('exit/reports/dashboard')['total_settlement_amount']);
    }

    public function test_exit_team_scope_follows_the_reporting_line(): void
    {
        $user = $this->hrUser('xteam@s6.test', DataScope::TEAM);
        $lead = $this->employee('XT-1', $user, 'Ops');
        $mine = $this->employee('XT-2', null, 'Ops', $lead->id);
        $other = $this->employee('XT-3', null, 'Ops');   // same dept, different manager
        $type = $this->exitType();

        $this->resigned($lead, $type, 10, 30);
        $this->resigned($mine, $type, 20, 30);
        $this->resigned($other, $type, 9000, 30);

        Sanctum::actingAs($user);
        $names = collect($this->report('exit/reports/employees'))->pluck('employee_name')->all();

        $this->assertContains('EXT-1', $names);
        $this->assertContains('EXT-2', $names);
        $this->assertNotContains('EXT-3', $names);
    }

    public function test_exit_department_rollup_and_its_attrition_denominator_agree(): void
    {
        [$user] = $this->exitCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $rows = collect($this->report('exit/reports/departments'));

        $this->assertSame(['Ops'], $rows->pluck('department')->all());
        $ops = $rows->first();
        // 2 exits over an Ops headcount of 2 = 100%. Over the tenant's 3 it
        // would have read 66.7%, which is the denominator leaking.
        $this->assertSame(2, $ops['requests']);
        $this->assertEquals(100.0, $ops['exit_rate']);
    }

    public function test_exit_settlement_report_excludes_foreign_figures(): void
    {
        [$user] = $this->exitCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $rows = collect($this->report('exit/reports/settlements'));

        $this->assertCount(2, $rows);
        $this->assertNotContains('EX-3', $rows->pluck('employee_name')->all());
        $this->assertEqualsCanonicalizing([100.0, 200.0], $rows->pluck('net')->all());
    }

    public function test_exit_clearance_rollup_is_scoped_through_its_parent(): void
    {
        [$user] = $this->exitCast(DataScope::OWN);
        Sanctum::actingAs($user);

        // hr_exit_clearance_items has no employee_id — it reaches one through
        // hr_exit_clearances. Own scope leaves exactly one cleared IT item.
        $it = collect($this->report('exit/reports/clearances'))->firstWhere('department', 'IT');

        $this->assertSame(1, $it['cleared']);
    }

    public function test_exit_trends_count_only_scoped_records(): void
    {
        [$user] = $this->exitCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $month = collect($this->report('exit/reports/trends', ['year' => now()->year]))[now()->month - 1];

        $this->assertSame(2, $month['requests']);
        $this->assertEquals(150.0, $month['avg_settlement']);   // (100+200)/2
    }

    public function test_exit_filters_cannot_widen_and_do_not_leak_the_directory(): void
    {
        [$user, , , $out] = $this->exitCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertSame([], $this->report('exit/reports/employees', ['employee_id' => $out->id]));
        $this->assertSame([], $this->report('exit/reports/settlements', ['employee_id' => $out->id]));

        $f = $this->report('exit/reports/filters');
        $this->assertSame(['EX-1', 'EX-2'], collect($f['employees'])->pluck('name')->all());
        $this->assertSame(['Ops'], $f['departments']);
        // Exit types are master data and stay whole.
        $this->assertCount(1, $f['exit_types']);
    }

    public function test_exit_csv_export_excludes_out_of_scope_employees(): void
    {
        [$user] = $this->exitCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $csv = $this->streamed('/api/hr/exit/reports/export?report=settlements&format=csv');

        $this->assertStringContainsString('EX-1', $csv);
        $this->assertStringNotContainsString('EX-3', $csv);
        $this->assertStringNotContainsString('9000', $csv);
    }

    public function test_exit_global_and_no_employee_actors(): void
    {
        [$user] = $this->exitCast(DataScope::GLOBAL);
        Sanctum::actingAs($user);
        $this->assertCount(3, $this->report('exit/reports/employees'));

        $stranger = $this->hrUser('xstranger@s6.test', DataScope::DEPARTMENT);
        Sanctum::actingAs($stranger);
        $this->assertSame([], $this->report('exit/reports/employees'));
        $this->assertSame(0, $this->report('exit/reports/dashboard')['total_requests']);
    }

    /* ── Probation fixtures ───────────────────────────────────────────── */

    private function probationCast(string $scope): array
    {
        [$user, $me, $mate, $out] = $this->cast($scope, 'P');

        $typeId = DB::table('hr_probation_types')->insertGetId([
            'tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Standard',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
        $policyId = DB::table('hr_probation_policies')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Six Months', 'probation_type_id' => $typeId,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        foreach ([$me, $mate, $out] as $e) {
            $probId = DB::table('hr_employee_probations')->insertGetId([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'probation_policy_id' => $policyId, 'probation_type_id' => $typeId,
                'probation_start_date' => now()->startOfMonth()->toDateString(),
                'probation_end_date' => now()->startOfMonth()->addDays(180)->toDateString(),
                'current_status' => 'Active', 'extension_count' => 0,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);

            DB::table('hr_probation_reviews')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'employee_probation_id' => $probId, 'review_date' => now()->toDateString(),
                'overall_rating' => $e->id === $out->id ? 1 : 5,
                'recommendation' => $e->id === $out->id ? 'Fail' : 'Confirm',
                'status' => 'Completed', 'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);

            DB::table('hr_probation_confirmations')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'probation_id' => $probId,
                'status' => 'Pending', 'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);

            DB::table('hr_probation_extensions')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'probation_id' => $probId,
                'current_end_date' => now()->startOfMonth()->addDays(180)->toDateString(),
                'extended_end_date' => now()->startOfMonth()->addDays(210)->toDateString(),
                'extension_days' => 30, 'status' => 'Approved',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        return [$user, $me, $mate, $out];
    }

    /* ── Probation ────────────────────────────────────────────────────── */

    public function test_probation_employee_report_excludes_another_department(): void
    {
        [$user] = $this->probationCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $names = collect($this->report('probation/reports/employees'))->pluck('employee_name')->all();

        $this->assertEqualsCanonicalizing(['EP-1', 'EP-2'], $names);
    }

    public function test_probation_dashboard_counts_are_scoped(): void
    {
        [$user] = $this->probationCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->report('probation/reports/dashboard');

        $this->assertSame(2, $d['total']);
        $this->assertSame(2, $d['active']);
        // hr_probation_confirmations is its own table with its own employee_id.
        $this->assertSame(2, $d['pending_confirmation']);
    }

    public function test_probation_review_summary_excludes_the_outsiders_rating(): void
    {
        [$user] = $this->probationCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $r = $this->report('probation/reports/reviews');

        // Two 5s in Ops. The outsider's 1 would pull the average to 3.7.
        $this->assertSame(2, $r['completed']);
        $this->assertEquals(5.0, $r['avg_rating']);
        $this->assertSame(2, $r['recommendations']['Confirm']);
        $this->assertSame(0, $r['recommendations']['Fail']);
    }

    public function test_probation_extensions_and_confirmations_are_scoped(): void
    {
        [$user] = $this->probationCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $ext = collect($this->report('probation/reports/extensions'));
        $this->assertSame(['Ops'], $ext->pluck('department')->all());
        $this->assertSame(2, $ext->first()['requested']);

        $conf = collect($this->report('probation/reports/confirmations'));
        $this->assertEqualsCanonicalizing(['EP-1', 'EP-2'], $conf->pluck('employee_name')->all());
    }

    public function test_probation_own_scope_and_trends(): void
    {
        [$user] = $this->probationCast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->assertCount(1, $this->report('probation/reports/employees'));

        $month = collect($this->report('probation/reports/trends', ['year' => now()->year]))[now()->month - 1];
        $this->assertSame(1, $month['probations']);
        $this->assertSame(1, $month['reviews']);
    }

    public function test_probation_filters_cannot_widen_and_exports_are_scoped(): void
    {
        [$user, , , $out] = $this->probationCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertSame([], $this->report('probation/reports/employees', ['employee_id' => $out->id]));

        $f = $this->report('probation/reports/filters');
        $this->assertSame(['EP-1', 'EP-2'], collect($f['employees'])->pluck('name')->all());
        $this->assertSame(['Ops'], $f['departments']);

        $csv = $this->streamed('/api/hr/probation/reports/export?report=confirmations&format=csv');
        $this->assertStringContainsString('EP-1', $csv);
        $this->assertStringNotContainsString('EP-3', $csv);
    }

    public function test_probation_global_and_no_employee_actors(): void
    {
        [$user] = $this->probationCast(DataScope::GLOBAL);
        Sanctum::actingAs($user);
        $this->assertCount(3, $this->report('probation/reports/employees'));

        $stranger = $this->hrUser('pstranger@s6.test', DataScope::DEPARTMENT);
        Sanctum::actingAs($stranger);
        $this->assertSame([], $this->report('probation/reports/employees'));
        $this->assertSame(0, $this->report('probation/reports/dashboard')['total']);
    }

    /* ── Training fixtures ────────────────────────────────────────────── */

    private function trainingCast(string $scope): array
    {
        [$user, $me, $mate, $out] = $this->cast($scope, 'T');

        $catId = DB::table('hr_training_categories')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Technical', 'code' => 'TECH',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
        $typeId = DB::table('hr_training_types')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'Workshop', 'code' => 'WS',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
        $provId = DB::table('hr_training_providers')->insertGetId([
            'tenant_id' => $this->tenant->id, 'name' => 'In House',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
        $progId = DB::table('hr_training_programs')->insertGetId([
            'tenant_id' => $this->tenant->id, 'category_id' => $catId, 'training_type_id' => $typeId,
            'provider_id' => $provId, 'program_code' => 'PRG1', 'program_name' => 'Safety',
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);
        $sessId = DB::table('hr_training_sessions')->insertGetId([
            'tenant_id' => $this->tenant->id, 'training_program_id' => $progId,
            'trainer_name' => 'Trainer A', 'title' => 'Safety S1',
            'start_at' => now()->startOfMonth()->toDateTimeString(),
            'end_at' => now()->startOfMonth()->addHours(2)->toDateTimeString(),
            'status' => 'Completed', 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        foreach ([[$me, 90], [$mate, 80], [$out, 10]] as [$e, $pct]) {
            $etId = DB::table('hr_employee_trainings')->insertGetId([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'training_program_id' => $progId, 'training_session_id' => $sessId,
                'status' => 'Completed', 'completion_percentage' => 100,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);

            DB::table('hr_training_assessments')->insert([
                'tenant_id' => $this->tenant->id, 'employee_training_id' => $etId,
                'assessment_name' => 'Final', 'total_marks' => 100, 'obtained_marks' => $pct,
                'percentage' => $pct, 'result' => $pct >= 50 ? 'Pass' : 'Fail',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);

            DB::table('hr_training_certificates')->insert([
                'tenant_id' => $this->tenant->id, 'employee_training_id' => $etId,
                'certificate_number' => 'CERT-'.$e->employee_code,
                'issue_date' => now()->toDateString(), 'status' => 'Issued',
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        return [$user, $me, $mate, $out];
    }

    /* ── Training ─────────────────────────────────────────────────────── */

    public function test_training_employee_report_excludes_another_department(): void
    {
        [$user] = $this->trainingCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertEqualsCanonicalizing(
            ['ET-1', 'ET-2'],
            collect($this->report('learning/reports/employees'))->pluck('employee_name')->all()
        );
    }

    public function test_training_dashboard_scopes_people_but_not_the_catalogue(): void
    {
        [$user] = $this->trainingCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $d = $this->report('learning/reports/dashboard');

        // Who took what — scoped.
        $this->assertSame(2, $d['assignments']);
        $this->assertSame(2, $d['completed']);
        $this->assertSame(2, $d['certificates']);
        // Ops scored 90 and 80. The outsider's 10 would drop this to 60 and
        // make pass_pct 66.7 instead of 100.
        $this->assertEquals(85.0, $d['average_score']);
        $this->assertEquals(100.0, $d['pass_pct']);

        // The catalogue — NOT scoped. A course exists for everyone.
        $this->assertSame(1, $d['total_programs']);
        $this->assertSame(1, $d['total_sessions']);
    }

    public function test_training_assessments_and_certificates_are_scoped(): void
    {
        [$user] = $this->trainingCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $a = collect($this->report('learning/reports/assessments'));
        $this->assertCount(2, $a);
        $this->assertNotContains('ET-3', $a->pluck('employee_name')->all());

        $c = collect($this->report('learning/reports/certificates'));
        $this->assertCount(2, $c);
        $this->assertNotContains('CERT-T-3', $c->pluck('certificate_number')->all());
    }

    public function test_training_completion_report_is_scoped(): void
    {
        [$user] = $this->trainingCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        // This one runs through TrainingCompletionService rather than the
        // report repository, so it needed its own scope call.
        $rows = collect($this->report('learning/reports/completion'));

        $this->assertCount(2, $rows);
        $this->assertNotContains('ET-3', $rows->pluck('employee_name')->all());
    }

    public function test_training_program_rollup_counts_only_scoped_assignments(): void
    {
        [$user] = $this->trainingCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $p = collect($this->report('learning/reports/programs'))->firstWhere('code', 'PRG1');

        $this->assertSame(2, $p['assignments']);
        $this->assertEquals(85.0, $p['avg_score']);
    }

    public function test_training_own_scope_and_trends(): void
    {
        [$user] = $this->trainingCast(DataScope::OWN);
        Sanctum::actingAs($user);

        $this->assertCount(1, $this->report('learning/reports/employees'));

        $month = collect($this->report('learning/reports/trends', ['year' => now()->year]))[now()->month - 1];
        $this->assertSame(1, $month['trainings']);
        $this->assertSame(1, $month['certificates']);
        // Sessions are schedule data and stay whole — one session ran.
        $this->assertSame(1, $month['sessions']);
    }

    public function test_training_filters_cannot_widen_and_exports_are_scoped(): void
    {
        [$user, , , $out] = $this->trainingCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertSame([], $this->report('learning/reports/employees', ['employee_id' => $out->id]));

        $f = $this->report('learning/reports/filters');
        $this->assertSame(['ET-1', 'ET-2'], collect($f['employees'])->pluck('name')->all());
        $this->assertSame(['Ops'], $f['departments']);
        // The training catalogue stays whole.
        $this->assertCount(1, $f['programs']);

        $csv = $this->streamed('/api/hr/learning/reports/export?report=assessments&format=csv');
        $this->assertStringContainsString('ET-1', $csv);
        $this->assertStringNotContainsString('ET-3', $csv);
    }

    public function test_training_global_and_no_employee_actors(): void
    {
        [$user] = $this->trainingCast(DataScope::GLOBAL);
        Sanctum::actingAs($user);
        $this->assertCount(3, $this->report('learning/reports/employees'));

        $stranger = $this->hrUser('tstranger@s6.test', DataScope::DEPARTMENT);
        Sanctum::actingAs($stranger);
        $this->assertSame([], $this->report('learning/reports/employees'));
        $this->assertSame(0, $this->report('learning/reports/dashboard')['assignments']);
    }

    /* ── Salary fixtures ──────────────────────────────────────────────── */

    private function salaryCast(string $scope): array
    {
        [$user, $me, $mate, $out] = $this->cast($scope, 'C');

        foreach ([[$me, 1000], [$mate, 2000], [$out, 90000]] as [$e, $ctc]) {
            DB::table('hr_employee_salaries')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'effective_from' => '2024-01-01', 'status' => 'active',
                'monthly_ctc' => $ctc, 'annual_ctc' => $ctc * 12,
                'gross_salary' => $ctc, 'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => $ctc,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);

            DB::table('hr_salary_revisions')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'effective_from' => '2024-01-01', 'revision_no' => 1,
                'previous_monthly_ctc' => 0, 'new_monthly_ctc' => $ctc,
                'new_annual_ctc' => $ctc * 12, 'new_net_salary' => $ctc,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        return [$user, $me, $mate, $out];
    }

    /* ── Salary ───────────────────────────────────────────────────────── */

    public function test_salary_summary_totals_come_from_the_scoped_set(): void
    {
        [$user] = $this->salaryCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $s = $this->report('payroll/salary-reports/summary');

        $this->assertSame(2, $s['employees']);
        // 1000 + 2000, not + 90000.
        $this->assertEquals(3000.0, $s['total_monthly_ctc']);
        $this->assertEquals(1500.0, $s['average_ctc']);
        $this->assertEquals(36000.0, $s['total_annual_ctc']);
    }

    public function test_salary_employee_report_and_revisions_are_scoped(): void
    {
        [$user] = $this->salaryCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $rows = collect($this->report('payroll/salary-reports/employees')['rows']);
        $this->assertCount(2, $rows);
        $this->assertNotContains('EC-3', $rows->pluck('name')->all());

        $rev = collect($this->report('payroll/salary-reports/revisions')['rows']);
        $this->assertCount(2, $rev);
        $this->assertNotContains('EC-3', $rev->pluck('name')->all());
    }

    public function test_salary_cost_rollup_subtotals_use_only_scoped_employees(): void
    {
        [$user] = $this->salaryCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $rows = collect($this->report('payroll/salary-reports/department-cost')['rows']);

        $this->assertSame(['Ops'], $rows->pluck('label')->all());
        $this->assertSame(2, (int) $rows->first()['employees']);
        $this->assertEquals(3000.0, (float) $rows->first()['monthly_ctc']);
    }

    public function test_salary_structures_stay_whole_because_they_name_nobody(): void
    {
        [$user] = $this->salaryCast(DataScope::DEPARTMENT);
        DB::table('hr_salary_structures')->insert([
            'tenant_id' => $this->tenant->id, 'name' => 'Band A', 'code' => 'BA',
            'monthly_ctc' => 5000, 'annual_ctc' => 60000, 'is_active' => true,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ]);

        Sanctum::actingAs($user);

        // A pay scale is policy, not a person. Hiding it would withhold the
        // salary bands from a department head rather than protect anybody.
        $this->assertCount(1, $this->report('payroll/salary-reports/structures')['rows']);
    }

    public function test_salary_own_scope_sees_one_ctc(): void
    {
        [$user] = $this->salaryCast(DataScope::OWN);
        Sanctum::actingAs($user);

        $s = $this->report('payroll/salary-reports/summary');

        $this->assertSame(1, $s['employees']);
        $this->assertEquals(1000.0, $s['total_monthly_ctc']);
    }

    public function test_salary_team_scope_follows_the_reporting_line(): void
    {
        $user = $this->hrUser('cteam@s6.test', DataScope::TEAM);
        $lead = $this->employee('CT-1', $user, 'Ops');
        $mine = $this->employee('CT-2', null, 'Ops', $lead->id);
        $other = $this->employee('CT-3', null, 'Ops');

        foreach ([[$lead, 10], [$mine, 20], [$other, 90000]] as [$e, $ctc]) {
            DB::table('hr_employee_salaries')->insert([
                'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
                'effective_from' => '2024-01-01', 'status' => 'active',
                'monthly_ctc' => $ctc, 'annual_ctc' => $ctc * 12,
                'gross_salary' => $ctc, 'total_benefits' => 0, 'total_deductions' => 0, 'net_salary' => $ctc,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        }

        Sanctum::actingAs($user);

        $this->assertEquals(30.0, $this->report('payroll/salary-reports/summary')['total_monthly_ctc']);
    }

    public function test_salary_filters_cannot_widen_and_exports_are_scoped(): void
    {
        [$user, , , $out] = $this->salaryCast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $this->assertSame([], $this->report('payroll/salary-reports/employees', ['employee_id' => $out->id])['rows']);
        $this->assertSame([], $this->report('payroll/salary-reports/employees', ['department' => 'Sales'])['rows']);

        $meta = $this->report('payroll/salary-reports/meta');
        $this->assertSame(['Ops'], $meta['filters']['departments']);

        $csv = $this->streamed('/api/hr/payroll/salary-reports/employees/export?format=csv');
        $this->assertStringContainsString('EC-1', $csv);
        $this->assertStringNotContainsString('EC-3', $csv);
        $this->assertStringNotContainsString('90000', $csv);
    }

    public function test_salary_global_and_no_employee_actors(): void
    {
        [$user] = $this->salaryCast(DataScope::GLOBAL);
        Sanctum::actingAs($user);
        $this->assertEquals(93000.0, $this->report('payroll/salary-reports/summary')['total_monthly_ctc']);

        $stranger = $this->hrUser('cstranger@s6.test', DataScope::DEPARTMENT);
        Sanctum::actingAs($stranger);
        $this->assertSame(0, $this->report('payroll/salary-reports/summary')['employees']);
    }

    /* ── permission and portal isolation, all four ────────────────────── */

    public function test_permission_denial_survives_every_scope(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoAuth', 'email' => 'noauth@s6.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        foreach ([
            '/api/hr/exit/reports/employees',
            '/api/hr/probation/reports/employees',
            '/api/hr/learning/reports/employees',
            '/api/hr/payroll/salary-reports/summary',
        ] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_portal_account_types_remain_denied(): void
    {
        foreach (['client', 'contact', 'doctor', 'company', 'patient'] as $i => $type) {
            $user = $this->hrUser("portal{$i}@s6.test", DataScope::GLOBAL, $type);
            Sanctum::actingAs($user);

            $this->getJson('/api/hr/exit/reports/employees')->assertForbidden();
            $this->getJson('/api/hr/probation/reports/employees')->assertForbidden();
            $this->getJson('/api/hr/learning/reports/employees')->assertForbidden();
            $this->getJson('/api/hr/payroll/salary-reports/summary')->assertForbidden();
        }
    }

    public function test_an_admin_is_never_restricted(): void
    {
        $this->exitCast(DataScope::DEPARTMENT);

        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@s6.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        // No employee record and no staff role, yet global — the resolver
        // short-circuits on isAdmin() so the scope cannot lock out the person
        // who fixes scopes.
        $this->assertCount(3, $this->report('exit/reports/employees'));
    }
}
