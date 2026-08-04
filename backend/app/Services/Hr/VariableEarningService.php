<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrEmployeeVariableEarning;
use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrSalaryComponent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Review comment #31 — "Earnings: Commissions/Incentives for employees".
 *
 * Owns the lifecycle of a variable earning (raise → approve → paid) and hands
 * payroll the lines for a period. It computes no salary and no tax: the component
 * master decides how the money is treated and StatutoryEngine applies that, which
 * is why a commission needs no special case anywhere in the payroll engine.
 */
class VariableEarningService
{
    /** Raise or amend a commission/incentive for one employee and period. */
    public function save(array $data, int $tenantId, ?User $actor = null): HrEmployeeVariableEarning
    {
        $component = HrSalaryComponent::where('tenant_id', $tenantId)
            ->where('id', $data['component_id'] ?? 0)->first();

        if (! $component) {
            throw new BusinessException('Select a salary component for this earning', 422);
        }

        // A commission paid against a Deduction component would subtract money
        // from someone's pay under the heading "incentive".
        if ($component->type !== 'Earning') {
            throw new BusinessException(
                'Only an Earning component can carry a commission or incentive — "'
                .$component->name.'" is a '.$component->type.' component.', 422
            );
        }

        if ((float) ($data['amount'] ?? 0) <= 0) {
            throw new BusinessException('Amount must be greater than zero', 422);
        }

        if (! preg_match('/^\d{4}-\d{2}$/', (string) ($data['period'] ?? ''))) {
            throw new BusinessException('Period must be in YYYY-MM format', 422);
        }

        $existing = ! empty($data['id'])
            ? HrEmployeeVariableEarning::forTenant($tenantId)->find($data['id'])
            : null;

        if ($existing && $existing->status === HrEmployeeVariableEarning::PAID) {
            throw new BusinessException('This earning has already been paid by a payroll run and can no longer be edited', 422);
        }

        $payload = [
            'tenant_id'    => $tenantId,
            'employee_id'  => $data['employee_id'],
            'component_id' => $component->id,
            'period'       => $data['period'],
            'amount'       => round((float) $data['amount'], 2),
            'reference'    => $data['reference'] ?? null,
            'remarks'      => $data['remarks'] ?? null,
            'updated_by'   => $actor?->id,
        ];

        if ($existing) {
            // Editing the figure invalidates the approval it was granted under.
            $existing->update($payload + ['status' => HrEmployeeVariableEarning::PENDING,
                'approved_by' => null, 'approved_at' => null]);
            $existing->recordAudit('Variable earning updated', $actor, null, ['period' => $payload['period']]);

            return $existing->fresh();
        }

        $earning = HrEmployeeVariableEarning::create($payload + [
            'status' => HrEmployeeVariableEarning::PENDING, 'created_by' => $actor?->id,
        ]);
        $earning->recordAudit('Variable earning raised', $actor, null,
            ['period' => $payload['period'], 'amount' => $payload['amount']]);

        return $earning;
    }

    public function approve(int $id, int $tenantId, ?User $actor = null): HrEmployeeVariableEarning
    {
        $earning = $this->find($id, $tenantId);

        if ($earning->status === HrEmployeeVariableEarning::PAID) {
            throw new BusinessException('This earning has already been paid', 422);
        }

        $earning->update([
            'status' => HrEmployeeVariableEarning::APPROVED,
            'approved_by' => $actor?->id, 'approved_at' => now(),
        ]);
        $earning->recordAudit('Variable earning approved', $actor);

        return $earning->fresh();
    }

    public function reject(int $id, int $tenantId, string $remarks, ?User $actor = null): HrEmployeeVariableEarning
    {
        $earning = $this->find($id, $tenantId);

        if ($earning->status === HrEmployeeVariableEarning::PAID) {
            throw new BusinessException('This earning has already been paid and cannot be rejected', 422);
        }

        $earning->update(['status' => HrEmployeeVariableEarning::REJECTED, 'remarks' => $remarks]);
        $earning->recordAudit('Variable earning rejected', $actor, $remarks);

        return $earning->fresh();
    }

    public function destroy(int $id, int $tenantId, ?User $actor = null): void
    {
        $earning = $this->find($id, $tenantId);

        if ($earning->status === HrEmployeeVariableEarning::PAID) {
            throw new BusinessException('A paid earning is part of a payroll record and cannot be deleted', 422);
        }

        $earning->recordAudit('Variable earning deleted', $actor);
        $earning->delete();
    }

