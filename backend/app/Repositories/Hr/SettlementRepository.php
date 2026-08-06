<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitSettlement;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for Exit Full & Final Settlement (Phase 5). Tenant-scoped; no writes. */
class SettlementRepository
{
    private const EAGER = [
        // `joining_date` drives the tenure gratuity is computed from. It was missing
        // from this constrained list, so the attribute read back as NULL, tenure
        // computed as 0 years, and EVERY settlement paid zero gratuity regardless of
        // service or policy. Constrained eager loads silently return null for
        // unlisted columns — add a column here before reading it in the service.
        'employee:id,name,employee_code,department,designation,joining_date',
        'exitRequest:id,exit_type_id,exit_policy_id,status,last_working_date,notice_days',
        'exitRequest.exitType:id,name,code',
    ];

    /** Completed clearances whose exit has no settlement yet — need lazy init. */
    public function completedClearancesNeedingSettlement(int $tenantId): Collection
    {
        return HrExitClearance::where('tenant_id', $tenantId)
            ->where('status', HrExitClearance::COMPLETED)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('hr_exit_settlements')
                    ->whereColumn('hr_exit_settlements.exit_request_id', 'hr_exit_clearances.exit_request_id');
            })
            ->with('employee')
            ->get();
    }

    public function queue(int $tenantId, array $f): Collection
    {
        return HrExitSettlement::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['settlement_month']), fn ($q) => $q->where('settlement_month', $f['settlement_month']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['exit_type_id']), fn ($q) => $q->whereHas('exitRequest', fn ($r) => $r->where('exit_type_id', $f['exit_type_id'])))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrExitSettlement
    {
        return HrExitSettlement::where('tenant_id', $tenantId)
            ->with([...self::EAGER, 'exitRequest.policy:id,name,buyout_allowed,recovery_allowed,leave_encashment,gratuity_applicable', 'auditLogs'])
            ->find($id);
    }

    public function findByEmployee(int $employeeId, int $tenantId): ?HrExitSettlement
    {
        return HrExitSettlement::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->with([...self::EAGER, 'auditLogs'])
            ->orderByDesc('id')->first();
    }

    public function history(int $tenantId, array $f): Collection
    {
        return HrExitSettlement::where('tenant_id', $tenantId)
            ->whereIn('status', [HrExitSettlement::APPROVED, HrExitSettlement::SETTLED])
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->orderByDesc('settled_at')->orderByDesc('id')->get();
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => HrExitSettlement::where('tenant_id', $tenantId);

        return [
            'pending'   => (int) $base()->where('status', HrExitSettlement::PENDING)->count(),
            'generated' => (int) $base()->where('status', HrExitSettlement::GENERATED)->count(),
            'reviewed'  => (int) $base()->where('status', HrExitSettlement::REVIEWED)->count(),
            'approved'  => (int) $base()->where('status', HrExitSettlement::APPROVED)->count(),
            'settled'   => (int) $base()->where('status', HrExitSettlement::SETTLED)->count(),
        ];
    }

    /** Distinct settlement months present, for the filter dropdown. */
    public function months(int $tenantId): array
    {
        return HrExitSettlement::where('tenant_id', $tenantId)
            ->whereNotNull('settlement_month')
            ->distinct()->orderByDesc('settlement_month')->pluck('settlement_month')->all();
    }
}
