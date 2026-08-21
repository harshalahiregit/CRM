<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvActivity;
use App\Models\Tpv\TpvWorkPackage;

/**
 * TPV Work Packages & Activities (Sangoe TPV §13). CRUD over the accountability
 * spine; tenant-scoped, references auto-generate on the model.
 */
class TpvWorkPackageService
{
    public function list(int $tenantId, array $filters = [])
    {
        return TpvWorkPackage::forTenant($tenantId)
            ->with('vendor:id,company_name,vendor_code')
            ->withCount(['activities', 'workers'])
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')
            ->get();
    }

    public function create(array $data, int $tenantId, int $userId): TpvWorkPackage
    {
        return TpvWorkPackage::create([
            ...$data,
            'tenant_id' => $tenantId,
            'created_by' => $userId,
            'status' => $data['status'] ?? 'Planned',
        ])->load('vendor:id,company_name,vendor_code');
    }

    public function update(TpvWorkPackage $wp, array $data): TpvWorkPackage
    {
        $wp->update($data);

        return $wp->load('vendor:id,company_name,vendor_code');
    }

    public function detail(TpvWorkPackage $wp): TpvWorkPackage
    {
        return $wp->load([
            'vendor:id,company_name,vendor_code',
            'activities',
            'workers:id,work_package_id,name,worker_code,status',
        ]);
    }

    public function delete(TpvWorkPackage $wp): void
    {
        $wp->delete();
    }

    /* ── Activities ─────────────────────────────────────────────────────── */

    public function addActivity(TpvWorkPackage $wp, array $data): TpvActivity
    {
        return $wp->activities()->create([
            ...$data,
            'tenant_id' => $wp->tenant_id,
            'status' => $data['status'] ?? 'Not_Started',
        ]);
    }

    public function updateActivity(TpvActivity $activity, array $data): TpvActivity
    {
        $activity->update($data);

        return $activity;
    }

    public function deleteActivity(TpvActivity $activity): void
    {
        $activity->delete();
    }
}
