<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseDebitRefund;
use App\Models\Purchase\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Purchase\PurchaseDebitNoteRepository;
use App\Support\Purchase\PurchaseDebitNoteStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseDebitNoteService
{
    public function __construct(private PurchaseDebitNoteRepository $purchaseDebitNoteRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->purchaseDebitNoteRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, User $actor): PurchaseDebitNote
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $tenantId = $actor->tenant_id;
        $this->assertVendorEngageable($data['vendor_id'] ?? null, $tenantId);
        $po = $this->resolveOrder($data['purchase_order_id'] ?? null, $tenantId);

        $dn = DB::transaction(function () use ($data, $items, $tenantId, $actor, $po) {
            $dn = PurchaseDebitNote::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $actor->id,
                // Standalone debit notes never touch inventory.
                'adjust_inventory' => $po ? ($data['adjust_inventory'] ?? true) : false,
                'status'     => Status::DRAFT,
            ]);
            $this->syncItems($dn, $items);
            $dn->recalcTotals();

            return $dn;
        });

        $dn->recordAudit('Debit Note Created', $actor, null, ['debit_number' => $dn->debit_number]);

        Log::channel('purchase')->info('Debit note created', [
            'purchase_debit_note_id' => $dn->id, 'tenant_id' => $tenantId,
        ]);

        return $dn->fresh(['items']);
    }

    public function update(PurchaseDebitNote $dn, array $data, User $actor): PurchaseDebitNote
    {
        if (! $dn->isEditable()) {
            throw new BusinessException('Only a Draft debit note can be edited.');
        }

        $items = $data['items'] ?? null;
        unset($data['items']);

        $this->assertVendorEngageable($data['vendor_id'] ?? null, $dn->tenant_id);
        $po = $this->resolveOrder($data['purchase_order_id'] ?? $dn->purchase_order_id, $dn->tenant_id);

        DB::transaction(function () use ($dn, $data, $items, $po) {
            if (! $po) {
                $data['adjust_inventory'] = false;
            }
            $dn->update($data);
            if ($items !== null) {
                $dn->items()->delete();
                $this->syncItems($dn, $items);
            }
            $dn->load('items');
            $dn->recalcTotals();
        });

        $dn->recordAudit('Debit Note Updated', $actor);

        Log::channel('purchase')->info('Debit note updated', [
            'purchase_debit_note_id' => $dn->id, 'tenant_id' => $dn->tenant_id,
        ]);

        return $dn->fresh(['items']);
    }

    /**
     * Draft → Open. This is the inventory-adjusting step: for each line linked to
     * a PO item, the returned quantity is deducted from that item's received_qty
     * (the reverse of a Goods Receipt), and the PO status is re-synced. Guarded so
     * you can never return more than is currently on hand.
     */
    public function issue(PurchaseDebitNote $dn, User $actor): PurchaseDebitNote
    {
        if ($dn->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft debit note can be issued.');
        }
        if ($dn->items()->count() === 0) {
            throw new BusinessException('Add at least one line item before issuing.');
        }

        DB::transaction(function () use ($dn, $actor) {
            if ($dn->adjust_inventory && $dn->purchase_order_id) {
                $po = PurchaseOrder::forTenant($dn->tenant_id)->find($dn->purchase_order_id);
                if (! $po) {
                    throw new BusinessException('The linked purchase order no longer exists.', 404);
                }

                $poItems = $po->items()->get()->keyBy('id');
                foreach ($dn->items as $line) {
                    if (! $line->purchase_order_item_id) {
                        continue; // a manual line with no inventory link
                    }
                    $poItem = $poItems->get($line->purchase_order_item_id);
                    if (! $poItem) {
                        throw new BusinessException('A returned line no longer matches the purchase order.');
                    }
                    if ((float) $line->qty > (float) $poItem->received_qty + 0.0001) {
                        throw new BusinessException(
                            "Return quantity for \"{$poItem->description}\" ({$line->qty}) exceeds the received quantity on hand ({$poItem->received_qty})."
                        );
                    }
                    $poItem->received_qty = round((float) $poItem->received_qty - (float) $line->qty, 2);
                    $poItem->save();
                }

                // A return can drop the PO from Received back to Partially_Received.
                $po->syncReceiptStatus();
            }

            $dn->update([
                'status'    => Status::OPEN,
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'balance'   => $dn->total,
            ]);
        });

        $dn->recordAudit('Debit Note Issued', $actor, null, [
            'from' => Status::DRAFT, 'to' => Status::OPEN, 'inventory_adjusted' => (bool) $dn->adjust_inventory,
        ]);

        Log::channel('purchase')->info('Debit note issued', [
            'purchase_debit_note_id' => $dn->id, 'tenant_id' => $dn->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $dn->fresh();
    }

    /**
     * Record a vendor refund against an Open debit note. Guards over-refund
     * against the note's outstanding balance and settles it when the balance
     * reaches zero.
     */
    public function recordRefund(PurchaseDebitNote $dn, array $data, User $actor): PurchaseDebitNote
    {
        if (! $dn->isRefundable()) {
            throw new BusinessException("This debit note is {$dn->status_label} and cannot accept refunds.");
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new BusinessException('Refund amount must be greater than zero.');
        }
        if ($amount > (float) $dn->balance + 0.0001) {
            throw new BusinessException("Refund ({$amount}) exceeds the outstanding claim balance ({$dn->balance}).");
        }

        DB::transaction(function () use ($dn, $data, $amount, $actor) {
            $dn->refunds()->create([
                'tenant_id'   => $dn->tenant_id,
                'created_by'  => $actor->id,
                'amount'      => $amount,
                'refund_date' => $data['refund_date'] ?? now()->toDateString(),
                'refund_mode' => $data['refund_mode'] ?? 'Bank_Transfer',
                'reference'   => $data['reference'] ?? null,
                'notes'       => $data['notes'] ?? null,
            ]);
            $dn->recalcRefunds();
        });

        $dn->refresh();
        $dn->recordAudit('Refund Recorded', $actor, null, [
            'amount' => $amount, 'balance' => $dn->balance, 'status' => $dn->status,
        ]);

        Log::channel('purchase')->info('Debit note refund recorded', [
            'purchase_debit_note_id' => $dn->id, 'tenant_id' => $dn->tenant_id, 'amount' => $amount,
        ]);

        return $dn->fresh(['refunds']);
    }

    public function deleteRefund(PurchaseDebitNote $dn, PurchaseDebitRefund $refund, User $actor): PurchaseDebitNote
    {
        if ((int) $refund->purchase_debit_note_id !== (int) $dn->id) {
            throw new BusinessException('Refund does not belong to this debit note.', 404);
        }

        DB::transaction(function () use ($dn, $refund) {
            $refund->delete();
            $dn->refresh();
            $dn->recalcRefunds();
        });

        $dn->refresh();
        $dn->recordAudit('Refund Reversed', $actor, null, [
            'amount' => $refund->amount, 'balance' => $dn->balance, 'status' => $dn->status,
        ]);

        Log::channel('purchase')->info('Debit note refund reversed', [
            'purchase_debit_note_id' => $dn->id, 'tenant_id' => $dn->tenant_id, 'refund_id' => $refund->id,
        ]);

        return $dn->fresh(['refunds']);
    }

    /**
     * Cancel an Open debit note that has no refunds — restoring any inventory the
     * issue step deducted (goods effectively un-returned). Draft notes are deleted
     * instead (see destroy).
     */
    public function cancel(PurchaseDebitNote $dn, User $actor, ?string $remarks = null): PurchaseDebitNote
    {
        if ($dn->status === Status::SETTLED) {
            throw new BusinessException('A settled debit note cannot be cancelled.');
        }
        if ($dn->status === Status::CANCELLED) {
            throw new BusinessException('This debit note is already cancelled.');
        }
        if ($dn->amount_refunded > 0) {
            throw new BusinessException('Reverse the recorded refunds before cancelling this debit note.');
        }
        if ($dn->amount_applied > 0) {
            throw new BusinessException('Reverse the credits applied to invoices before cancelling this debit note.');
        }

        DB::transaction(function () use ($dn) {
            // Give the returned goods back to the PO if issuing had deducted them.
            if ($dn->status === Status::OPEN && $dn->adjust_inventory && $dn->purchase_order_id) {
                $po = PurchaseOrder::forTenant($dn->tenant_id)->find($dn->purchase_order_id);
                if ($po) {
                    $poItems = $po->items()->get()->keyBy('id');
                    foreach ($dn->items as $line) {
                        if ($line->purchase_order_item_id && ($poItem = $poItems->get($line->purchase_order_item_id))) {
                            $poItem->received_qty = round((float) $poItem->received_qty + (float) $line->qty, 2);
                            $poItem->save();
                        }
                    }
                    $po->syncReceiptStatus();
                }
            }
            $dn->update(['status' => Status::CANCELLED, 'notes' => $remarks ?? $dn->notes]);
        });

        $dn->recordAudit('Debit Note Cancelled', $actor, $remarks, ['to' => Status::CANCELLED]);

        Log::channel('purchase')->info('Debit note cancelled', [
            'purchase_debit_note_id' => $dn->id, 'tenant_id' => $dn->tenant_id,
        ]);

        return $dn->fresh();
    }

    public function destroy(PurchaseDebitNote $dn): void
    {
        if ($dn->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft debit note can be deleted.');
        }

        $dn->delete();

        Log::channel('purchase')->info('Debit note deleted', [
            'purchase_debit_note_id' => $dn->id, 'tenant_id' => $dn->tenant_id,
        ]);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseDebitNote::forTenant($tenantId);

        return [
            'total'       => $base()->count(),
            'draft'       => $base()->where('status', Status::DRAFT)->count(),
            'open'        => $base()->where('status', Status::OPEN)->count(),
            'settled'     => $base()->where('status', Status::SETTLED)->count(),
            // Money the vendor still owes us across open debit notes.
            'open_claims' => (float) $base()->where('status', Status::OPEN)->sum('balance'),
            'refunded'    => (float) $base()->sum('amount_refunded'),
        ];
    }

    private function syncItems(PurchaseDebitNote $dn, array $items): void
    {
        foreach ($items as $i => $item) {
            $dn->items()->create([
                ...$item,
                'tenant_id'  => $dn->tenant_id,
                'sort_order' => $item['sort_order'] ?? $i,
            ]);
        }
    }

    /** A PO reference must belong to the caller's tenant. */
    private function resolveOrder(?int $poId, int $tenantId): ?PurchaseOrder
    {
        if (! $poId) {
            return null;
        }

        $po = PurchaseOrder::forTenant($tenantId)->find($poId);
        if (! $po) {
            throw new BusinessException('Purchase order not found.', 404);
        }

        return $po;
    }

    /** Mirrors the other purchase services — cross-tenant + non-active vendor guard. */
    private function assertVendorEngageable(?int $vendorId, int $tenantId): void
    {
        if (! $vendorId) {
            return;
        }

        $vendor = Vendor::forTenant($tenantId)->find($vendorId);
        if (! $vendor) {
            throw new BusinessException('Vendor not found.', 404);
        }
        if (! $vendor->isEngageable()) {
            throw new BusinessException("Vendor {$vendor->vendor_code} is {$vendor->status_label} and cannot be transacted with.");
        }
    }
}
