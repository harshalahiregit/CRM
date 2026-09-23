<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrPayslip;
use App\Models\User;
use App\Repositories\BaseRepository;
use App\Repositories\Hr\Concerns\ScopesEmployeeData;
use Illuminate\Database\Eloquent\Collection;

class PayslipRepository extends BaseRepository
{
    use ScopesEmployeeData;

    protected string $modelClass = HrPayslip::class;

    /** Tenant-scoped payslip list with optional month/year/status/search filters. */
    public function filtered(int $tenantId, array $filters, ?User $actor = null): Collection
    {
        $query = HrPayslip::where('tenant_id', $tenantId)
            ->with('employee:id,name,employee_code,department,designation');

        // Before the filters, so a filter can only narrow within the scope. A
        // payslip is the most disclosing row an employee owns — net pay, every
        // deduction, and their bank-facing figures.
        $query = $this->scopeToEmployees($query, $actor);

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

    /**
     * One payslip by id.
     *
     * The scope goes on the query rather than after the fetch, so an
     * out-of-scope payslip is indistinguishable from one that does not exist —
     * find() simply returns null and the caller's existing 404 stands. Telling
     * somebody "that payslip is not yours" confirms whose it is.
     */
    public function findForTenant(int $id, int $tenantId, ?User $actor = null): ?HrPayslip
    {
        return $this->scopeToEmployees(
            HrPayslip::where('tenant_id', $tenantId)
                ->with('employee:id,name,employee_code,department,designation'),
            $actor
        )->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId, ?User $actor = null): Collection
    {
        // The employee id arrives from the caller, so the list filter is not
        // enough on its own — this is the direct-id surface.
        $this->assertEmployeeInScope($actor, $employeeId);

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
