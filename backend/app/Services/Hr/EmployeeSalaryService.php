<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrEmployeeSalary;
use App\Models\Hr\HrSalaryRevision;
use App\Models\User;
use App\Repositories\Hr\EmployeeSalaryRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Employee Salary Assignment (Payroll Phase 3).
 *
 * Assigns a Salary Structure to an employee and freezes a SNAPSHOT of the
 * computed figures — later edits to the structure never touch existing history.
 * Exactly one row is active per employee; assigning a new structure archives the
 * previous active row (a salary revision). No hard delete, no payroll processing.
 */
class EmployeeSalaryService
{
    public function __construct(
        private EmployeeSalaryRepository $repo,
        private SalaryStructureService $structures,
    ) {
    }

    /** Current salary + full history for one employee. */
    public function forEmployee(int $employeeId, int $tenantId): array
    {
        $this->assertEmployee($employeeId, $tenantId);

        $current = $this->repo->currentActive($employeeId, $tenantId);

        return [
            'current' => $current ? $this->present($current) : null,
            'history' => $this->repo->historyFor($employeeId, $tenantId)->map(fn ($s) => $this->present($s))->all(),
            'revisions' => $this->revisions($employeeId, $tenantId),
        ];
    }

    /** Append-only salary revision ledger for one employee (newest first). */
    public function revisions(int $employeeId, int $tenantId): array
    {
        return HrSalaryRevision::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->orderByDesc('revision_no')->orderByDesc('id')->get()
            ->map(fn ($r) => [
                'id'                => $r->id,
                'revision_no'       => $r->revision_no,
                'effective_from'    => optional($r->effective_from)->toDateString(),
                'reason'            => $r->reason,
                'to_structure'      => $r->toStructure?->name,
                'previous_monthly_ctc' => $r->previous_monthly_ctc !== null ? (float) $r->previous_monthly_ctc : null,
                'new_monthly_ctc'   => (float) $r->new_monthly_ctc,
                'new_annual_ctc'    => (float) $r->new_annual_ctc,
                'new_net_salary'    => (float) $r->new_net_salary,
                'changed_by'        => $r->changedBy?->name,
                'created_at'        => optional($r->created_at)->toIso8601String(),
            ])->all();
    }

