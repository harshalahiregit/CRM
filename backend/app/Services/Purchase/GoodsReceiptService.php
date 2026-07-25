<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\GoodsReceipt;
use App\Models\Purchase\PurchaseOrder;
use App\Models\User;
use App\Support\Purchase\GoodsReceiptStatus as Status;
use App\Support\Purchase\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GoodsReceiptService
{
    /** GRNs for one purchase order (the PO detail view lists its receipts). */
    public function listForOrder(PurchaseOrder $po): Collection
    {
        return $po->goodsReceipts()->with(['items', 'receiver:id,name'])->get();
    }

    /**
     * Draft GRN against a receivable PO. Lines that aren't supplied default to
     * zero; description and ordered_qty are copied from the PO server-side.
     */
    public function create(PurchaseOrder $po, array $data, User $actor): GoodsReceipt
    {
        if (! $po->isReceivable()) {
            throw new BusinessException("This purchase order is {$po->status_label} and cannot receive goods.");
        }

        $lines = $this->buildLines($po, $data['items'] ?? []);
        if ($lines->isEmpty()) {
            throw new BusinessException('Record a received quantity for at least one line.');
        }

        $grn = DB::transaction(function () use ($po, $data, $actor, $lines) {
            $grn = GoodsReceipt::create([
                'tenant_id'         => $po->tenant_id,
                'purchase_order_id' => $po->id,
                'vendor_id'         => $po->vendor_id,
                'received_by'       => $actor->id,
                'received_date'     => $data['received_date'] ?? now()->toDateString(),
                'delivery_note_ref' => $data['delivery_note_ref'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'status'            => Status::DRAFT,
            ]);

            foreach ($lines as $line) {
                $grn->items()->create([...$line, 'tenant_id' => $po->tenant_id]);
            }

            return $grn;
        });

        $grn->recordAudit('Goods Receipt Created', $actor, null, ['grn_number' => $grn->grn_number]);

        Log::channel('purchase')->info('Goods receipt created', [
            'goods_receipt_id' => $grn->id, 'purchase_order_id' => $po->id, 'tenant_id' => $po->tenant_id,
        ]);

        return $grn->fresh(['items']);
    }

    /**
     * Confirm a Draft GRN: validate accepted quantities against each PO line's
     * still-pending quantity, roll them up to the PO, and advance PO status.
     * The whole thing is one transaction so a mid-way validation failure leaves
     * neither the GRN nor the PO half-updated.
     */
    public function confirm(GoodsReceipt $grn, User $actor): GoodsReceipt
    {
        if ($grn->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft goods receipt can be confirmed.');
        }

        $po = $grn->purchaseOrder;
        if (! $po || ! $po->isReceivable()) {
            throw new BusinessException('The purchase order is no longer receivable.');
        }

        DB::transaction(function () use ($grn, $po) {
            $poItems = $po->items()->get()->keyBy('id');

            foreach ($grn->items as $line) {
                $poItem = $poItems->get($line->purchase_order_item_id);
                if (! $poItem) {
                    throw new BusinessException('A received line no longer matches the purchase order.');
                }

                $pending = (float) $poItem->pending_qty;
                if ((float) $line->accepted_qty > $pending + 0.0001) {
                    throw new BusinessException(
                        "Accepted quantity for \"{$poItem->description}\" ({$line->accepted_qty}) exceeds the pending quantity ({$pending})."
                    );
                }

                $poItem->received_qty = round((float) $poItem->received_qty + (float) $line->accepted_qty, 2);
                $poItem->save();
            }

            $grn->update(['status' => Status::CONFIRMED, 'confirmed_at' => now()]);
            $po->syncReceiptStatus();
        });

        $grn->recordAudit('Goods Receipt Confirmed', $actor, null, ['to' => Status::CONFIRMED]);
        $po->recordAudit('Goods Received', $actor, null, [
            'grn_number' => $grn->grn_number, 'po_status' => $po->fresh()->status,
        ]);

        Log::channel('purchase')->info('Goods receipt confirmed', [
            'goods_receipt_id' => $grn->id, 'purchase_order_id' => $po->id, 'tenant_id' => $grn->tenant_id,
        ]);

        return $grn->fresh(['items']);
    }

    public function cancel(GoodsReceipt $grn, User $actor, ?string $remarks = null): GoodsReceipt
    {
        if ($grn->status === Status::CONFIRMED) {
            // Reversing confirmed receipts would require unwinding PO quantities —
            // out of scope for v1. Cancel is for draft/mistaken GRNs only.
            throw new BusinessException('A confirmed goods receipt cannot be cancelled.');
        }

        $grn->update(['status' => Status::CANCELLED, 'notes' => $remarks ?? $grn->notes]);
        $grn->recordAudit('Goods Receipt Cancelled', $actor, $remarks, ['to' => Status::CANCELLED]);

        Log::channel('purchase')->info('Goods receipt cancelled', [
            'goods_receipt_id' => $grn->id, 'tenant_id' => $grn->tenant_id,
        ]);

        return $grn;
    }

    public function destroy(GoodsReceipt $grn): void
    {
        if ($grn->status === Status::CONFIRMED) {
            throw new BusinessException('A confirmed goods receipt cannot be deleted.');
        }

        $grn->delete();

        Log::channel('purchase')->info('Goods receipt deleted', [
            'goods_receipt_id' => $grn->id, 'tenant_id' => $grn->tenant_id,
        ]);
    }

    /**
     * Normalise incoming lines against the PO: only PO lines are receivable,
     * description + ordered_qty come from the PO (not the client), and lines
     * with nothing received/rejected are dropped.
     */
    private function buildLines(PurchaseOrder $po, array $items): \Illuminate\Support\Collection
    {
        $poItems = $po->items()->get()->keyBy('id');

        return collect($items)->map(function ($line) use ($poItems) {
            $poItem = $poItems->get($line['purchase_order_item_id'] ?? null);
            if (! $poItem) {
                return null;
            }

            $accepted = round((float) ($line['accepted_qty'] ?? 0), 2);
            $rejected = round((float) ($line['rejected_qty'] ?? 0), 2);
            if ($accepted <= 0 && $rejected <= 0) {
                return null;
            }

            return [
                'purchase_order_item_id' => $poItem->id,
                'description'            => $poItem->description,
                'ordered_qty'            => $poItem->qty,
                'accepted_qty'           => $accepted,
                'rejected_qty'           => $rejected,
                'remarks'                => $line['remarks'] ?? null,
            ];
        })->filter()->values();
    }
}
