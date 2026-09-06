<?php

namespace Tests\Feature\Hr\Statutory;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeDetail;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four registers a payroll month is filed with.
 *
 * Built from the same July 2026 figures as FiledRegisterTest, but through the
 * whole path — payroll records, the service, the endpoint — rather than the
 * calculators alone. What is pinned here is the SHAPE: which people appear on
 * which register, which columns, and what the totals add to.
 *
 * The three registers list different numbers of people out of one payroll (PF 9,
 * ESIC 8, PT 13 in the filed month), and getting that wrong is how a return is
 * filed for somebody who should not be on it.
 */
class StatutoryRegisterTest extends TestCase
{
    use RefreshDatabase;

    private HrPayrollRun $run;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Summit', 'slug' => 'registers', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@registers.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->run = HrPayrollRun::create([
            'tenant_id' => $tenant->id, 'payroll_month' => 7, 'payroll_year' => 2026,
            'status' => 'Processed',
        ]);

        Sanctum::actingAs($admin);
    }

    /**
     * One employee, with whichever contributions the case needs.
     *
     * @param  array<string, float|int>  $amounts
     */
    private function employee(string $code, string $name, array $amounts, string $gender = 'Male', ?int $bornYear = 1985): HrEmployee
    {
        $e = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => $name,
            'department' => 'Ops', 'designation' => 'Staff', 'gender' => $gender,
            'dob' => $bornYear.'-06-01', 'joining_date' => '2017-04-01', 'status' => 'Active',
        ]);

        HrEmployeeDetail::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'uan_number' => '10011270'.substr($code, -4), 'pf_number' => '100'.substr($code, -2),
            'esic_number' => '35135064'.substr($code, -2), 'father_name' => 'Father of '.$name,
        ]);

        HrPayrollRecord::create(array_merge([
            'tenant_id' => $this->tenantId, 'payroll_run_id' => $this->run->id,
            'employee_id' => $e->id, 'payable_days' => 31, 'absent_days' => 0,
            'gross_salary' => 0, 'net_salary' => 0,
            'pf_wages' => 0, 'pf_employee' => 0, 'pf_employer' => 0, 'eps_employer' => 0,
            'esic_wages' => 0, 'esic_employee' => 0, 'esic_employer' => 0,
            'pt_amount' => 0, 'lwf_employee' => 0, 'lwf_employer' => 0, 'vpf_amount' => 0,
        ], $amounts));

        return $e;
    }

    /** The full PF row, as the filed register lays it out. */
    public function test_the_pf_register_has_the_filed_columns_and_totals(): void
    {
        $this->employee('SD104', 'Sanjay Ranga Nandi', [
            'gross_salary' => 48478, 'pf_wages' => 15000,
            'pf_employee' => 1800, 'pf_employer' => 1800, 'eps_employer' => 1250,
        ]);

        // Past 58: EPS nil, so the whole employer share is EPF and eps_salary is 0
        // while pf_salary stays 15,000. Two different columns for a reason.
        $this->employee('SD111', 'Vijay Jageshwar Titarmare', [
            'gross_salary' => 27030, 'pf_wages' => 15000,
            'pf_employee' => 1800, 'pf_employer' => 1800, 'eps_employer' => 0,
        ], 'Male', 1966);

        $r = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/pf")->assertOk();
        $reg = $r->json('data.register');

        $this->assertSame(2, $reg['employees']);

        foreach (['sr_no', 'code', 'employee', 'uan', 'pf_number', 'ncd', 'paid_days',
                  'gross_salary', 'pf_salary', 'edli_salary', 'eps_salary', 'pf', 'vpf',
                  'epf', 'eps', 'total', 'dob', 'doj', 'father_name'] as $col) {
            $this->assertArrayHasKey($col, $reg['rows'][0], "The PF register must print {$col}");
        }

        [$first, $second] = $reg['rows'];

        $this->assertEquals(1250.0, $first['eps']);
        $this->assertEquals(550.0, $first['epf'], 'EPF is the employer share less EPS');
        $this->assertEquals(3600.0, $first['total']);

        $this->assertEquals(0.0, $second['eps'], 'Past 58 there is no EPS');
        $this->assertEquals(1800.0, $second['epf'], 'and the whole employer share goes to EPF');
        $this->assertEquals(0.0, $second['eps_salary'], 'so there is no EPS wage either');
        $this->assertEquals(15000.0, $second['pf_salary'], 'but he is still a PF member');

        $this->assertEquals(1250.0, $reg['totals']['eps']);
        $this->assertEquals(2350.0, $reg['totals']['epf']);
        $this->assertEquals(7200.0, $reg['totals']['total']);
    }

    /** The challan split — what actually gets paid, per account. */
    public function test_the_pf_challan_splits_by_account(): void
    {
        $this->employee('SD104', 'Sanjay', [
            'gross_salary' => 48478, 'pf_wages' => 15000,
            'pf_employee' => 1800, 'pf_employer' => 1800, 'eps_employer' => 1250,
        ]);

        $c = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/pf")
            ->json('data.register.challan');

        $this->assertEquals(2350.0, $c['ac_01'], 'A/C 01 = PF + VPF + EPF');
        $this->assertEquals(1250.0, $c['ac_10'], 'A/C 10 = EPS');
        $this->assertEquals(75.0, $c['ac_21'], 'A/C 21 = 0.5% of EDLI wages');
    }

    /**
     * Somebody above the ESIC ceiling is on the payroll and NOT on the return.
     *
     * This is the difference between the filed registers listing 9, 8 and 13
     * people out of one payroll.
     */
    public function test_each_register_lists_only_the_people_it_covers(): void
    {
        // Covered by all three.
        $this->employee('SD104', 'Covered', [
            'gross_salary' => 18453, 'pf_wages' => 15000, 'pf_employee' => 1800,
            'pf_employer' => 1800, 'eps_employer' => 1250,
            'esic_wages' => 18453, 'esic_employee' => 139, 'esic_employer' => 600,
            'pt_amount' => 200,
        ]);

        // Earns too much for ESIC: PF and PT only.
        $this->employee('SD85', 'Above the ESIC ceiling', [
            'gross_salary' => 126170, 'pf_wages' => 15000, 'pf_employee' => 1800,
            'pf_employer' => 1800, 'eps_employer' => 1250,
            'pt_amount' => 200,
        ]);

        // PT only — no PF, no ESIC.
        $this->employee('SD22', 'PT only', ['gross_salary' => 97082, 'pt_amount' => 200]);

        $pf   = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/pf")->json('data.register');
        $esic = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/esic")->json('data.register');
        $pt   = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/pt")->json('data.register');

        $this->assertSame(2, $pf['employees'], 'PF covers two of the three');
        $this->assertSame(1, $esic['employees'], 'ESIC covers only the one under the ceiling');
        $this->assertSame(3, $pt['employees'], 'PT covers all three');
    }

    /** The PT summary splits by gender, which is the whole point of it. */
    public function test_the_pt_summary_separates_men_and_women(): void
    {
        $this->employee('SD114', 'Chitra Ramesh Waghmare', ['gross_salary' => 47029, 'pt_amount' => 200], 'Female');
        $this->employee('SD85', 'Gyan Prakash Sharma', ['gross_salary' => 126170, 'pt_amount' => 200]);
        $this->employee('SD92', 'Jignesh Oza', ['gross_salary' => 57808, 'pt_amount' => 200]);

        $reg = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/pt")->json('data.register');

        $this->assertEquals(600.0, $reg['totals']['pt']);

        $byGender = collect($reg['summary'])->keyBy('gender');
        $this->assertSame(1, $byGender['Female']['employees']);
        $this->assertSame(2, $byGender['Male']['employees']);
        $this->assertEquals(47029.0, $byGender['Female']['total_salary']);
    }

    /**
     * An empty LWF register says WHY it is empty.
     *
     * "No rows" and "not a deduction month" look identical on screen, and one of
     * them is a missed filing.
     */
    public function test_an_empty_lwf_register_explains_itself(): void
    {
        $this->employee('SD104', 'Sanjay', ['gross_salary' => 48478]);

        $reg = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/lwf")->json('data.register');

        $this->assertSame(0, $reg['employees']);
        $this->assertStringContainsString('twice a year', (string) $reg['note']);
    }

    public function test_the_lwf_register_lists_both_shares_in_a_deduction_month(): void
    {
        $this->employee('SD104', 'Sanjay', [
            'gross_salary' => 48478, 'lwf_employee' => 25, 'lwf_employer' => 75,
        ]);

        $reg = $this->getJson("/api/hr/payroll/runs/{$this->run->id}/registers/lwf")->json('data.register');

        $this->assertSame(1, $reg['employees']);
        $this->assertEquals(25.0, $reg['totals']['employee_contribution']);
        $this->assertEquals(75.0, $reg['totals']['employer_contribution']);
        $this->assertEquals(100.0, $reg['totals']['total']);
        $this->assertNull($reg['note']);
    }

    public function test_another_tenants_run_is_not_readable(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'registers-2', 'status' => 'active']);
        $theirs = HrPayrollRun::create([
            'tenant_id' => $other->id, 'payroll_month' => 7, 'payroll_year' => 2026, 'status' => 'Processed',
        ]);

        $this->getJson("/api/hr/payroll/runs/{$theirs->id}/registers/pf")->assertNotFound();
    }
}
