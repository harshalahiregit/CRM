<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseInvoice;
use App\Repositories\BaseRepository;

class PurchaseInvoiceRepository extends BaseRepository
{
    protected string $modelClass = PurchaseInvoice::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseInvoice::forTenant($tenantId)
            ->with(['vendor:id,vendor_code,company_name', 'creator:id,name', 'purchaseOrder:id,po_number']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }
        if (! empty($filters['overdue'])) {
            // Outstanding balance, past due, still open.
            $query->whereDate('due_date', '<', now())
                  ->where('balance', '>', 0)
                  ->whereNotIn('status', ['Paid', 'Cancelled']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', '%'.$search.'%')
                  ->orWhere('vendor_invoice_ref', 'like', '%'.$search.'%')
                  ->orWhere('title', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }
}
