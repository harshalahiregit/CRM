<?php

namespace Tests\Feature\Hr;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\Hr\HrSalaryComponent;
use App\Models\Hr\HrSalaryStructure;
use App\Models\Hr\HrStatutoryRule;
use App\Services\Hr\PayrollService;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Professional Tax resolves by WORK STATE, never by city.
 *
 * Every employee below is deliberately located in a city ("Pune", "Bengaluru")
 * whose name is NOT a state. If PT ever came from `location` again, these tests
 * fail — which is the whole point of them.
 *
 * The PT amounts are TEST FIXTURES chosen so the arithmetic is checkable; they are
 * not an assertion about either state's current schedule.
 */
class PayrollWorkStateTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 1;

    private HrSalaryComponent $basicC;

    private HrSalaryComponent $hraC;

    protected function setUp(): void
    {
        parent::setUp();

        $mk = fn (string $code, string $name, array $flags) => HrSalaryComponent::create([
            'tenant_id' => $this->tenantId, 'name' => $name, 'code' => $code, 'type' => 'Earning',
            'calculation_type' => 'Fixed', 'is_active' => true,
        ] + $flags);

        $this->basicC = $mk('BASIC', 'Basic', ['taxable' => true, 'pf_applicable' => true, 'esic_applicable' => true]);
        $this->hraC   = $mk('HRA', 'HRA', ['taxable' => true, 'pf_applicable' => false, 'esic_applicable' => true]);
    }

    /**
     * One employee with a salary. `location` is always a CITY — the field PT used to
     * (wrongly) read — so a passing test proves PT is not reading it.
     */
    private function employee(string $code, ?string $workState, string $city, float $basic = 20000, float $hra = 8000): HrEmployee
    {
        $employee = HrEmployee::create([
            'tenant_id' => $this->tenantId, 'name' => "Emp {$code}", 'employee_code' => $code,
            'department' => 'Engineering', 'designation' => 'Engineer', 'status' => 'Active',
            'joining_date' => '2020-01-01',
            'location' => $city, 'work_state' => $workState,
        ]);

        $structure = HrSalaryStructure::create([
            'tenant_id' => $this->tenantId, 'name' => "Structure {$code}", 'code' => "ST-{$code}", 'is_active' => true,
        ]);
        $structure->lines()->createMany([
            ['component_id' => $this->basicC->id, 'amount' => $basic, 'calculation_type' => 'Fixed', 'sort_order' => 1],
            ['component_id' => $this->hraC->id,   'amount' => $hra,   'calculation_type' => 'Fixed', 'sort_order' => 2],
        ]);

        HrEmployeeSalary::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employee->id,
            'salary_structure_id' => $structure->id, 'effective_from' => '2026-01-01',
            'annual_ctc' => ($basic + $hra) * 12, 'monthly_ctc' => $basic + $hra,
            'gross_salary' => $basic + $hra, 'total_benefits' => 0, 'total_deductions' => 0,
            'net_salary' => $basic + $hra, 'status' => HrEmployeeSalary::ACTIVE,
        ]);

        return $employee;
    }

    private function ptRule(string $state, float $amount): void
    {
        HrStatutoryRule::create([
            'tenant_id' => $this->tenantId, 'rule_type' => 'pt', 'state' => $state,
            'effective_from' => '2020-01-01', 'is_active' => true,
            'config' => ['slabs' => [['from' => 0, 'to' => null, 'amount' => $amount]]],
        ]);
    }

    private function runPayroll(): HrPayrollRun
    {
        $run = HrPayrollRun::create([
            'tenant_id' => $this->tenantId, 'payroll_month' => 6, 'payroll_year' => 2026,
            'status' => HrPayrollRun::DRAFT,
        ]);
        app(PayrollService::class)->process($run->id, $this->tenantId);

        return $run->refresh();
    }

    private function recordFor(HrEmployee $employee): HrPayrollRecord
    {
        return HrPayrollRecord::where('employee_id', $employee->id)->firstOrFail();
    }

    private function meta(HrPayrollRecord $record): array
    {
        return json_decode($record->statutory_meta, true) ?: [];
    }

    /* ── The three required cases ─────────────────────────────────────── */

    public function test_maharashtra_employee_gets_maharashtra_pt(): void
    {
        $employee = $this->employee('MH-1', 'Maharashtra', city: 'Pune');
        $this->ptRule('Maharashtra', 200);
        $this->ptRule('Karnataka', 200);   // present, and must NOT be the one that applies

        $this->runPayroll();
        $record = $this->recordFor($employee);

        $this->assertEquals(200, (float) $record->pt_amount);
        $this->assertSame('Maharashtra', $this->meta($record)['state']);
    }

    public function test_karnataka_employee_gets_karnataka_pt(): void
    {
        $employee = $this->employee('KA-1', 'Karnataka', city: 'Bengaluru');
        $this->ptRule('Maharashtra', 200);
        $this->ptRule('Karnataka', 300);

        $this->runPayroll();
        $record = $this->recordFor($employee);

        $this->assertEquals(300, (float) $record->pt_amount, 'the Karnataka rule, not the Maharashtra one');
        $this->assertSame('Karnataka', $this->meta($record)['state']);
    }

    public function test_missing_work_state_does_not_fail_payroll_and_records_the_reason(): void
    {
        $employee = $this->employee('NO-STATE', null, city: 'Pune');
        $this->ptRule('Maharashtra', 200);

        // PF is configured too, to prove only PT is affected — the run is not skipped.
        HrStatutoryRule::create([
            'tenant_id' => $this->tenantId, 'rule_type' => 'pf', 'state' => null,
            'effective_from' => '2020-01-01', 'is_active' => true,
            'config' => ['employee_rate' => 12, 'employer_rate' => 12,
                         'wage_ceiling' => 15000, 'restrict_to_ceiling' => true],
        ]);

        $run = $this->runPayroll();

        $this->assertSame(HrPayrollRun::COMPLETED, $run->status, 'payroll still completes');

        $record = $this->recordFor($employee);
        $this->assertEquals(0, (float) $record->pt_amount, 'no state → no PT, never a guess');
        $this->assertEquals(1800, (float) $record->pf_employee, 'the rest of the statutory split is unaffected');

        $meta = $this->meta($record);
        $this->assertNull($meta['state']);
        $this->assertStringContainsString('Work state not set', $meta['pt'],
            'the reason must say WHY, so "PT is zero" is diagnosable');
    }

    /* ── The city dependency is gone ──────────────────────────────────── */

    public function test_a_city_in_the_location_field_never_resolves_pt(): void
    {
        // The exact shape of the old bug: location holds a city, no work state set.
        $employee = $this->employee('CITY-1', null, city: 'Pune');
        $this->ptRule('Pune', 999);   // even an (absurd) city-keyed rule must not match

        $this->runPayroll();
        $record = $this->recordFor($employee);

        $this->assertEquals(0, (float) $record->pt_amount, 'location is not a jurisdiction');
    }

    public function test_state_stored_as_a_code_still_matches_a_rule_keyed_by_name(): void
    {
        $employee = $this->employee('MH-2', 'MH', city: 'Nagpur');
        $this->ptRule('Maharashtra', 200);

        $this->runPayroll();

        $this->assertEquals(200, (float) $this->recordFor($employee)->pt_amount,
            '"MH" is normalised to "Maharashtra" on write');
    }

    public function test_two_states_in_one_run_are_taxed_independently(): void
    {
        $mh = $this->employee('MIX-MH', 'Maharashtra', city: 'Pune');
        $ka = $this->employee('MIX-KA', 'Karnataka', city: 'Bengaluru');
        $this->ptRule('Maharashtra', 200);
        $this->ptRule('Karnataka', 300);

        $this->runPayroll();

        $this->assertEquals(200, (float) $this->recordFor($mh)->pt_amount);
        $this->assertEquals(300, (float) $this->recordFor($ka)->pt_amount,
            'the per-request rule cache must not leak one state onto another');
    }

    /* ── Company-wide fallback ────────────────────────────────────────── */

    public function test_company_default_work_state_applies_when_the_employee_has_none(): void
    {
        $employee = $this->employee('DEF-1', null, city: 'Pune');
        $this->ptRule('Maharashtra', 200);
        app(SettingsService::class)->set($this->tenantId, 'payroll', 'default_work_state', 'Maharashtra');

        $this->runPayroll();

        $this->assertEquals(200, (float) $this->recordFor($employee)->pt_amount,
            'a single-state company configures this once instead of per employee');
    }

    public function test_the_employee_work_state_overrides_the_company_default(): void
    {
        $employee = $this->employee('OVR-1', 'Karnataka', city: 'Bengaluru');
        $this->ptRule('Maharashtra', 200);
        $this->ptRule('Karnataka', 300);
        app(SettingsService::class)->set($this->tenantId, 'payroll', 'default_work_state', 'Maharashtra');

        $this->runPayroll();

        $this->assertEquals(300, (float) $this->recordFor($employee)->pt_amount);
    }

    /* ── PT is never resolved from a state-less rule ──────────────────── */

    public function test_a_stateless_pt_rule_is_not_applied_to_a_state_it_was_not_written_for(): void
    {
        $employee = $this->employee('STRICT-1', 'Karnataka', city: 'Bengaluru');

        // A tenant-wide PT rule with no state. For PF/ESIC this is the normal case;
        // for PT it must NOT become "every state's" rule.
        HrStatutoryRule::create([
            'tenant_id' => $this->tenantId, 'rule_type' => 'pt', 'state' => null,
            'effective_from' => '2020-01-01', 'is_active' => true,
            'config' => ['slabs' => [['from' => 0, 'to' => null, 'amount' => 200]]],
        ]);

        $this->runPayroll();
        $record = $this->recordFor($employee);

        $this->assertEquals(0, (float) $record->pt_amount, 'PT is levied BY a state — no exact rule, no deduction');
        $this->assertStringContainsString('Karnataka', $this->meta($record)['pt'],
            'and the reason names the state that is missing configuration');
    }
}
