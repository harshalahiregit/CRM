<?php

namespace App\Services\Hr\Payroll;

use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use Illuminate\Support\Collection;

/**
 * The four registers a payroll month is filed with: PF, ESIC, PT and LWF.
 *
 * These are not "reports" in the dashboard sense — they are the documents handed
 * to an accountant and typed into a government portal, so their SHAPE matters as
 * much as their numbers. Each method returns the columns of the corresponding
 * filed register, in its order, with the totals that appear on it.
 *
 * All four read a payroll run that has already been processed. Nothing is
 * recalculated here: a register that computes its own figures can disagree with
 * the payslip the employee was given, and then nobody knows which is right.
 *
 * Employees with no contribution are EXCLUDED from each register, matching the
 * filed documents — July's PF register lists 9 people, ESIC 8 and PT 13, out of
 * the same payroll. Somebody above the ESIC ceiling belongs on the payroll and
 * not on the ESIC return.
 */
class StatutoryRegisterService
{
    /** PF register — identity, the wage bases, then the PF/VPF/EPF/EPS split. */
    public function pf(HrPayrollRun $run): array
    {
        $rows = $this->records($run)
            ->filter(fn ($r) => (float) $r->pf_employee > 0 || (float) $r->pf_employer > 0)
            ->values()
            ->map(function (HrPayrollRecord $r, int $i) {
                $e = $r->employee;
                $d = $e?->detail;

                $employer = (float) $r->pf_employer;
                $eps      = (float) $r->eps_employer;
                $vpf      = (float) ($r->vpf_amount ?? 0);

                return [
                    'sr_no'        => $i + 1,
                    'code'         => $e?->employee_code,
                    'employee'     => $e?->name,
                    'uan'          => $d?->uan_number,
                    'pf_number'    => $d?->pf_number,
                    // Non-contributory days: days in the month earning no PF.
                    'ncd'          => (float) ($r->absent_days ?? 0),
                    'paid_days'    => (float) ($r->payable_days ?? 0),
                    'gross_salary' => (float) $r->gross_salary,
                    'pf_salary'    => (float) $r->pf_wages,
                    'edli_salary'  => (float) $r->pf_wages,
                    // Zero once EPS membership has ended. The person is still a PF
                    // member, so this column and pf_salary genuinely differ.
                    'eps_salary'   => $eps > 0 ? (float) $r->pf_wages : 0.0,
                    'pf'           => (float) $r->pf_employee,
                    'vpf'          => $vpf,
                    'epf'          => round($employer - $eps, 2),
                    'eps'          => $eps,
                    'total'        => round((float) $r->pf_employee + $employer + $vpf, 2),
                    'dob'          => $e?->dob?->format('d/m/Y'),
                    'doj'          => $e?->joining_date?->format('d/m/Y'),
                    'father_name'  => $d?->father_name,
                ];
            });

        return [
            'rows'      => $rows->all(),
            'totals'    => $this->sum($rows, ['gross_salary', 'pf_salary', 'edli_salary', 'eps_salary', 'pf', 'vpf', 'epf', 'eps', 'total']),
            'employees' => $rows->count(),
            'challan'   => $this->pfChallan($rows),
        ];
    }

    /**
     * The PF challan's account-wise split — what actually gets paid.
     *
     * A/C 01 is the fund, 02 the administration charge, 10 the pension scheme,
     * 21 and 22 the EDLI insurance. This is why the register carries an EDLI wage
     * column at all.
     */
    private function pfChallan(Collection $rows): array
    {
        $t = $this->sum($rows, ['pf', 'vpf', 'epf', 'eps', 'edli_salary', 'pf_salary']);

        return [
            'ac_01' => round($t['pf'] + $t['vpf'] + $t['epf'], 2),
            'ac_02' => round($t['pf_salary'] * 0.5 / 100, 2),
            'ac_10' => $t['eps'],
            'ac_21' => round($t['edli_salary'] * 0.5 / 100, 2),
            'ac_22' => 0.0,
        ];
    }

