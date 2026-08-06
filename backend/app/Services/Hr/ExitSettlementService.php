<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeLeaveBalance;
use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitSettlement;
use App\Models\User;
use App\Repositories\Hr\EmployeeSalaryRepository;
use App\Repositories\Hr\SettlementRepository;
use App\Services\Hr\Statutory\GratuityCalculator;
use App\Services\Hr\Statutory\StatutoryRuleResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Full & Final Settlement (Exit Phase 5). Only exits whose clearance is Completed
 * get a settlement. Generation READS existing Payroll / Employee Salary / Leave
 * data plus the exit policy and freezes an immutable snapshot into `components` —
 * payroll is never modified or recalculated. Salary-derived figures (basic,
 * pending salary, leave encashment, gratuity) are computed; discretionary figures
 * (bonus, incentives, recoveries, asset recovery, other earnings/deductions) are
 * captured as HR inputs at generation. Lifecycle: Pending → Generated → Reviewed →
 * Approved → Settled; Settled is immutable. Settlement rows are lazily created
 * (status Pending) for newly-completed clearances when the queue is read — the
 * clearance phase is left untouched. Tenant-scoped, audited.
 */
class ExitSettlementService
{
    /** Discretionary earning inputs (captured at generation). */
    private const EARNING_INPUTS = ['bonus', 'incentives', 'other_earnings'];
    /** Discretionary recovery / deduction inputs. */
    private const RECOVERY_INPUTS = ['notice_recovery', 'buyout_recovery', 'asset_recovery', 'other_deductions'];

    public function __construct(
        private SettlementRepository $repo,
        private EmployeeSalaryRepository $salaries,
        private SalaryStructureService $structures,
        private GratuityCalculator $gratuity,
        private StatutoryRuleResolver $statutoryRules,
    ) {
    }

    /**
     * Gratuity payable on exit.
     *
     * The exit POLICY decides whether gratuity applies at all; the statutory rule
     * decides the formula — days per year, the month divisor, the minimum service
     * and the ceiling. Before this, the formula was hardcoded here as
     * `15/26 * basic * floor(years)` with a bare `>= 5` gate and NO ceiling, which
     * meant a long-serving senior employee could be settled above the statutory cap.
     *
     * The legacy formula is retained as a fallback for tenants who have not yet
     * configured a Gratuity rule, so no existing settlement changes value on
     * upgrade. It is flagged in the snapshot rather than applied quietly — a legal
     * figure living in code is a defect, not a default.
     */
    private function gratuityFor($policy, float $basicMonthly, int $tenureMonths, int $tenantId, $asOf): array
    {
        if (! $policy || ! $policy->gratuity_applicable) {
            return ['amount' => 0.0, 'source' => null, 'eligible_years' => 0,
                    'reason' => 'The exit policy does not provide for gratuity'];
        }

        $config = $this->statutoryRules->resolve('gratuity', $tenantId, $asOf ? Carbon::parse($asOf) : null);

        if ($config && ! empty($config['days_per_year']) && ! empty($config['month_days'])) {
            $result = $this->gratuity->settlement($basicMonthly, $tenureMonths, $config);

            return ['amount' => $result['amount'], 'source' => 'statutory_rule',
                    'eligible_years' => $result['eligible_years'], 'reason' => $result['reason'],
                    'formula' => "{$config['days_per_year']}/{$config['month_days']} days"
                                 .(isset($config['max_amount']) ? ", capped at {$config['max_amount']}" : ', uncapped'),
            ];
        }

        // Legacy fallback — identical to the pre-configuration behaviour.
        $years = intdiv($tenureMonths, 12);
        $amount = $years >= 5 ? round(15 / 26 * $basicMonthly * $years, 2) : 0.0;

        return [
            'amount' => $amount, 'source' => 'legacy_default', 'eligible_years' => $years,
            'reason' => $years >= 5
                ? 'Computed with the built-in legacy formula — configure a Gratuity statutory rule to control it'
                : 'Service below the 5-year minimum (legacy default)',
            'formula' => '15/26 days, uncapped (legacy)',
        ];
    }

    public function queue(int $tenantId, array $f, ?User $actor = null): array
    {
        $this->ensureForCompletedClearance($tenantId, $actor);

        return [
            'stats'  => $this->repo->stats($tenantId),
            'months' => $this->repo->months($tenantId),
            'rows'   => $this->repo->queue($tenantId, $f)->map(fn ($s) => $this->present($s))->all(),
        ];
    }

