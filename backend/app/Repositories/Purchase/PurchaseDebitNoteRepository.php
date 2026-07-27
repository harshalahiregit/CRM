<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseDebitNote;
use App\Repositories\BaseRepository;

class PurchaseDebitNoteRepository extends BaseRepository
{
    protected string $modelClass = PurchaseDebitNote::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseDebitNote::forTenant($tenantId)
            ->with(['items:id,purchase_debit_note_id', 'vendor:id,purchase_vendor_code,company_name', 'creator:id,name', 'purchaseOrder:id,po_number']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['purchase_vendor_id'])) {
            $query->where('purchase_vendor_id', $filters['purchase_vendor_id']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('debit_number', 'like', '%'.$search.'%')
                  ->orWhere('reason', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }
}
