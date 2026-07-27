<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrLeavePolicy;
use App\Models\Hr\HrLeaveType;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for Leave Management (Phase 1). Tenant-scoped; no writes. */
class LeaveRepository
{
    /* ── Leave Types ──────────────────────────────────────── */
    public function types(int $tenantId, array $f): Collection
    {
        return HrLeaveType::where('tenant_id', $tenantId)
            ->when(! empty($f['category']) && $f['category'] !== 'All', fn ($q) => $q->where('category', $f['category']))
            ->when(isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All', fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('code', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('name')->get();
    }

    public function findType(int $id, int $tenantId): ?HrLeaveType
    {
        return HrLeaveType::where('tenant_id', $tenantId)->find($id);
    }

    public function typeStats(int $tenantId): array
    {
        $base = HrLeaveType::where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'paid'     => (clone $base)->where('paid', true)->count(),
            'unpaid'   => (clone $base)->where('paid', false)->count(),
            'active'   => (clone $base)->where('is_active', true)->count(),
            'inactive' => (clone $base)->where('is_active', false)->count(),
        ];
    }

    /* ── Leave Policies ───────────────────────────────────── */
    public function policies(int $tenantId, array $f): Collection
    {
        return HrLeavePolicy::where('tenant_id', $tenantId)
            ->with(['policyTypes.leaveType:id,name,code,category', 'grade:id,name', 'designation:id,name'])
            ->when(isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All', fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%'))
            ->orderBy('name')->get();
    }

    public function findPolicy(int $id, int $tenantId): ?HrLeavePolicy
    {
        return HrLeavePolicy::where('tenant_id', $tenantId)
            ->with(['policyTypes.leaveType:id,name,code,category', 'grade:id,name', 'designation:id,name'])
            ->find($id);
    }
}