    public function show(int $id, int $tenantId, ?User $actor = null): array
    {
        $settlement = $this->find($id, $tenantId);
        $settlement->recordAudit('Settlement Viewed', $actor);

        return $this->present($settlement, true);
    }

    public function history(int $tenantId, array $f): array
    {
        return $this->repo->history($tenantId, $f)->map(fn ($s) => $this->present($s))->all();
    }

    public function forEmployee(int $employeeId, int $tenantId): ?array
    {
        $settlement = $this->repo->findByEmployee($employeeId, $tenantId);

        return $settlement ? $this->present($settlement, true) : null;
    }

    /* ── Workflow ─────────────────────────────────────────── */

    public function generate(int $id, array $inputs, int $tenantId, ?User $actor = null): array
    {
        $settlement = $this->find($id, $tenantId);
        if ($settlement->status !== HrExitSettlement::PENDING) {
            throw new BusinessException('This settlement has already been generated.');
        }
        $exit = $settlement->exitRequest;
        // Guard: the clearance behind this exit must be Completed.
        $clearance = HrExitClearance::where('tenant_id', $tenantId)->where('exit_request_id', $settlement->exit_request_id)->first();
        if (! $clearance || $clearance->status !== HrExitClearance::COMPLETED) {
            throw new BusinessException('Full & Final can only be generated once departmental clearance is complete.');
        }

        $snapshot = $this->computeSnapshot($settlement, $exit, $inputs, $tenantId);
        $month = $exit?->last_working_date ? Carbon::parse($exit->last_working_date)->format('Y-m') : now()->format('Y-m');

        $settlement->update([
            'status'           => HrExitSettlement::GENERATED,
            'settlement_month' => $month,
            'components'       => $snapshot,
            'gross_earnings'   => $snapshot['totals']['gross_earnings'],
            'total_recoveries' => $snapshot['totals']['total_recoveries'],
            'net_settlement'   => $snapshot['totals']['net_settlement'],
            'generated_at'     => now(),
            'generated_by'     => $actor?->id,
            'updated_by'       => $actor?->id,
        ]);
        $settlement->recordAudit('Settlement Generated', $actor, null, ['net' => $snapshot['totals']['net_settlement'], 'employee' => $settlement->employee?->name]);
        $this->log('Settlement generated', $tenantId, $settlement->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    public function review(int $id, int $tenantId, ?User $actor = null): array
    {
        return $this->transition($id, $tenantId, HrExitSettlement::GENERATED, HrExitSettlement::REVIEWED, 'reviewed_at', 'reviewed_by', 'Settlement Reviewed', 'Only a generated settlement can be reviewed.', $actor);
    }

    public function approve(int $id, int $tenantId, ?User $actor = null): array
    {
        return $this->transition($id, $tenantId, HrExitSettlement::REVIEWED, HrExitSettlement::APPROVED, 'approved_at', 'approved_by', 'Settlement Approved', 'Only a reviewed settlement can be approved.', $actor);
    }

    public function settle(int $id, int $tenantId, ?User $actor = null): array
    {
        return $this->transition($id, $tenantId, HrExitSettlement::APPROVED, HrExitSettlement::SETTLED, 'settled_at', 'settled_by', 'Settlement Settled', 'Only an approved settlement can be settled.', $actor);
    }

    private function transition(int $id, int $tenantId, string $from, string $to, string $tsCol, string $byCol, string $audit, string $error, ?User $actor): array
    {
        $settlement = $this->find($id, $tenantId);
        if ($settlement->status === HrExitSettlement::SETTLED) {
            throw new BusinessException('This settlement is settled and is now read-only.');
        }
        if ($settlement->status !== $from) {
            throw new BusinessException($error);
        }
        $settlement->update(['status' => $to, $tsCol => now(), $byCol => $actor?->id, 'updated_by' => $actor?->id]);
        $settlement->recordAudit($audit, $actor);
        $this->log($audit, $tenantId, $settlement->id);

        return $this->present($this->find($id, $tenantId), true);
    }

    /* ── Snapshot computation (READ-ONLY on payroll) ──────── */

    private function computeSnapshot(HrExitSettlement $settlement, $exit, array $inputs, int $tenantId): array
    {
        $employee = $settlement->employee;
        $policy = $exit?->policy;
        $lastWorking = $exit?->last_working_date ? Carbon::parse($exit->last_working_date) : null;

        // --- Salary (read the active frozen assignment; never write) ---
        $salary = $this->salaries->currentActive($settlement->employee_id, $tenantId);
        $grossMonthly = $salary ? (float) $salary->gross_salary : 0.0;
        $basicMonthly = $this->basicFrom($salary, $tenantId, $grossMonthly);
        $perDayBasic = round($basicMonthly / 30, 2);

        // --- Pending salary: pro-rata gross for the final (partial) month ---
        $pendingSalary = 0.0;
        if ($lastWorking && $grossMonthly > 0) {
            $daysInMonth = (int) $lastWorking->daysInMonth;
            $workedDays = (int) $lastWorking->day;
            $pendingSalary = round($grossMonthly * $workedDays / $daysInMonth, 2);
        }

        // --- Leave encashment (only if the policy allows) ---
        $leaveDays = 0.0;
        $leaveEncashment = 0.0;
        if ($policy && $policy->leave_encashment) {
            $leaveDays = (float) HrEmployeeLeaveBalance::where('tenant_id', $tenantId)
                ->where('employee_id', $settlement->employee_id)
                ->where('status', HrEmployeeLeaveBalance::ACTIVE)
                ->sum('available_balance');
            $leaveDays = max(0.0, round($leaveDays, 1));
            $leaveEncashment = round($leaveDays * $perDayBasic, 2);
        }

        // --- Gratuity: policy decides WHETHER, the statutory rule decides HOW MUCH ---
        $tenureYears = 0.0;
        $tenureMonths = 0;
        if ($employee?->joining_date && $lastWorking) {
            $joined = Carbon::parse($employee->joining_date);
            $tenureYears  = round($joined->floatDiffInYears($lastWorking), 1);
            $tenureMonths = (int) $joined->diffInMonths($lastWorking);
        }
        $gratuityResult = $this->gratuityFor($policy, $basicMonthly, $tenureMonths, $tenantId, $lastWorking);
        $gratuity = $gratuityResult['amount'];

        // --- Discretionary HR inputs (default 0, never negative) ---
        $num = fn ($k) => round(max(0.0, (float) ($inputs[$k] ?? 0)), 2);
        $bonus          = $num('bonus');
        $incentives     = $num('incentives');
        $otherEarnings  = $num('other_earnings');
        $noticeRecovery = $policy && ! $policy->recovery_allowed ? 0.0 : $num('notice_recovery');
        $buyoutRecovery = $policy && ! $policy->buyout_allowed ? 0.0 : $num('buyout_recovery');
        $assetRecovery  = $num('asset_recovery');
        $otherDeductions = $num('other_deductions');

        $grossEarnings = round($pendingSalary + $leaveEncashment + $gratuity + $bonus + $incentives + $otherEarnings, 2);
        $totalRecoveries = round($noticeRecovery + $buyoutRecovery + $assetRecovery + $otherDeductions, 2);
        $net = round($grossEarnings - $totalRecoveries, 2);

        return [
            'context' => [
                'monthly_gross'   => $grossMonthly,
                'monthly_basic'   => $basicMonthly,
                'per_day_basic'   => $perDayBasic,
                'structure_name'  => $salary?->structure?->name,
                'last_working_date' => optional($lastWorking)->toDateString(),
                'notice_days'     => $exit?->notice_days,
                'tenure_years'    => $tenureYears,
                'tenure_months'   => $tenureMonths,
                // How gratuity was arrived at, frozen with the settlement. `source`
                // is 'statutory_rule' or 'legacy_default' — the latter is a prompt
                // to configure the rule, not a silent success.
                'gratuity_basis'  => $gratuityResult,
                'leave_days'      => $leaveDays,
                'policy_name'     => $policy?->name,
                'policy_flags'    => [
                    'leave_encashment'    => (bool) ($policy?->leave_encashment),
                    'gratuity_applicable' => (bool) ($policy?->gratuity_applicable),
                    'recovery_allowed'    => (bool) ($policy?->recovery_allowed),
                    'buyout_allowed'      => (bool) ($policy?->buyout_allowed),
                ],
            ],
            'earnings' => [
                'basic_salary'    => $basicMonthly, // reference (monthly basic); not summed into net
                'pending_salary'  => $pendingSalary,
                'leave_encashment'=> $leaveEncashment,
                'gratuity'        => $gratuity,
                'bonus'           => $bonus,
                'incentives'      => $incentives,
                'other_earnings'  => $otherEarnings,
            ],
            'recoveries' => [
                'notice_recovery'  => $noticeRecovery,
                'buyout_recovery'  => $buyoutRecovery,
                'asset_recovery'   => $assetRecovery,
                'other_deductions' => $otherDeductions,
            ],
            'totals' => [
                'gross_earnings'   => $grossEarnings,
                'total_recoveries' => $totalRecoveries,
                'net_settlement'   => $net,
            ],
        ];
    }

    /** Basic monthly from the active salary's structure (read-only); falls back to 50% gross. */
    private function basicFrom($salary, int $tenantId, float $grossMonthly): float
    {
        if ($salary && $salary->salary_structure_id) {
            try {
                $structure = $this->structures->show((int) $salary->salary_structure_id, $tenantId);
                foreach ($structure['lines'] as $line) {
                    $name = mb_strtolower((string) ($line['component_name'] ?? ''));
                    $code = mb_strtolower((string) ($line['code'] ?? ''));
                    if (str_contains($name, 'basic') || $code === 'basic') {
                        return round((float) $line['computed_amount'], 2);
                    }
                }
            } catch (BusinessException $e) {
                // structure removed — fall through to the ratio fallback
            }
        }

        return round($grossMonthly * 0.5, 2);
    }

    /* ── Lazy init ────────────────────────────────────────── */

    private function ensureForCompletedClearance(int $tenantId, ?User $actor): void
    {
        foreach ($this->repo->completedClearancesNeedingSettlement($tenantId) as $clearance) {
            HrExitSettlement::create([
                'tenant_id'       => $tenantId,
                'exit_request_id' => $clearance->exit_request_id,
                'clearance_id'    => $clearance->id,
                'employee_id'     => $clearance->employee_id,
                'status'          => HrExitSettlement::PENDING,
                'created_by'      => $actor?->id,
                'updated_by'      => $actor?->id,
            ]);
        }
    }

    /* ── Presenter + helpers ──────────────────────────────── */

    private function present(HrExitSettlement $s, bool $full = false): array
    {
        $exit = $s->exitRequest;
        $out = [
            'id' => $s->id,
            'exit_request_id' => $s->exit_request_id,
            'employee_id' => $s->employee_id,
            'employee_name' => $s->employee?->name,
            'employee_code' => $s->employee?->employee_code,
            'department' => $s->employee?->department,
            'designation' => $s->employee?->designation,
            'exit_type' => $exit?->exitType?->name,
            'last_working_date' => optional($exit?->last_working_date)->toDateString(),
            'status' => $s->status,
            'settlement_month' => $s->settlement_month,
            'gross_earnings' => $s->gross_earnings !== null ? (float) $s->gross_earnings : null,
            'total_recoveries' => $s->total_recoveries !== null ? (float) $s->total_recoveries : null,
            'net_settlement' => $s->net_settlement !== null ? (float) $s->net_settlement : null,
            'generated_at' => optional($s->generated_at)->toIso8601String(),
            'reviewed_at' => optional($s->reviewed_at)->toIso8601String(),
            'approved_at' => optional($s->approved_at)->toIso8601String(),
            'settled_at' => optional($s->settled_at)->toIso8601String(),
        ];

        if ($full) {
            $out['components'] = $s->components;
            $out['exit'] = [
                'exit_type' => $exit?->exitType?->name,
                'status' => $exit?->status,
                'notice_days' => $exit?->notice_days,
                'last_working_date' => optional($exit?->last_working_date)->toDateString(),
                'policy_name' => $exit?->policy?->name,
            ];
            $out['timeline'] = $s->relationLoaded('auditLogs')
                ? $s->auditLogs->sortBy('id')->values()->map(fn ($l) => [
                    'action' => $l->action, 'actor_name' => $l->actor_name,
                    'comment' => $l->comment, 'created_at' => optional($l->created_at)->toIso8601String(),
                ])->all()
                : [];
        }

        return $out;
    }

    private function find(int $id, int $tenantId): HrExitSettlement
    {
        $settlement = $this->repo->find($id, $tenantId);
        if (! $settlement) {
            throw new BusinessException('Settlement not found', 404);
        }

        return $settlement;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
