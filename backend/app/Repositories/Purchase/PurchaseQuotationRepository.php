<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseQuotation;
use App\Repositories\BaseRepository;

class PurchaseQuotationRepository extends BaseRepository
{
    protected string $modelClass = PurchaseQuotation::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseQuotation::forTenant($tenantId)
            ->with(['vendor:id,purchase_vendor_code,company_name', 'rfq:id,rfq_number,title'])
            ->withCount('items');

        if (! empty($filters['purchase_rfq_id'])) {
            $query->where('purchase_rfq_id', $filters['purchase_rfq_id']);
        }
        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['purchase_vendor_id'])) {
            $query->where('purchase_vendor_id', $filters['purchase_vendor_id']);
        }

        return $query->latest()->get();
    }
}
