<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseOrder;
use App\Repositories\BaseRepository;

class PurchaseOrderRepository extends BaseRepository
{
    protected string $modelClass = PurchaseOrder::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseOrder::forTenant($tenantId)
            ->with(['items', 'vendor:id,purchase_vendor_code,company_name', 'creator:id,name']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }
        if (! empty($filters['purchase_vendor_id'])) {
            $query->where('purchase_vendor_id', $filters['purchase_vendor_id']);
        }
        if (! empty($filters['expected_by'])) {
            $query->whereDate('expected_delivery_date', '<=', $filters['expected_by']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                  ->orWhere('po_number', 'like', '%'.$search.'%')
                  ->orWhere('department', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }
}
