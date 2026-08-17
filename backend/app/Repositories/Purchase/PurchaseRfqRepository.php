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
        // RFQs one vendor was invited to. The link is the recipient list, not a
        // column on the RFQ — an RFQ goes to several vendors at once — so this
        // filters through that relation. Additive: omit it and the list is
        // tenant-wide exactly as before.
        if (! empty($filters['purchase_vendor_id'])) {
            $query->whereHas(
                'rfqVendors',
                fn ($q) => $q->where('purchase_vendor_id', (int) $filters['purchase_vendor_id'])
            );
        }

        return $query->latest()->get();
    }
}
