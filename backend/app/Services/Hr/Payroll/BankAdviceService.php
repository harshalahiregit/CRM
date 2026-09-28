<?php

namespace App\Services\Hr\Payroll;

use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Models\User;
use App\Services\Auth\ScopeResolver;
use App\Support\Hr\DataScope;
use Illuminate\Support\Collection;

/**
 * The salary transfer advice — the file a bank is given to pay everybody.
 *
 * This is the one payroll output that moves money, so it is deliberately strict
 * about who appears on it. Being LEFT OFF is recoverable: somebody notices they
 * were not paid and it is fixed the same day. Being on it with the wrong account
 * number is not — the money reaches a stranger, and getting it back is a matter
 * of goodwill.
 *
 * So an employee is excluded, with the reason stated, when:
 *
 *   - their pay mode is not a bank transfer. A cash or cheque payee has no
 *     account to send to and must not be swept into a transfer file.
 *   - their salary is held this month.
 *   - the account number or IFSC is missing.
 *   - the IFSC is not the right shape. Four letters, a zero, six characters —
 *     a malformed one is rejected by the bank at best and misroutes at worst.
 *   - the net pay is zero or negative. A zero-value transfer is a line the bank
 *     may reject and, more usefully, is a sign the run is wrong.
 *
 * Everyone excluded is RETURNED, not silently dropped, so the total on screen is
 * "42 paid, 3 not" rather than a number nobody can reconcile against headcount.
 */
class BankAdviceService
{
    /** Four letters, a mandatory 0, then six alphanumerics. */
    private const IFSC = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    private const TRANSFER_MODES = ['transfer', 'bank', 'neft', 'imps', 'rtgs', 'bank transfer'];

    /**
     * @param  User|null  $actor  Narrows WHO appears, never how they are paid.
     *
     * This is the single most disclosing payload in the module — every
     * employee's account number, IFSC and take-home in one list — so it gets a
     * data scope on top of the controller's permission gate, which is
     * unchanged. The exclusion reasons, the IFSC validation and the payable
     * formula are untouched: a scoped advice is the same document for fewer
     * people, and `totals.employees` already counts rows rather than headcount.
     */
    public function forRun(HrPayrollRun $run, ?User $actor = null): array
    {
        $q = HrPayrollRecord::with(['employee', 'employee.detail'])
            ->where('payroll_run_id', $run->id);

        $q = app(ScopeResolver::class)->applyToQuery($q, $actor, 'employee_id', [
            DataScope::OWN, DataScope::DEPARTMENT, DataScope::TEAM,
        ]);

        $records = $q->get()
            ->sortBy(fn ($r) => $r->employee?->employee_code ?? '')
            ->values();

        $payable = [];
        $excluded = [];

        foreach ($records as $r) {
            $reason = $this->whyNotPayable($r);

            if ($reason !== null) {
                $excluded[] = [
                    'code'     => $r->employee?->employee_code,
                    'employee' => $r->employee?->name,
                    'net_pay'  => $this->payable($r),
                    'reason'   => $reason,
                ];

                continue;
            }

            $d = $r->employee->detail;

            $payable[] = [
                'code'            => $r->employee->employee_code,
                'employee'        => $r->employee->name,
                // The name on the ACCOUNT, which is not always the name on the
                // employee record — a married name, an initial, a joint account.
                'account_name'    => $d->bank_account_holder_name ?: $r->employee->name,
                'account_number'  => $d->bank_account_number,
                'ifsc'            => strtoupper((string) $d->bank_ifsc),
                'bank_name'       => $d->bank_name,
                'amount'          => $this->payable($r),
                'reference'       => $this->reference($run, $r),
            ];
        }

        $rows = collect($payable);

        return [
            'rows'     => $payable,
            'excluded' => $excluded,
            'totals'   => [
                'employees' => $rows->count(),
                'amount'    => round($rows->sum('amount'), 2),
                // Stated separately so the two numbers can be compared with
                // headcount rather than inferred from one another.
                'excluded'  => count($excluded),
            ],
        ];
    }

    /**
     * A CSV in the shape most Indian bank portals accept for a bulk upload.
     *
     * Deliberately plain: no currency symbols, no thousands separators, amounts
     * to two decimals. Every one of those is a reason an upload is rejected.
     */
    public function csv(HrPayrollRun $run, ?User $actor = null): string
    {
        $advice = $this->forRun($run, $actor);

        $out = "Account Name,Account Number,IFSC,Amount,Reference\n";

        foreach ($advice['rows'] as $row) {
            $out .= implode(',', [
                $this->escape($row['account_name']),
                $this->escape($row['account_number']),
                $this->escape($row['ifsc']),
                number_format($row['amount'], 2, '.', ''),
                $this->escape($row['reference']),
            ])."\n";
        }

        return $out;
    }

    /** The reason this person cannot be paid by transfer, or null if they can. */
    private function whyNotPayable(HrPayrollRecord $r): ?string
    {
        $e = $r->employee;

        if (! $e) {
            return 'The employee record is missing';
        }

        if ($e->hold_salary) {
            return 'Salary is held this month';
        }

        $d = $e->detail;

        if (! $d) {
            return 'No bank details on record';
        }

        $mode = strtolower(trim((string) ($d->pay_mode ?? 'transfer')));

        if ($mode !== '' && ! in_array($mode, self::TRANSFER_MODES, true)) {
            return "Paid by {$d->pay_mode}, not by bank transfer";
        }

        if (! $d->bank_account_number) {
            return 'No bank account number';
        }

        if (! $d->bank_ifsc) {
            return 'No IFSC';
        }

        if (! preg_match(self::IFSC, strtoupper((string) $d->bank_ifsc))) {
            return 'The IFSC is not a valid code';
        }

        if ($this->payable($r) <= 0) {
            return 'Net pay is zero';
        }

        return null;
    }

    /**
     * What this person is actually owed this month.
     *
     * Delegated to the record, which is the only place the formula is written.
     * It used to be spelled out here as well, and the two spellings disagreed:
     * this file transferred `net_salary`, the frozen structure snapshot, which
     * excludes the statutory split. On a July run reproducing filed employee
     * SD104 that meant an advice for ₹48,478 against a true net of ₹46,539 —
     * PF ₹1,800 and ESIC ₹139 were withheld on the payslip, remitted to the
     * government, AND still transferred to the employee. The company paid the
     * same ₹1,939 twice, every employee, every month, and nothing in the file
     * disagreed with itself; the arithmetic was only wrong against a column
     * this service never read.
     *
     * Keeping the wrapper rather than calling netPayable() at all three sites
     * is deliberate — it is where that history is written down, and where the
     * next person looks when the bank total does not match the register.
     */
    private function payable(HrPayrollRecord $r): float
    {
        return $r->netPayable();
    }

    /** What the employee sees on their statement. */
    private function reference(HrPayrollRun $run, HrPayrollRecord $r): string
    {
        return sprintf('SAL %02d-%d %s', $run->payroll_month, $run->payroll_year, $r->employee?->employee_code);
    }

    /** A comma or a quote in a name must not shift every later column. */
    private function escape(?string $value): string
    {
        $v = (string) $value;

        return str_contains($v, ',') || str_contains($v, '"')
            ? '"'.str_replace('"', '""', $v).'"'
            : $v;
    }
}
