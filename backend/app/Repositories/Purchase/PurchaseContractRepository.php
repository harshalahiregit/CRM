<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseContract;
use App\Repositories\BaseRepository;

class PurchaseContractRepository extends BaseRepository
{
    protected string $modelClass = PurchaseContract::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseContract::forTenant($tenantId)
            ->with(['vendor:id,vendor_code,company_name', 'creator:id,name'])
            ->withCount('items');

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['type']) && $filters['type'] !== 'All') {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhere('contract_number', 'like', "%{$s}%"));
        }

        return $query->latest()->get();
    }
}