    public function list(int $tenantId, array $filters = []): array
    {
        $query = HrEmployeeVariableEarning::forTenant($tenantId)
            ->with(['employee:id,name,employee_code,department', 'component:id,name,code,type']);

        foreach (['employee_id', 'period', 'status', 'component_id'] as $key) {
            if (! empty($filters[$key]) && $filters[$key] !== 'All') {
                $query->where($key, $filters[$key]);
            }
        }

        return $query->orderByDesc('period')->orderByDesc('id')->get()->map(fn ($e) => [
            'id' => $e->id, 'employee_id' => $e->employee_id,
            'employee_name' => $e->employee?->name, 'employee_code' => $e->employee?->employee_code,
            'department' => $e->employee?->department,
            'component_id' => $e->component_id, 'component_name' => $e->component?->name,
            'period' => $e->period, 'amount' => (float) $e->amount,
            'reference' => $e->reference, 'remarks' => $e->remarks,
            'status' => $e->status, 'approved_at' => optional($e->approved_at)->toDateTimeString(),
            'payroll_record_id' => $e->payroll_record_id,
        ])->all();
    }

    /**
     * Approved earnings for one employee and period, as payroll component lines.
     *
     * The flags are copied from the component so StatutoryEngine treats a
     * commission exactly as it treats any other earning — that is the whole
     * reason this integrates without touching the engine.
     */
    public function linesFor(int $employeeId, int $tenantId, string $period): array
    {
        return HrEmployeeVariableEarning::forTenant($tenantId)
            ->where('employee_id', $employeeId)
            ->where('period', $period)
            ->where('status', HrEmployeeVariableEarning::APPROVED)
            ->with('component')
            ->get()
            ->map(fn ($e) => [
                'component_id'     => $e->component_id,
                'code'             => $e->component?->code,
                'component_name'   => $e->component?->name ?? 'Variable Earning',
                'name'             => $e->component?->name ?? 'Variable Earning',
                'type'             => 'Earning',
                'source'           => 'variable',
                'computed_amount'  => (float) $e->amount,
                'amount'           => (float) $e->amount,
                'taxable'          => (bool) $e->component?->taxable,
                'pf_applicable'    => (bool) $e->component?->pf_applicable,
                'esic_applicable'  => (bool) $e->component?->esic_applicable,
                'sort_order'       => 800,
            ])->all();
    }

    /**
     * Mark this period's approved earnings as paid by the given record.
     *
     * Returns the total so payroll can stamp it on the record without summing the
     * lines a second time.
     */
    public function markPaid(HrPayrollRecord $record, int $tenantId, string $period): float
    {
        return DB::transaction(function () use ($record, $tenantId, $period) {
            $rows = HrEmployeeVariableEarning::forTenant($tenantId)
                ->where('employee_id', $record->employee_id)
                ->where('period', $period)
                ->where('status', HrEmployeeVariableEarning::APPROVED)
                ->lockForUpdate()->get();

            foreach ($rows as $row) {
                $row->update(['status' => HrEmployeeVariableEarning::PAID, 'payroll_record_id' => $record->id]);
            }

            return round((float) $rows->sum('amount'), 2);
        });
    }

    /**
     * Return a run's earnings to Approved.
     *
     * Mirrors LoanRecoveryService's release: reprocessing a draft run deletes its
     * records, and an earning still marked Paid against a record that no longer
     * exists would never be paid at all.
     */
    public function releaseForRun(int $runId, int $tenantId): void
    {
        $recordIds = HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('payroll_run_id', $runId)->pluck('id');

        if ($recordIds->isEmpty()) {
            return;
        }

        HrEmployeeVariableEarning::forTenant($tenantId)
            ->whereIn('payroll_record_id', $recordIds)
            ->update(['status' => HrEmployeeVariableEarning::APPROVED, 'payroll_record_id' => null]);
    }

    /** #31 — what one run paid out in commissions, for the run summary. */
    public function runTotals(int $runId, int $tenantId): array
    {
        $records = HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('payroll_run_id', $runId)
            ->where('variable_earnings', '>', 0)
            ->get(['id', 'variable_earnings']);

        return [
            'total_paid'      => round((float) $records->sum('variable_earnings'), 2),
            'employees_count' => $records->count(),
        ];
    }

    private function find(int $id, int $tenantId): HrEmployeeVariableEarning
    {
        $earning = HrEmployeeVariableEarning::forTenant($tenantId)->find($id);

        if (! $earning) {
            throw new BusinessException('Variable earning not found', 404);
        }

        return $earning;
    }
}
