<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationPolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Read queries for Employee Probation (Phase 2). Tenant-scoped; no writes. */
class EmployeeProbationRepository
{
    private const EAGER = [
        'employee:id,name,employee_code,department,designation',
        'policy:id,name,review_frequency', 'probationType:id,name,code,default_duration_days',
    ];

    public function list(int $tenantId, array $f): Collection
    {
        return HrEmployeeProbation::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['probation_policy_id']), fn ($q) => $q->where('probation_policy_id', $f['probation_policy_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('current_status', $f['status']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrEmployeeProbation
    {
        return HrEmployeeProbation::where('tenant_id', $tenantId)->with([...self::EAGER, 'auditLogs'])->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrEmployeeProbation::where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->with([...self::EAGER, 'auditLogs'])->orderByDesc('id')->get();
    }

    /** Existing open (Assigned/Active/Extended) or Confirmed probation blocks a new assignment. */
    public function blockingProbation(int $employeeId, int $tenantId): ?HrEmployeeProbation
    {
        return HrEmployeeProbation::where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->whereIn('current_status', array_merge(HrEmployeeProbation::OPEN, [HrEmployeeProbation::CONFIRMED]))
            ->first();
    }

    /** First active policy best-matching an employee's grade/designation/department (most specific wins). */
    public function policyForEmployee(int $tenantId, ?int $gradeId, ?int $designationId, ?int $departmentId): ?HrProbationPolicy
    {
        $base = fn () => HrProbationPolicy::where('tenant_id', $tenantId)->where('is_active', true);

        foreach ([
            ['grade_id' => $gradeId, 'designation_id' => $designationId, 'department_id' => $departmentId],
            ['designation_id' => $designationId],
            ['grade_id' => $gradeId],
            ['department_id' => $departmentId],
        ] as $criteria) {
            $criteria = array_filter($criteria, fn ($v) => ! empty($v));
            if (! $criteria) {
                continue;
            }
            $match = $base();
            foreach ($criteria as $col => $val) {
                $match->where($col, $val);
            }
            if ($hit = $match->orderBy('id')->first()) {
                return $hit;
            }
        }

        return null;
    }

    public function stats(int $tenantId): array
    {
        $rows = HrEmployeeProbation::where('tenant_id', $tenantId)
            ->selectRaw('current_status, count(*) as c')->groupBy('current_status')->pluck('c', 'current_status')->all();
        $today = Carbon::today()->toDateString();
        $pending = (int) HrEmployeeProbation::where('tenant_id', $tenantId)
            ->whereIn('current_status', [HrEmployeeProbation::ACTIVE, HrEmployeeProbation::EXTENDED])
            ->whereNotNull('confirmation_due_date')->whereDate('confirmation_due_date', '<=', $today)->count();

        return [
            'total'                => (int) array_sum($rows),
            'active'               => (int) ($rows[HrEmployeeProbation::ACTIVE] ?? 0),
            'extended'             => (int) ($rows[HrEmployeeProbation::EXTENDED] ?? 0),
            'confirmed'            => (int) ($rows[HrEmployeeProbation::CONFIRMED] ?? 0),
            'pending_confirmation' => $pending,
        ];
    }
}