    /** ESIC register — only those actually covered this month. */
    public function esic(HrPayrollRun $run): array
    {
        $rows = $this->records($run)
            ->filter(fn ($r) => (float) $r->esic_employee > 0 || (float) $r->esic_employer > 0)
            ->values()
            ->map(fn (HrPayrollRecord $r, int $i) => [
                'sr_no'       => $i + 1,
                'code'        => $r->employee?->employee_code,
                'employee'    => $r->employee?->name,
                'esic_number' => $r->employee?->detail?->esic_number,
                'days'        => (float) ($r->payable_days ?? 0),
                'esic_salary' => (float) $r->esic_wages,
                'employee_contribution' => (float) $r->esic_employee,
                'employer_contribution' => (float) $r->esic_employer,
                'total'       => round((float) $r->esic_employee + (float) $r->esic_employer, 2),
            ]);

        return [
            'rows'      => $rows->all(),
            'totals'    => $this->sum($rows, ['days', 'esic_salary', 'employee_contribution', 'employer_contribution', 'total']),
            'employees' => $rows->count(),
        ];
    }

    /**
     * PT register, with the summary block the filed document carries.
     *
     * The summary groups by slab AND gender, because Maharashtra's thresholds
     * differ by gender — one "200 slab" line would hide that a woman on 20,000
     * pays nothing where a man on the same salary pays.
     */
    public function pt(HrPayrollRun $run): array
    {
        $rows = $this->records($run)
            ->filter(fn ($r) => (float) $r->pt_amount > 0)
            ->values()
            ->map(fn (HrPayrollRecord $r, int $i) => [
                'sr_no'     => $i + 1,
                'code'      => $r->employee?->employee_code,
                'employee'  => $r->employee?->name,
                'gender'    => $r->employee?->gender,
                'pt_salary' => (float) $r->gross_salary,
                'pt'        => (float) $r->pt_amount,
            ]);

        $summary = $rows->groupBy(fn ($row) => $row['pt'].'|'.$row['gender'])
            ->map(fn (Collection $g) => [
                'pt'           => $g->first()['pt'],
                'gender'       => $g->first()['gender'],
                'employees'    => $g->count(),
                'total_salary' => round($g->sum('pt_salary'), 2),
                'total_pt'     => round($g->sum('pt'), 2),
            ])->values()->all();

        return [
            'rows'      => $rows->all(),
            'summary'   => $summary,
            'totals'    => $this->sum($rows, ['pt_salary', 'pt']),
            // The filed register states this explicitly: somebody below the first
            // slab is on the payroll but not on this return.
            'employees' => $rows->count(),
        ];
    }

    /**
     * LWF register.
     *
     * Half-yearly, so in most months this is legitimately empty — a different
     * thing from "nothing was calculated", and the caller is told which.
     */
    public function lwf(HrPayrollRun $run): array
    {
        $rows = $this->records($run)
            ->filter(fn ($r) => (float) ($r->lwf_employee ?? 0) > 0 || (float) ($r->lwf_employer ?? 0) > 0)
            ->values()
            ->map(fn (HrPayrollRecord $r, int $i) => [
                'sr_no'    => $i + 1,
                'code'     => $r->employee?->employee_code,
                'employee' => $r->employee?->name,
                'employee_contribution' => (float) ($r->lwf_employee ?? 0),
                'employer_contribution' => (float) ($r->lwf_employer ?? 0),
                'total'    => round((float) ($r->lwf_employee ?? 0) + (float) ($r->lwf_employer ?? 0), 2),
            ]);

        return [
            'rows'      => $rows->all(),
            'totals'    => $this->sum($rows, ['employee_contribution', 'employer_contribution', 'total']),
            'employees' => $rows->count(),
            'note'      => $rows->isEmpty()
                ? 'LWF is deducted twice a year, in June and December. Nothing is due for this month.'
                : null,
        ];
    }

    /** @return Collection<int, HrPayrollRecord> */
    private function records(HrPayrollRun $run): Collection
    {
        return HrPayrollRecord::with(['employee', 'employee.detail'])
            ->where('payroll_run_id', $run->id)
            ->get()
            ->sortBy(fn ($r) => $r->employee?->employee_code ?? '')
            ->values();
    }

    /**
     * Totals are the sum of the ROWS, never a rate applied to a total.
     *
     * The company's own filed ESIC register does the latter for the employer
     * column and the former for the employee column, and the two disagree by a
     * rupee — its rows add to 4761 while it prints 4762. Summing rows is what each
     * payslip shows, so it is the figure that reconciles.
     *
     * @param  array<string>  $columns
     * @return array<string, float>
     */
    private function sum(Collection $rows, array $columns): array
    {
        $out = [];

        foreach ($columns as $c) {
            $out[$c] = round($rows->sum($c), 2);
        }

        return $out;
    }
}
