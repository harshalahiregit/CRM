<?php

namespace App\Services\Hr\Statutory;

/**
 * Labour Welfare Fund.
 *
 * The odd one out: every other statutory deduction is monthly, and this one is
 * HALF-YEARLY. Maharashtra takes it from whoever is on the roll on 30 June and
 * 31 December, payable by 15 July and 15 January. Deducting a twelfth each month
 * would be a smaller number every month and the wrong number every June.
 *
 * Config keys:
 *   employee_amount  flat rupees deducted from the employee in a deduction month
 *   employer_amount  flat rupees the employer adds (three times the employee's in
 *                    Maharashtra — 25 and 75)
 *   months           the months it is deducted in, as numbers. [6, 12] for
 *                    Maharashtra. A state that levies it monthly lists all twelve.
 *
 * A flat amount, not a rate: it does not scale with salary, and expressing it as
 * a percentage of anything would be inventing a rule the state does not have.
 */
class LwfCalculator
{
    /**
     * @return array{applicable: bool, employee: float, employer: float, total: float, reason: ?string}
     */
    public function calculate(?array $config, ?int $month = null): array
    {
        if (! $config || (empty($config['employee_amount']) && empty($config['employer_amount']))) {
            return $this->zero('LWF not configured');
        }

        $months = $config['months'] ?? null;

        if ($month === null) {
            return $this->zero('No month given — LWF is levied in specific months only');
        }

        // An empty or missing list means every month, so a state that does levy it
        // monthly needs no special case.
        if (is_array($months) && $months !== [] && ! in_array((int) $month, array_map('intval', $months), true)) {
            return $this->zero('Not an LWF deduction month');
        }

        $employee = (float) ($config['employee_amount'] ?? 0);
        $employer = (float) ($config['employer_amount'] ?? 0);

        return [
            'applicable' => true,
            'employee'   => $employee,
            'employer'   => $employer,
            'total'      => round($employee + $employer, 2),
            'reason'     => null,
        ];
    }

    private function zero(string $reason): array
    {
        return ['applicable' => false, 'employee' => 0.0, 'employer' => 0.0,
                'total' => 0.0, 'reason' => $reason];
    }
}
