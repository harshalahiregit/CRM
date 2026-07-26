<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrEmployeeProbation;
use App\Models\Hr\HrProbationExtension;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for Probation Extensions (Phase 4). Tenant-scoped; no writes. */
class ProbationExtensionRepository
{
    private const EAGER = [
        'employee:id,name,employee_code,department,designation',
        'requestedBy:id,name',
        'probation:id,current_status,probation_end_date,extension_count,probation_policy_id,probation_type_id',
        'probation.policy:id,name,extension_limit', 'probation.probationType:id,name,max_extensions',
    ];

    public function list(int $tenantId, array $f): Collection
    {
        return HrProbationExtension::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['probation_id']), fn ($q) => $q->where('probation_id', $f['probation_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['from']), fn ($q) => $q->whereDate('created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->whereDate('created_at', '<=', $f['to']))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrProbationExtension
    {
        return HrProbationExtension::where('tenant_id', $tenantId)->with([...self::EAGER, 'auditLogs'])->find($id);
    }

    public function forEmployee(int $employeeId, int $tenantId): Collection
    {
        return HrProbationExtension::where('tenant_id', $tenantId)->where('employee_id', $employeeId)
            ->with([...self::EAGER, 'auditLogs'])->orderByDesc('id')->get();
    }

    /** Decided extensions (Approved / Rejected) — the extension history log. */
    public function history(int $tenantId, array $f): Collection
    {
        return HrProbationExtension::where('tenant_id', $tenantId)
            ->whereIn('status', HrProbationExtension::TERMINAL)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->orderByDesc('id')->get();
    }

    public function pendingExists(int $probationId, int $tenantId): bool
    {
        return HrProbationExtension::where('tenant_id', $tenantId)
            ->where('probation_id', $probationId)->where('status', HrProbationExtension::PENDING)->exists();
    }

    public function nextExtensionNumber(int $probationId, int $tenantId): int
    {
        return (int) HrProbationExtension::where('tenant_id', $tenantId)
            ->where('probation_id', $probationId)->max('extension_number') + 1;
    }

    public function stats(int $tenantId): array
    {
        $rows = HrProbationExtension::where('tenant_id', $tenantId)
            ->selectRaw("COUNT(*) as total,
                SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected,
                AVG(CASE WHEN status='Approved' THEN extension_days END) as avg_days")->first();

        // Active extensions = approved extensions whose probation is still Active/Extended.
        $active = (int) HrProbationExtension::where('hr_probation_extensions.tenant_id', $tenantId)
            ->where('hr_probation_extensions.status', HrProbationExtension::APPROVED)
            ->whereHas('probation', fn ($p) => $p->whereIn('current_status', [HrEmployeeProbation::ACTIVE, HrEmployeeProbation::EXTENDED]))
            ->count();

        return [
            'pending'  => (int) ($rows->pending ?? 0),
            'approved' => (int) ($rows->approved ?? 0),
            'rejected' => (int) ($rows->rejected ?? 0),
            'active_extensions' => $active,
            'avg_days' => round((float) ($rows->avg_days ?? 0), 1),
        ];
    }
}
