<?php

namespace App\Repositories\Sales;

use App\Models\Sales\SalesContract;
use App\Repositories\BaseRepository;

class SalesContractRepository extends BaseRepository
{
    protected string $modelClass = SalesContract::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = SalesContract::forTenant($tenantId)->with(['client:id,company', 'type:id,name', 'creator:id,name']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }
        if (!empty($filters['type_id'])) {
            $query->where('contract_type_id', $filters['type_id']);
        }
        if (!empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('subject', 'like', $s)->orWhere('reference_no', 'like', $s));
        }

        return $query->latest()->get();
    }

    public function expiringSoon(int $tenantId, int $days)
    {
        return SalesContract::forTenant($tenantId)
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->with(['client:id,company'])
            ->orderBy('end_date')
            ->get();
    }
}
