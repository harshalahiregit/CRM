<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrExitClearance;
use App\Models\Hr\HrExitRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/** Read queries for Exit Clearance (Phase 4). Tenant-scoped; no writes. */
class ClearanceRepository
{
    private const EAGER = [
        'items',
        'employee:id,name,employee_code,department,designation',
        'exitRequest:id,exit_type_id,status,notice_days,notice_start_date,notice_end_date,last_working_date,decided_at',
        'exitRequest.exitType:id,name,code',
    ];

    /** Approved exit requests that have no clearance record yet — need lazy init. */
    public function approvedExitsNeedingClearance(int $tenantId): Collection
    {
        return HrExitRequest::where('tenant_id', $tenantId)
            ->where('status', HrExitRequest::APPROVED)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('hr_exit_clearances')
                    ->whereColumn('hr_exit_clearances.exit_request_id', 'hr_exit_requests.id');
            })
            ->get();
    }

    public function queue(int $tenantId, array $f): Collection
    {
        return HrExitClearance::where('tenant_id', $tenantId)
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['department']) && $f['department'] !== 'All', fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department', $f['department'])))
            ->when(! empty($f['exit_type_id']), fn ($q) => $q->whereHas('exitRequest', fn ($r) => $r->where('exit_type_id', $f['exit_type_id'])))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('employee_code', 'like', '%'.$f['search'].'%');
            })))
            ->orderByDesc('id')->get();
    }

    public function find(int $id, int $tenantId): ?HrExitClearance
    {
        return HrExitClearance::where('tenant_id', $tenantId)
            ->with([...self::EAGER, 'auditLogs'])
            ->find($id);
    }

    public function findByEmployee(int $employeeId, int $tenantId): ?HrExitClearance
    {
        return HrExitClearance::where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->with([...self::EAGER, 'auditLogs'])
            ->orderByDesc('id')->first();
    }

    public function history(int $tenantId, array $f): Collection
    {
        return HrExitClearance::where('tenant_id', $tenantId)
            ->whereIn('status', [HrExitClearance::COMPLETED, HrExitClearance::REJECTED])
            ->with(self::EAGER)
            ->when(! empty($f['employee_id']), fn ($q) => $q->where('employee_id', $f['employee_id']))
            ->orderByDesc('completed_at')->orderByDesc('id')->get();
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => HrExitClearance::where('tenant_id', $tenantId);
        $monthStart = Carbon::today()->startOfMonth();
        $monthEnd = Carbon::today()->endOfMonth();

        return [
            'pending'      => (int) $base()->where('status', HrExitClearance::PENDING)->count(),
            'in_progress'  => (int) $base()->where('status', HrExitClearance::IN_PROGRESS)->count(),
            'cleared'      => (int) $base()->where('status', HrExitClearance::COMPLETED)->count(),
            'rejected'     => (int) $base()->where('status', HrExitClearance::REJECTED)->count(),
            'completed_this_month' => (int) $base()->where('status', HrExitClearance::COMPLETED)
                ->whereBetween('completed_at', [$monthStart, $monthEnd])->count(),
        ];
    }
}
