<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
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
 * The statutory registers and the bank advice, scoped (Phase 6).
 *
 * These carry more than any other payload in the module: UAN and PF numbers,
 * ESIC numbers, date of birth, father's name, and — on the advice — every
 * employee's bank account number, IFSC and take-home pay.
 *
 * TWO QUESTIONS, ANSWERED SEPARATELY. The permission gate added in the earlier
 * security pass decides whether a caller may open a register at all, and is
 * asserted here to be unchanged. The scope added now decides which employees
 * appear on the one they opened. Neither replaces the other, and the tests are
 * written so that breaking either one fails.
 *
 * A scoped register is a PARTIAL FILING by design. That is already the
 * document's nature — an unscoped PF register omits non-contributors, and
 * `employees` has always counted rows rather than headcount — so a narrowed
 * register is the same shape for fewer people, not a different document.
 */
class ScopedStatutoryRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private HrPayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Reg', 'slug' => 'scoped-registers', 'status' => 'active']);

        $this->run = HrPayrollRun::create([
            'tenant_id' => $this->tenant->id, 'payroll_month' => 6, 'payroll_year' => 2026,
            'status' => 'Completed',
        ]);
    }

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
        $e = HrEmployee::create([
            'tenant_id' => $this->tenant->id, 'employee_code' => $code, 'name' => 'E'.$code,
            'department' => $dept, 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $user?->id, 'reporting_manager_id' => $managerId,
            'gender' => 'Male',
        ]);

        // The identity columns the registers print, and the bank details the
        // advice transfers to. Distinctive per employee so a leak is visible.
        DB::table('hr_employee_details')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'uan_number' => 'UAN'.$code, 'pf_number' => 'PF'.$code, 'esic_number' => 'ESIC'.$code,
            'bank_account_number' => 'ACCT'.$code, 'bank_ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC', 'bank_account_holder_name' => 'E'.$code,
            'pay_mode' => 'transfer',
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);

        return $e;
    }

    /** A processed record contributing to all four registers. */
    private function paid(HrEmployee $e, float $gross, float $pf, float $esic, float $pt, float $lwf): HrPayrollRecord
    {
        return HrPayrollRecord::create([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id,
            'employee_id' => $e->id,
            'gross_salary' => $gross, 'net_salary' => $gross,
            'total_benefits' => 0, 'total_deductions' => 0,
            'statutory_deductions' => 0, 'payable_days' => 30, 'absent_days' => 0,
            'pf_wages' => $gross, 'pf_employee' => $pf, 'pf_employer' => $pf, 'eps_employer' => 0,
            'esic_wages' => $gross, 'esic_employee' => $esic, 'esic_employer' => $esic,
            'pt_amount' => $pt, 'lwf_employee' => $lwf, 'lwf_employer' => $lwf,
            'status' => 'Processed',
        ]);
    }

    /**
     * Ops actor, Ops colleague, Sales outsider.
     *
     * The outsider's figures are an order of magnitude larger, so a leaked
     * total cannot be mistaken for a rounding difference.
     */
    private function cast(string $scope): array
    {
        $user = $this->hrUser("reg-{$scope}@reg.test", $scope);
        $me   = $this->employee('R-1', $user, 'Ops');
        $mate = $this->employee('R-2', null, 'Ops');
        $out  = $this->employee('R-3', null, 'Sales');

        $this->paid($me, 100, 12, 1, 200, 5);
        $this->paid($mate, 200, 24, 2, 200, 5);
        $this->paid($out, 9000, 1080, 90, 300, 50);

        return [$user, $me, $mate, $out];
    }

    private function register(string $kind): array
    {
        return $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/{$kind}")
            ->assertOk()->json('data.register');
    }

    /* ── PF ───────────────────────────────────────────────────────────── */

    public function test_the_pf_register_lists_only_scoped_employees(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $pf = $this->register('pf');

        $this->assertSame(2, $pf['employees']);
        $this->assertEqualsCanonicalizing(['ER-1', 'ER-2'], array_column($pf['rows'], 'employee'));
        $this->assertNotContains('UANR-3', array_column($pf['rows'], 'uan'));
    }

    public function test_pf_totals_and_challan_are_computed_from_the_scoped_rows(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $pf = $this->register('pf');

        // 12 + 24, not + 1080.
        $this->assertEquals(36.0, $pf['totals']['pf']);
        $this->assertEquals(300.0, $pf['totals']['pf_salary']);

        // The challan is derived from those same totals, so it narrows with
        // them: A/C 01 is employee PF + VPF + EPF = 36 + 0 + 36.
        $this->assertEquals(72.0, $pf['challan']['ac_01']);
        // A/C 02 is 0.85% of the scoped PF salary, not of the tenant's 9300.
        $this->assertEquals(round(300 * 0.85 / 100), $pf['challan']['ac_02']);
    }

    /* ── ESIC, PT, LWF ────────────────────────────────────────────────── */

    public function test_the_esic_register_is_scoped(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $esic = $this->register('esic');

        $this->assertSame(2, $esic['employees']);
        $this->assertEquals(3.0, $esic['totals']['employee_contribution']);   // 1 + 2
        $this->assertNotContains('ESICR-3', array_column($esic['rows'], 'esic_number'));
    }

    public function test_the_pt_register_and_its_slab_summary_are_scoped(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $pt = $this->register('pt');

        $this->assertSame(2, $pt['employees']);
        $this->assertEquals(400.0, $pt['totals']['pt']);   // 200 + 200

        // The summary groups the rows it was given, so it inherits the scope:
        // one slab line for 200/Male with two people, and no 300 line at all.
        $this->assertCount(1, $pt['summary']);
        $this->assertSame(2, $pt['summary'][0]['employees']);
        $this->assertEquals(200.0, $pt['summary'][0]['pt']);
    }

    public function test_the_lwf_register_is_scoped(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $lwf = $this->register('lwf');

        $this->assertSame(2, $lwf['employees']);
        $this->assertEquals(10.0, $lwf['totals']['employee_contribution']);   // 5 + 5
    }

    /* ── own / team / no employee ─────────────────────────────────────── */

    public function test_own_scope_shows_one_line(): void
    {
        [$user] = $this->cast(DataScope::OWN);
        Sanctum::actingAs($user);

        $pf = $this->register('pf');

        $this->assertSame(1, $pf['employees']);
        $this->assertSame('ER-1', $pf['rows'][0]['employee']);
        $this->assertEquals(12.0, $pf['totals']['pf']);
    }

    public function test_team_scope_follows_the_reporting_line(): void
    {
        $user = $this->hrUser('regteam@reg.test', DataScope::TEAM);
        $lead = $this->employee('RT-1', $user, 'Ops');
        $mine = $this->employee('RT-2', null, 'Ops', $lead->id);
        $other = $this->employee('RT-3', null, 'Ops');

        $this->paid($lead, 100, 10, 1, 200, 5);
        $this->paid($mine, 100, 20, 1, 200, 5);
        $this->paid($other, 9000, 1080, 90, 300, 50);

        Sanctum::actingAs($user);
        $pf = $this->register('pf');

        $this->assertSame(2, $pf['employees']);
        $this->assertEquals(30.0, $pf['totals']['pf']);
    }

    public function test_an_actor_with_no_employee_record_gets_an_empty_register(): void
    {
        $this->cast(DataScope::DEPARTMENT);
        $stranger = $this->hrUser('regstranger@reg.test', DataScope::DEPARTMENT);

        Sanctum::actingAs($stranger);

        // Authorised to open the register, with no place in the org chart —
        // so nothing, never everything.
        $pf = $this->register('pf');
        $this->assertSame(0, $pf['employees']);
        $this->assertSame([], $pf['rows']);
        $this->assertEquals(0.0, $pf['totals']['pf']);
    }

    public function test_global_and_admin_actors_see_the_whole_filing(): void
    {
        [$user] = $this->cast(DataScope::GLOBAL);
        Sanctum::actingAs($user);

        $pf = $this->register('pf');
        $this->assertSame(3, $pf['employees']);
        $this->assertEquals(1116.0, $pf['totals']['pf']);   // 12 + 24 + 1080

        $admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'regadmin@reg.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);
        Sanctum::actingAs($admin);

        $this->assertSame(3, $this->register('pf')['employees']);
    }

    /* ── bank advice ──────────────────────────────────────────────────── */

    public function test_the_bank_advice_lists_only_scoped_accounts(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        $data = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/bank-advice")
            ->assertOk()->json('data');

        $this->assertSame(2, $data['totals']['employees']);
        $this->assertEqualsCanonicalizing(['ACCTR-1', 'ACCTR-2'], array_column($data['rows'], 'account_number'));
        $this->assertNotContains('ACCTR-3', array_column($data['rows'], 'account_number'));
        // 100 + 200 transferred, not 9,300.
        $this->assertEquals(300.0, $data['totals']['amount']);
    }

    public function test_the_bank_advice_csv_carries_no_foreign_account_numbers(): void
    {
        [$user] = $this->cast(DataScope::DEPARTMENT);
        Sanctum::actingAs($user);

        // getContent(), not streamedContent(): this endpoint builds the whole
        // CSV in memory and returns it as an ordinary response, unlike the
        // report exports which stream.
        $csv = $this->get("/api/hr/payroll/runs/{$this->run->id}/bank-advice.csv")
            ->assertOk()->getContent();

        $this->assertStringContainsString('ACCTR-1', $csv);
        $this->assertStringContainsString('ACCTR-2', $csv);
        $this->assertStringNotContainsString('ACCTR-3', $csv);
        $this->assertStringNotContainsString('9000', $csv);
    }

    public function test_the_excluded_list_is_scoped_too(): void
    {
        [$user, , , $out] = $this->cast(DataScope::DEPARTMENT);

        // Put the outsider on hold so they would appear in `excluded` — which
        // names them and states a reason, and so discloses just as much as a
        // payable row would.
        $out->update(['hold_salary' => true]);

        Sanctum::actingAs($user);

        $data = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/bank-advice")
            ->assertOk()->json('data');

        $this->assertSame(0, $data['totals']['excluded']);
        $this->assertNotContains('ER-3', array_column($data['excluded'], 'employee'));
    }

    /* ── the gate is untouched ────────────────────────────────────────── */

    public function test_the_permission_gate_still_refuses_and_was_not_replaced_by_scope(): void
    {
        $this->cast(DataScope::GLOBAL);

        // A staff account with NO HR authority. Its scope resolves to global,
        // so if scope had been mistaken for permission this would now read the
        // whole register. It must still be a 403.
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoAuth', 'email' => 'regnoauth@reg.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        foreach (['pf', 'esic', 'pt', 'lwf'] as $kind) {
            $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/{$kind}")->assertForbidden();
        }

        $this->getJson("/api/hr/payroll/runs/{$this->run->id}/bank-advice")->assertForbidden();
        $this->get("/api/hr/payroll/runs/{$this->run->id}/bank-advice.csv")->assertForbidden();
    }

    public function test_portal_account_types_remain_denied(): void
    {
        $this->cast(DataScope::GLOBAL);

        foreach (['client', 'contact', 'doctor', 'company', 'patient'] as $i => $type) {
            $user = $this->hrUser("regportal{$i}@reg.test", DataScope::GLOBAL, $type);
            Sanctum::actingAs($user);

            $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/pf")->assertForbidden();
            $this->getJson("/api/hr/payroll/runs/{$this->run->id}/bank-advice")->assertForbidden();
        }
    }

    public function test_the_gate_runs_before_the_lookup_so_a_missing_run_is_still_403(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'NoAuth2', 'email' => 'regnoauth2@reg.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        // 403 rather than 404: answering 404 for a run that does not exist and
        // 403 for one that does would tell an unauthorised caller how many
        // payroll runs the company has.
        $this->getJson('/api/hr/payroll/runs/999999/registers/pf')->assertForbidden();
    }
}
