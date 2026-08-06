<?php

namespace App\Services\Hr;

use App\Models\Hr\HrEmployeeLoan;
use App\Models\Hr\HrEmployeeSalary;
use App\Services\Settings\SettingsService;

/**
 * Can this employee afford this EMI?
 *
 * Affordability is judged on the employee's monthly NET salary against their TOTAL
 * EMI burden — the loan being considered plus every other loan already being
 * repaid. Judging one loan in isolation is how someone ends up with three
 * "affordable" loans and no take-home pay.
 *
 * Three outcomes, both thresholds configurable per tenant:
 *   ok       EMI ≤ warn%          nothing to say
 *   warning  warn% < EMI ≤ max%   allowed, but flagged
 *   blocked  EMI > max%           refused while enforcement is on
 *
 * Two cases deliberately do NOT block:
 *   - no active salary on record, so there is no net to measure against;
 *   - enforcement switched off by the tenant.
 * Both return a reason instead. Refusing a loan because payroll has not been set
 * up yet would be the system's problem presented as the employee's.
 */
class LoanEligibilityService
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const BLOCKED = 'blocked';
    public const NOT_EVALUATED = 'not_evaluated';

    public function __construct(private SettingsService $settings)
    {
    }

    /** The tenant's configured thresholds. */
    public function limits(int $tenantId): array
    {
        $warn = (float) $this->settings->get($tenantId, 'payroll', 'loan_emi_warn_percent', 40);
        $max  = (float) $this->settings->get($tenantId, 'payroll', 'loan_emi_max_percent', 50);

        return [
            'warn_percent' => $warn,
            // A max below the warn threshold would make every warning also a block,
            // which reads as a misconfiguration rather than a policy. Keep them ordered.
            'max_percent'  => max($warn, $max),
            'enforced'     => (bool) $this->settings->get($tenantId, 'payroll', 'loan_enforce_eligibility', true),
        ];
    }

    /**
     * Evaluate an EMI for an employee.
     *
     * @param  int    $excludeLoanId  a loan being edited — its own existing EMI must
     *                                not be counted against the new figure
     */
    public function evaluate(int $employeeId, int $tenantId, float $emi, int $excludeLoanId = 0): array
    {
        $limits = $this->limits($tenantId);
        $net    = $this->netSalary($employeeId, $tenantId);
        $existing = $this->existingEmi($employeeId, $tenantId, $excludeLoanId);
        $total  = round($emi + $existing, 2);

        $base = [
            'emi'            => round($emi, 2),
            'existing_emi'   => $existing,
            'total_emi'      => $total,
            'net_salary'     => $net,
            'warn_percent'   => $limits['warn_percent'],
            'max_percent'    => $limits['max_percent'],
            'enforced'       => $limits['enforced'],
            'max_affordable_emi' => $net !== null ? round($net * $limits['max_percent'] / 100 - $existing, 2) : null,
        ];

        if ($net === null || $net <= 0) {
            return $base + [
                'status'  => self::NOT_EVALUATED,
                'percent' => null,
                'blocks'  => false,
                'message' => 'No active salary on record, so affordability could not be checked.',
            ];
        }

        // Compared unrounded, displayed rounded. Rounding first would let an EMI
        // of 50.001% read as exactly 50% and slip past a 50% limit.
        $exact   = $total / $net * 100;
        $percent = round($exact, 2);

        if ($exact > $limits['max_percent']) {
            return $base + [
                'status'  => self::BLOCKED,
                'percent' => $percent,
                'blocks'  => $limits['enforced'],
                'message' => sprintf(
                    'Total EMI %s%% of net salary exceeds the %s%% limit. Maximum affordable instalment is %s.',
                    $percent, $limits['max_percent'], number_format(max(0, $base['max_affordable_emi']), 2)
                ),
            ];
        }

        if ($exact > $limits['warn_percent']) {
            return $base + [
                'status'  => self::WARNING,
                'percent' => $percent,
                'blocks'  => false,
                'message' => sprintf(
                    'Total EMI is %s%% of net salary, above the %s%% comfort threshold but within the %s%% limit.',
                    $percent, $limits['warn_percent'], $limits['max_percent']
                ),
            ];
        }

        return $base + [
            'status'  => self::OK,
            'percent' => $percent,
            'blocks'  => false,
            'message' => null,
        ];
    }

    /* ── Inputs ───────────────────────────────────────────────────────── */

    /** Monthly net from the active frozen salary, or null when none is assigned. */
    private function netSalary(int $employeeId, int $tenantId): ?float
    {
        $salary = HrEmployeeSalary::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', HrEmployeeSalary::ACTIVE)
            ->orderByDesc('effective_from')
            ->first(['net_salary']);

        return $salary ? (float) $salary->net_salary : null;
    }

    /**
     * EMI already committed on other loans.
     *
     * Only DISBURSED loans count — an approved-but-undisbursed loan deducts
     * nothing yet, and counting it would refuse a second loan on the strength of
     * money that has not moved.
     */
    private function existingEmi(int $employeeId, int $tenantId, int $excludeLoanId = 0): float
    {
        return round((float) HrEmployeeLoan::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('status', HrEmployeeLoan::DISBURSED)
            ->when($excludeLoanId, fn ($q) => $q->where('id', '!=', $excludeLoanId))
            ->sum('emi'), 2);
    }
}
