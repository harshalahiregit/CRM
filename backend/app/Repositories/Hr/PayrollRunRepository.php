<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrPayrollRecord;
use App\Models\Hr\HrPayrollRun;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class PayrollRunRepository extends BaseRepository
{
    protected string $modelClass = HrPayrollRun::class;

    /** Tenant-scoped run list, newest period first. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = HrPayrollRun::where('tenant_id', $tenantId);

        if (! empty($filters['year']) && $filters['year'] !== 'All') {
            $query->where('payroll_year', $filters['year']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('payroll_year')->orderByDesc('payroll_month')->get();
    }

    public function findForTenant(int $id, int $tenantId): ?HrPayrollRun
    {
        return HrPayrollRun::where('tenant_id', $tenantId)->find($id);
    }

    public function existsForMonth(int $tenantId, int $year, int $month): bool
    {
        return HrPayrollRun::where('tenant_id', $tenantId)
            ->where('payroll_year', $year)
            ->where('payroll_month', $month)
            ->exists();
    }

    /** Frozen per-employee records for a run, with the employee for display. */
    public function recordsForRun(int $runId, int $tenantId): Collection
    {
        return HrPayrollRecord::where('tenant_id', $tenantId)
            ->where('payroll_run_id', $runId)
            ->with('employee:id,name,employee_code,department,designation')
            ->orderBy('id')
            ->get();
    }
}
