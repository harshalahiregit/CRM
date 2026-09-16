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
 * The salary transfer advice.
 *
 * The only payroll output that moves money, so the asymmetry matters: being LEFT
 * OFF is recoverable — somebody says they were not paid and it is fixed that day.
 * Being ON it with a wrong account is not; the money reaches a stranger and
 * getting it back is a matter of goodwill.
 *
 * So everything here is about who is excluded and whether the reason is visible.
 */
class BankAdviceTest extends TestCase
{
    use RefreshDatabase;

    private HrPayrollRun $run;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'bank-advice', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'a@bank.test',
            'password' => Hash::make('x'), 'role' => 'admin', 'status' => 'active',
        ]);

        $this->run = HrPayrollRun::create([
            'tenant_id' => $tenant->id, 'payroll_month' => 7, 'payroll_year' => 2026,
            'status' => 'Processed',
        ]);

        Sanctum::actingAs($admin);
    }

    private function person(string $code, float $net, array $bank = [], array $employee = []): HrEmployee
    {
        $e = HrEmployee::create(array_merge([
            'tenant_id' => $this->tenantId, 'employee_code' => $code, 'name' => "Person {$code}",
            'department' => 'Ops', 'designation' => 'Staff',
            'joining_date' => '2020-01-01', 'status' => 'Active',
        ], $employee));

        HrEmployeeDetail::create(array_merge([
            'tenant_id' => $this->tenantId, 'employee_id' => $e->id,
            'bank_account_number' => '0012345678', 'bank_ifsc' => 'HDFC0001234',
            'bank_name' => 'HDFC Bank', 'pay_mode' => 'Transfer',
        ], $bank));

        HrPayrollRecord::create([
            'tenant_id' => $this->tenantId, 'payroll_run_id' => $this->run->id,
            'employee_id' => $e->id, 'gross_salary' => $net, 'net_salary' => $net,
            'payable_days' => 31,
        ]);

        return $e;
    }

    private function advice(): array
    {
        return $this->getJson("/api/hr/payroll/runs/{$this->run->id}/bank-advice")
            ->assertOk()->json('data');
    }

    public function test_a_payable_employee_appears_with_their_account(): void
    {
        $this->person('SD104', 46339);

        $a = $this->advice();

        $this->assertSame(1, $a['totals']['employees']);
        $this->assertEquals(46339, $a['totals']['amount']);
        $this->assertSame('0012345678', $a['rows'][0]['account_number']);
        $this->assertSame('HDFC0001234', $a['rows'][0]['ifsc']);
        $this->assertStringContainsString('SD104', $a['rows'][0]['reference']);
    }

    /**
     * The transfer is net of the statutory split, not the frozen snapshot.
     *
     * `net_salary` is the salary structure's own figure and PayrollService keeps
     * it deliberately stable; PF, ESIC, this period's variable earnings and any
     * loan instalment live in separate columns. This service transferred
     * `net_salary`, so a July run reproducing filed employee SD104 wrote an
     * advice for ₹48,478 against a true net of ₹46,539 — PF ₹1,800 and ESIC ₹139
     * were withheld on the payslip, remitted to the government, AND paid to the
     * employee. The company paid the same ₹1,939 twice.
     *
     * Every other test in this file leaves the statutory columns at zero, which
     * is why the whole suite passed while the advice was wrong.
     */
    public function test_the_statutory_split_is_withheld_from_the_transfer(): void
    {
        $e = $this->person('SD104', 48478);

        HrPayrollRecord::where('employee_id', $e->id)->update([
            'pf_employee' => 1800, 'esic_employee' => 139, 'statutory_deductions' => 1939,
        ]);

        $a = $this->advice();

        $this->assertEquals(46539, $a['rows'][0]['amount'], 'PF and ESIC must not also be transferred');
        $this->assertEquals(46539, $a['totals']['amount']);
    }

    /** A loan instalment comes off the transfer, and a commission goes on it. */
    public function test_loan_and_variable_earnings_move_the_transfer(): void
    {
        $e = $this->person('SD115', 40000);

        HrPayrollRecord::where('employee_id', $e->id)->update([
            'statutory_deductions' => 1800,
            'loan_deduction'       => 5000,
            'variable_earnings'    => 2500,
        ]);

        $a = $this->advice();

        // 40000 + 2500 − 1800 − 5000
        $this->assertEquals(35700, $a['rows'][0]['amount']);
    }

    /**
     * Someone whose deductions swallow their pay is kept off the file.
     *
     * The zero-pay guard read the snapshot too, so a person whose statutory and
     * loan lines exceeded it still appeared — with a positive amount the company
     * did not owe.
     */
    public function test_a_person_whose_deductions_exceed_their_pay_is_excluded(): void
    {
        $e = $this->person('SD126', 5000);

        HrPayrollRecord::where('employee_id', $e->id)->update([
            'statutory_deductions' => 600,
            'loan_deduction'       => 4400,
        ]);

        $a = $this->advice();

        $this->assertSame(0, $a['totals']['employees']);
        $this->assertSame('Net pay is zero', $a['excluded'][0]['reason']);
    }

    /** A cash payee has no account, and must not be swept into a transfer file. */
    public function test_someone_paid_in_cash_is_excluded_with_the_reason(): void
    {
        $this->person('SD22', 20000, ['pay_mode' => 'Cash']);

        $a = $this->advice();

        $this->assertSame(0, $a['totals']['employees']);
        $this->assertSame(1, $a['totals']['excluded']);
        $this->assertStringContainsString('not by bank transfer', $a['excluded'][0]['reason']);
    }

    public function test_held_salary_is_excluded(): void
    {
        $this->person('SD90', 30000, [], ['hold_salary' => true]);

        $a = $this->advice();

        $this->assertSame(0, $a['totals']['employees']);
        $this->assertStringContainsString('held', $a['excluded'][0]['reason']);
    }

    /**
     * A malformed IFSC is refused rather than sent.
     *
     * The bank rejects it at best; at worst the transfer misroutes.
     */
    public function test_a_bad_or_missing_ifsc_is_excluded(): void
    {
        $this->person('SD91', 25000, ['bank_ifsc' => 'NOTANIFSC']);
        $this->person('SD92', 25000, ['bank_ifsc' => null]);
        $this->person('SD93', 25000, ['bank_account_number' => null]);

        $a = $this->advice();

        $this->assertSame(0, $a['totals']['employees']);
        $this->assertSame(3, $a['totals']['excluded']);

        $reasons = collect($a['excluded'])->pluck('reason')->implode(' | ');
        $this->assertStringContainsString('not a valid code', $reasons);
        $this->assertStringContainsString('No IFSC', $reasons);
        $this->assertStringContainsString('No bank account number', $reasons);
    }

    /** A zero-value transfer is a rejected line and a sign the run is wrong. */
    public function test_zero_net_pay_is_excluded(): void
    {
        $this->person('SD94', 0);

        $a = $this->advice();

        $this->assertSame(0, $a['totals']['employees']);
        $this->assertStringContainsString('zero', $a['excluded'][0]['reason']);
    }

    /** The account name can differ from the employee name, and the account wins. */
    public function test_the_account_holder_name_is_used_when_it_differs(): void
    {
        $this->person('SD95', 10000, ['bank_account_holder_name' => 'R K Sharma']);

        $this->assertSame('R K Sharma', $this->advice()['rows'][0]['account_name']);
    }

    /** Nobody is dropped silently — paid plus excluded must equal headcount. */
    public function test_everybody_is_accounted_for(): void
    {
        $this->person('SD01', 10000);
        $this->person('SD02', 20000);
        $this->person('SD03', 15000, ['pay_mode' => 'Cheque']);
        $this->person('SD04', 0);

        $a = $this->advice();

        $this->assertSame(4, $a['totals']['employees'] + $a['totals']['excluded']);
        $this->assertEquals(30000, $a['totals']['amount']);
    }

    public function test_the_csv_is_plain_enough_for_a_bank_portal(): void
    {
        $this->person('SD104', 46339.5, ['bank_account_holder_name' => 'Sharma, R K']);

        // A plain response, not a streamed one — the file is small enough to hold
        // in memory and a stream buys nothing for a few dozen rows.
        $csv = $this->get("/api/hr/payroll/runs/{$this->run->id}/bank-advice.csv")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->getContent();

        $this->assertStringContainsString('Account Name,Account Number,IFSC,Amount,Reference', $csv);
        // A comma in a name must not shift every later column.
        $this->assertStringContainsString('"Sharma, R K"', $csv);
        // No symbols, no separators — both are why an upload gets rejected.
        $this->assertStringContainsString('46339.50', $csv);
        $this->assertStringNotContainsString('₹', $csv);
    }

    public function test_another_tenants_run_is_not_readable(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'bank-advice-2', 'status' => 'active']);
        $theirs = HrPayrollRun::create([
            'tenant_id' => $other->id, 'payroll_month' => 7, 'payroll_year' => 2026, 'status' => 'Processed',
        ]);

        $this->getJson("/api/hr/payroll/runs/{$theirs->id}/bank-advice")->assertNotFound();
    }
}
