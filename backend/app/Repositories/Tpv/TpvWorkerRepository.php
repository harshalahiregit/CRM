<?php

namespace App\Repositories\Tpv;

use App\Models\Tpv\TpvWorker;
use App\Repositories\BaseRepository;

class TpvWorkerRepository extends BaseRepository
{
    protected string $modelClass = TpvWorker::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = TpvWorker::forTenant($tenantId)
            ->with(['vendor:id,vendor_code,company_name,status', 'medical:id,tpv_worker_id,fitness_status', 'induction:id,tpv_worker_id,passed']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }
        if (! empty($filters['skill_category']) && $filters['skill_category'] !== 'All') {
            $query->where('skill_category', $filters['skill_category']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('worker_code', 'like', '%'.$search.'%')
                  ->orWhere('mobile', 'like', '%'.$search.'%')
                  ->orWhere('designation', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }
}