    /**
     * Assign a structure to an employee. Archives the current active salary (if any)
     * so only one is ever active, then stores a frozen snapshot as a new active row.
     */
    public function assign(int $employeeId, array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertEmployee($employeeId, $tenantId);
        $snapshot = $this->snapshotFromStructure((int) $data['salary_structure_id'], $tenantId);
        $effectiveFrom = $data['effective_from'];
        $structureName = $snapshot['_structure_name'] ?? null;
        unset($snapshot['_structure_name']);

        DB::transaction(function () use ($employeeId, $tenantId, $data, $snapshot, $effectiveFrom, $structureName, $actor) {
            // Archive the outgoing active salary — history stays intact, never edited away.
            $current = $this->repo->currentActive($employeeId, $tenantId);
            if ($current) {
                $endDate = Carbon::parse($effectiveFrom)->subDay();
                $current->update([
                    'status'       => HrEmployeeSalary::INACTIVE,
                    'effective_to' => $current->effective_from && $endDate->lt($current->effective_from) ? $current->effective_from : $endDate,
                    'updated_by'   => $actor?->id,
                ]);
                $current->recordAudit('Salary Deactivated', $actor, 'Superseded by a new salary assignment');
            }

            // Next revision number for this employee (append-only, never reused).
            $revisionNo = (int) HrEmployeeSalary::where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)->max('revision_no') + 1;

            $salary = HrEmployeeSalary::create([
                'tenant_id'          => $tenantId,
                'employee_id'        => $employeeId,
                'salary_structure_id'=> (int) $data['salary_structure_id'],
                'effective_from'     => $effectiveFrom,
                'effective_to'       => $data['effective_to'] ?? null,
                'revision_no'        => $revisionNo,
                'reason'             => $data['reason'] ?? ($current ? 'Salary revision' : 'Initial assignment'),
                'assigned_by'        => $actor?->id,
                'status'             => HrEmployeeSalary::ACTIVE,
                'created_by'         => $actor?->id,
                'updated_by'         => $actor?->id,
                ...$snapshot,
            ]);
            $salary->recordAudit($current ? 'Salary Revised' : 'Salary Assigned', $actor, null, [
                'structure'   => $structureName,
                'revision_no' => $revisionNo,
                'monthly_ctc' => $snapshot['monthly_ctc'],
                'annual_ctc'  => $snapshot['annual_ctc'],
            ]);

            // Append-only revision ledger row (immutable).
            HrSalaryRevision::create([
                'tenant_id'                 => $tenantId,
                'employee_id'               => $employeeId,
                'employee_salary_id'        => $salary->id,
                'from_structure_id'         => $current?->salary_structure_id,
                'to_structure_id'           => (int) $data['salary_structure_id'],
                'revision_no'               => $revisionNo,
                'effective_from'            => $effectiveFrom,
                'reason'                    => $data['reason'] ?? ($current ? 'Salary revision' : 'Initial assignment'),
                'previous_monthly_ctc'      => $current?->monthly_ctc,
                'previous_annual_ctc'       => $current?->annual_ctc,
                'previous_net_salary'       => $current?->net_salary,
                'new_monthly_ctc'           => $snapshot['monthly_ctc'],
                'new_annual_ctc'            => $snapshot['annual_ctc'],
                'new_gross_salary'          => $snapshot['gross_salary'],
                'new_employer_contribution' => $snapshot['total_benefits'],
                'new_total_deduction'       => $snapshot['total_deductions'],
                'new_net_salary'            => $snapshot['net_salary'],
                'changed_by'                => $actor?->id,
            ]);

            $this->log('Salary assigned', $tenantId, $salary->id);
        });

