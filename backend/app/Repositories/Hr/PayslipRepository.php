<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrPayslip;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class PayslipRepository extends BaseRepository
{
    protected string $modelClass = HrPayslip::class;

    /** Tenant-scoped payslip list with optional month/year/status/search filters. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = HrPayslip::where('tenant_id', $tenantId)
            ->with('employee:id,name,employee_code,department,designation');

        if (! empty($filters['year']) && $filters['year'] !== 'All') {
            $query->where('payslip_year', $filters['year']);
        }
        if (! empty($filters['month']) && $filters['month'] !== 'All') {
            $query->where('payslip_month', $filters['month']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('payslip_number', 'like', '%'.$search.'%')
                  ->orWhereHas('employee', fn ($e) => $e->where('name', 'like', '%'.$search.'%')->orWhere('employee_code', 'like', '%'.$search.'%'));
            });
        }

        return $query->orderByDesc('payslip_year')->orderByDesc('payslip_month')->orderByDesc('id')->get();
    }

    public function findForTenant(int $id, int $tenantId): ?HrPayslip
    {
        return HrPayslip::where('tenant_id', $tenantId)
            ->with('employee:id,name,employee_code,department,designation')
            ->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrPayslip::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->orderByDesc('payslip_year')->orderByDesc('payslip_month')->orderByDesc('id')
            ->get();
    }

    public function existsForRecord(int $recordId, int $tenantId): bool
    {
        return HrPayslip::where('tenant_id', $tenantId)->where('payroll_record_id', $recordId)->exists();
    }

    /** Next sequence number for a tenant within a payslip period. */
    public function countForPeriod(int $tenantId, int $year, int $month): int
    {
        return HrPayslip::where('tenant_id', $tenantId)
            ->where('payslip_year', $year)
            ->where('payslip_month', $month)
            ->count();
    }
}
