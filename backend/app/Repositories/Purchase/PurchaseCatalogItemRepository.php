<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseCatalogItem;
use App\Repositories\BaseRepository;

class PurchaseCatalogItemRepository extends BaseRepository
{
    protected string $modelClass = PurchaseCatalogItem::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseCatalogItem::forTenant($tenantId)
            ->with(['preferredVendor:id,vendor_code,company_name']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['category']) && $filters['category'] !== 'All') {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('sku', 'like', "%{$s}%")
                ->orWhere('category', 'like', "%{$s}%")
                ->orWhere('hsn_code', 'like', "%{$s}%"));
        }

        return $query->orderBy('name')->get();
    }
}