        return $this->forEmployee($employeeId, $tenantId);
    }

    /**
     * Apply a performance increment as a salary revision (Phase 2 integration). Scales
     * the current active snapshot up by the increment — a percentage, or an absolute
     * annual amount — keeping the same structure. Archives the old row and appends a
     * new active revision + ledger entry; history is never modified. Returns null if
     * the employee has no active salary to increment. Additive: assign() is untouched.
     */
    public function applyIncrement(int $employeeId, array $opts, int $tenantId, ?User $actor = null): ?array
    {
        $this->assertEmployee($employeeId, $tenantId);
        $current = $this->repo->currentActive($employeeId, $tenantId);
        if (! $current) {
            return null; // no base salary — nothing to increment
        }

        $effectiveFrom = $opts['effective_from'] ?? now()->toDateString();
        $reason = $opts['reason'] ?? 'Performance increment';

        $currentAnnual = (float) $current->annual_ctc;
        if (! empty($opts['percentage'])) {
            $factor = 1 + ((float) $opts['percentage']) / 100;
        } elseif (! empty($opts['amount']) && $currentAnnual > 0) {
            $factor = ($currentAnnual + (float) $opts['amount']) / $currentAnnual;
        } else {
            $factor = 1.0;
        }
        if ($factor <= 1.0) {
            return null; // a zero/negative increment produces no revision
        }

        $scale = fn ($v) => round((float) $v * $factor, 2);
        $snapshot = [
            'monthly_ctc'      => $scale($current->monthly_ctc),
            'annual_ctc'       => $scale($current->annual_ctc),
            'gross_salary'     => $scale($current->gross_salary),
            'total_benefits'   => $scale($current->total_benefits),
            'total_deductions' => $scale($current->total_deductions),
            'net_salary'       => $scale($current->net_salary),
        ];

        DB::transaction(function () use ($current, $employeeId, $tenantId, $snapshot, $effectiveFrom, $reason, $actor) {
            $endDate = Carbon::parse($effectiveFrom)->subDay();
            $current->update([
                'status'       => HrEmployeeSalary::INACTIVE,
                'effective_to' => $current->effective_from && $endDate->lt($current->effective_from) ? $current->effective_from : $endDate,
                'updated_by'   => $actor?->id,
            ]);
            $current->recordAudit('Salary Deactivated', $actor, 'Superseded by an increment');

            $revisionNo = (int) HrEmployeeSalary::where('tenant_id', $tenantId)
                ->where('employee_id', $employeeId)->max('revision_no') + 1;

            $salary = HrEmployeeSalary::create([
                'tenant_id'          => $tenantId,
                'employee_id'        => $employeeId,
                'salary_structure_id'=> $current->salary_structure_id,   // same structure, higher CTC
                'effective_from'     => $effectiveFrom,
                'effective_to'       => null,
                'revision_no'        => $revisionNo,
                'reason'             => $reason,
                'assigned_by'        => $actor?->id,
                'status'             => HrEmployeeSalary::ACTIVE,
                'created_by'         => $actor?->id,
                'updated_by'         => $actor?->id,
                ...$snapshot,
            ]);
            $salary->recordAudit('Salary Revised', $actor, null, ['type' => 'increment', 'revision_no' => $revisionNo, 'monthly_ctc' => $snapshot['monthly_ctc']]);

            HrSalaryRevision::create([
                'tenant_id'                 => $tenantId,
                'employee_id'               => $employeeId,
                'employee_salary_id'        => $salary->id,
                'from_structure_id'         => $current->salary_structure_id,
                'to_structure_id'           => $current->salary_structure_id,
                'revision_no'               => $revisionNo,
                'effective_from'            => $effectiveFrom,
                'reason'                    => $reason,
                'previous_monthly_ctc'      => $current->monthly_ctc,
                'previous_annual_ctc'       => $current->annual_ctc,
                'previous_net_salary'       => $current->net_salary,
                'new_monthly_ctc'           => $snapshot['monthly_ctc'],
                'new_annual_ctc'            => $snapshot['annual_ctc'],
                'new_gross_salary'          => $snapshot['gross_salary'],
                'new_employer_contribution' => $snapshot['total_benefits'],
                'new_total_deduction'       => $snapshot['total_deductions'],
                'new_net_salary'            => $snapshot['net_salary'],
                'changed_by'                => $actor?->id,
            ]);
            $this->log('Increment applied as salary revision', $tenantId, $salary->id);
        });

        return $this->forEmployee($employeeId, $tenantId);
    }

    /**
     * Update an existing salary row (effective dates, and/or re-point to another
     * structure which re-snapshots). Only this row is touched — other history rows
     * are never modified.
     */
    public function update(int $employeeId, int $id, array $data, int $tenantId, ?User $actor = null): array
    {
        $this->assertEmployee($employeeId, $tenantId);
        $salary = $this->find($id, $employeeId, $tenantId);

        $attrs = ['updated_by' => $actor?->id];
        if (array_key_exists('effective_from', $data) && $data['effective_from']) {
            $attrs['effective_from'] = $data['effective_from'];
        }
        if (array_key_exists('effective_to', $data)) {
            $attrs['effective_to'] = $data['effective_to'] ?: null;
        }
        // Re-snapshot only if the structure is explicitly changed.
        if (! empty($data['salary_structure_id']) && (int) $data['salary_structure_id'] !== (int) $salary->salary_structure_id) {
            $snapshot = $this->snapshotFromStructure((int) $data['salary_structure_id'], $tenantId);
            unset($snapshot['_structure_name']);
            $attrs = array_merge($attrs, $snapshot, ['salary_structure_id' => (int) $data['salary_structure_id']]);
        }

        $salary->update($attrs);
        $salary->recordAudit('Salary Updated', $actor, null, ['monthly_ctc' => $salary->monthly_ctc]);
        $this->log('Salary updated', $tenantId, $salary->id);

        return $this->forEmployee($employeeId, $tenantId);
    }

    /** Activate (enforcing single-active) or deactivate a salary row. */
    public function setStatus(int $employeeId, int $id, bool $active, int $tenantId, ?User $actor = null): array
    {
        $this->assertEmployee($employeeId, $tenantId);
        $salary = $this->find($id, $employeeId, $tenantId);

        DB::transaction(function () use ($salary, $employeeId, $tenantId, $active, $actor) {
            if ($active) {
                // Only one active salary at a time — stand down any other active rows.
                HrEmployeeSalary::where('tenant_id', $tenantId)
                    ->where('employee_id', $employeeId)
                    ->where('status', HrEmployeeSalary::ACTIVE)
                    ->where('id', '!=', $salary->id)
                    ->update(['status' => HrEmployeeSalary::INACTIVE, 'updated_by' => $actor?->id]);
            }
            $salary->update(['status' => $active ? HrEmployeeSalary::ACTIVE : HrEmployeeSalary::INACTIVE, 'updated_by' => $actor?->id]);
            $salary->recordAudit($active ? 'Salary Activated' : 'Salary Deactivated', $actor);
        });

        return $this->forEmployee($employeeId, $tenantId);
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshot + helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Compute a frozen snapshot from a Salary Structure's (monthly) breakdown.
     * Throws if the structure is not in this tenant. Returns the persisted columns
     * plus a `_structure_name` hint for the audit metadata (stripped before create).
     */
    private function snapshotFromStructure(int $structureId, int $tenantId): array
    {
        // Reuses Phase 2 — also validates tenant ownership (404 BusinessException if not).
        $structure = $this->structures->show($structureId, $tenantId);
        $t = $structure['totals'];

        return [
            'monthly_ctc'      => round($t['ctc'], 2),
            'annual_ctc'       => round($t['ctc'] * 12, 2),
            'gross_salary'     => round($t['gross_earnings'], 2),
            'total_benefits'   => round($t['employer_benefits'], 2),
            'total_deductions' => round($t['deductions'], 2),
            'net_salary'       => round($t['net_pay'], 2),
            '_structure_name'  => $structure['name'],
        ];
    }

    private function present(HrEmployeeSalary $s): array
    {
        return [
            'id'                => $s->id,
            'employee_id'       => $s->employee_id,
            'salary_structure_id'=> $s->salary_structure_id,
            'structure_name'    => $s->structure?->name,
            'structure_code'    => $s->structure?->code,
            'effective_from'    => optional($s->effective_from)->toDateString(),
            'effective_to'      => optional($s->effective_to)->toDateString(),
            'annual_ctc'        => (float) $s->annual_ctc,
            'monthly_ctc'       => (float) $s->monthly_ctc,
            'gross_salary'      => (float) $s->gross_salary,
            'total_benefits'    => (float) $s->total_benefits,
            'total_deductions'  => (float) $s->total_deductions,
            'net_salary'        => (float) $s->net_salary,
            'status'            => $s->status,
            'created_at'        => optional($s->created_at)->toIso8601String(),
        ];
    }

    private function assertEmployee(int $employeeId, int $tenantId): HrEmployee
    {
        $employee = HrEmployee::where('tenant_id', $tenantId)->find($employeeId);
        if (! $employee) {
            throw new BusinessException('Employee not found', 404);
        }

        return $employee;
    }

    private function find(int $id, int $employeeId, int $tenantId): HrEmployeeSalary
    {
        $salary = $this->repo->findForTenant($id, $employeeId, $tenantId);
        if (! $salary) {
            throw new BusinessException('Salary record not found', 404);
        }

        return $salary;
    }

    private function log(string $msg, int $tenantId, int $id): void
    {
        Log::channel('hr')->info($msg, ['tenant_id' => $tenantId, 'id' => $id]);
    }
}
