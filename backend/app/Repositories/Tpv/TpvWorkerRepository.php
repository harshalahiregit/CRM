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
            ->with([
                'vendor:id,vendor_code,company_name,status',
                // `medical` is a latestOfMany relation, so Eloquent loads it
                // through a self-join — unqualified column names in the select
                // are ambiguous there and the query fails outright. Qualify
                // them, and carry the quality-check state so the roster can say
                // whether a certificate is cleared, not merely recorded.
                'medical:tpv_worker_medicals.id,tpv_worker_medicals.tpv_worker_id,tpv_worker_medicals.fitness_status,tpv_worker_medicals.qc_status,tpv_worker_medicals.valid_until',
                'induction:id,tpv_worker_id,passed',
            ]);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        // One vendor, and only that vendor. This used to read "vendor_id = X OR
        // vendor_id = 1" — a workaround for bulk imports that were landing on
        // vendor 1 by default. The cost of the workaround was that the first
        // vendor's entire workforce appeared inside every OTHER vendor's portal,
        // because the portal roster is this same filter forced to the caller's
        // own id. The imports are fixed at the source now, so the filter can say
        // what it means.
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
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
