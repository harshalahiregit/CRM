<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseRfq;
use App\Repositories\BaseRepository;

class PurchaseRfqRepository extends BaseRepository
{
    protected string $modelClass = PurchaseRfq::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseRfq::forTenant($tenantId)
            ->withCount(['items', 'rfqVendors', 'quotations'])
            ->with(['creator:id,name']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")
                ->orWhere('rfq_number', 'like', "%{$s}%")
                ->orWhere('department', 'like', "%{$s}%"));
        }

        return $query->latest()->get();
    }
}
