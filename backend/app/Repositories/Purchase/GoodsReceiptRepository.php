<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\GoodsReceipt;

/**
 * Cross-PO queries for the Goods Received register.
 *
 * Receipts are created and confirmed from inside a purchase order, so the
 * per-PO list lives on GoodsReceiptService::listForOrder(). This is the other
 * direction: every receipt for the tenant, which is what a stores clerk needs
 * when they know the GRN number or the delivery note but not the PO.
 */
class GoodsReceiptRepository
{
    public function filtered(int $tenantId, array $filters)
    {
        $query = GoodsReceipt::forTenant($tenantId)
            ->with([
                'vendor:id,purchase_vendor_code,company_name',
                'purchaseOrder:id,po_number',
                'receiver:id,name',
                'items',
            ])
            ->withCount('items');

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['purchase_vendor_id'])) {
            $query->where('purchase_vendor_id', $filters['purchase_vendor_id']);
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', $filters['purchase_order_id']);
        }

        // Inclusive on both ends: a clerk asking for "the 3rd" means that day.
        if (! empty($filters['from'])) {
            $query->whereDate('received_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('received_date', '<=', $filters['to']);
        }

        // Receipts with something rejected — the queue a QA lead works from.
        if (! empty($filters['has_rejections'])) {
            $query->whereHas('items', fn ($q) => $q->where('rejected_qty', '>', 0));
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('grn_number', 'like', '%'.$search.'%')
                  ->orWhere('delivery_note_ref', 'like', '%'.$search.'%')
                  ->orWhere('notes', 'like', '%'.$search.'%')
                  ->orWhereHas('purchaseOrder', fn ($p) => $p->where('po_number', 'like', '%'.$search.'%'))
                  ->orWhereHas('vendor', fn ($v) => $v
                      ->where('company_name', 'like', '%'.$search.'%')
                      ->orWhere('purchase_vendor_code', 'like', '%'.$search.'%'));
            });
        }

        // Newest receipt first, then newest row — received_date is a date, so
        // several receipts share a day and would otherwise order arbitrarily.
        return $query->orderByDesc('received_date')->orderByDesc('id')->get();
    }
}
