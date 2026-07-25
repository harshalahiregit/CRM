<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrProbationPolicy;
use App\Models\Hr\HrProbationType;
use Illuminate\Database\Eloquent\Collection;

/** Read queries for Probation Management masters (Phase 1). Tenant-scoped; no writes. */
class ProbationRepository
{
    /* ── Probation Types ──────────────────────────────────── */
    public function types(int $tenantId, array $f): Collection
    {
        return HrProbationType::where('tenant_id', $tenantId)
            ->when($this->statusSet($f), fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where(function ($w) use ($f) {
                $w->where('name', 'like', '%'.$f['search'].'%')->orWhere('code', 'like', '%'.$f['search'].'%');
            }))
            ->orderBy('name')->get();
    }

    public function findType(int $id, int $tenantId): ?HrProbationType
    {
        return HrProbationType::where('tenant_id', $tenantId)->find($id);
    }

    public function typeStats(int $tenantId): array
    {
        $base = HrProbationType::where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'active'   => (clone $base)->where('is_active', true)->count(),
            'inactive' => (clone $base)->where('is_active', false)->count(),
        ];
    }

    /* ── Probation Policies ───────────────────────────────── */
    public function policies(int $tenantId, array $f): Collection
    {
        return HrProbationPolicy::where('tenant_id', $tenantId)
            ->with(['probationType:id,name,code', 'department:id,name', 'designation:id,name', 'grade:id,name'])
            ->when($this->statusSet($f), fn ($q) => $q->where('is_active', $f['status'] === 'Active'))
            ->when(! empty($f['search']), fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%'))
            ->orderBy('name')->get();
    }

    public function findPolicy(int $id, int $tenantId): ?HrProbationPolicy
    {
        return HrProbationPolicy::where('tenant_id', $tenantId)
            ->with(['probationType:id,name,code', 'department:id,name', 'designation:id,name', 'grade:id,name'])
            ->find($id);
    }

    public function policyStats(int $tenantId): array
    {
        $base = HrProbationPolicy::where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'active'   => (clone $base)->where('is_active', true)->count(),
            'inactive' => (clone $base)->where('is_active', false)->count(),
        ];
    }

    private function statusSet(array $f): bool
    {
        return isset($f['status']) && $f['status'] !== '' && $f['status'] !== 'All';
    }
}
