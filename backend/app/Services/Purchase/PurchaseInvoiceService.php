<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Repositories\Purchase\PurchaseInvoiceRepository;
use App\Support\Purchase\PurchaseInvoiceStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseInvoiceService
{
    public function __construct(
        private PurchaseInvoiceRepository $purchaseInvoiceRepository,
        private ThreeWayMatchService $threeWayMatch,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->purchaseInvoiceRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, User $actor): PurchaseInvoice
    {
        $items = $data['items'] ?? [];
        unset($data['items']);

        $tenantId = $actor->tenant_id;
        $this->assertVendorEngageable($data['vendor_id'] ?? null, $tenantId);

        $invoice = DB::transaction(function () use ($data, $items, $tenantId, $actor) {
            $invoice = PurchaseInvoice::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $actor->id,
                'status'     => Status::DRAFT,
            ]);
            $this->syncItems($invoice, $items);
            $invoice->recalcTotals();

            return $invoice;
        });

        $invoice->recordAudit('Purchase Invoice Created', $actor, null, ['invoice_number' => $invoice->invoice_number]);

        Log::channel('purchase')->info('Purchase invoice created', [
            'purchase_invoice_id' => $invoice->id, 'tenant_id' => $tenantId,
        ]);

        return $invoice->fresh(['items']);
    }

    /** Raise an invoice from a PO — copies line items and links the order. */
    public function createFromOrder(PurchaseOrder $po, array $data, User $actor): PurchaseInvoice
    {
        if (in_array($po->status, ['Draft', 'Cancelled'], true)) {
            throw new BusinessException('Only an issued or received purchase order can be invoiced.');
        }

        $invoice = DB::transaction(function () use ($po, $data, $actor) {
            $invoice = PurchaseInvoice::create([
                'tenant_id'         => $po->tenant_id,
                'purchase_order_id' => $po->id,
                'vendor_id'         => $po->vendor_id,
                'created_by'        => $actor->id,
                'title'             => $po->title,
                'vendor_invoice_ref' => $data['vendor_invoice_ref'] ?? null,
                'invoice_date'      => $data['invoice_date'] ?? now()->toDateString(),
                'due_date'          => $data['due_date'] ?? null,
                'currency'          => $po->currency,
                'status'            => Status::DRAFT,
            ]);

            foreach ($po->items as $i => $item) {
                $invoice->items()->create([
                    'tenant_id'   => $po->tenant_id,
                    // Link the invoice line to its PO line so 3-way match can
                    // reconcile billed vs ordered vs received line-by-line.
                    'purchase_order_item_id' => $item->id,
                    'description' => $item->description,
                    // Bill for what was received, not what was ordered — the
                    // whole point of matching. Falls back to ordered qty when no
                    // receipt has landed yet (received_qty is 0).
                    'qty'         => $item->received_qty > 0 ? $item->received_qty : $item->qty,
                    'unit'        => $item->unit,
                    'rate'        => $item->rate,
                    'tax'         => $item->tax,
                    'sort_order'  => $item->sort_order ?? $i,
                ]);
            }
            $invoice->recalcTotals();

            return $invoice;
        });

        $invoice->recordAudit('Purchase Invoice Created', $actor, null, [
            'invoice_number' => $invoice->invoice_number, 'from_order' => $po->po_number,
        ]);

        Log::channel('purchase')->info('Purchase invoice created from order', [
            'purchase_invoice_id' => $invoice->id, 'purchase_order_id' => $po->id, 'tenant_id' => $po->tenant_id,
        ]);

        return $invoice->fresh(['items']);
    }

    public function update(PurchaseInvoice $invoice, array $data, User $actor): PurchaseInvoice
    {
        if (! $invoice->isEditable()) {
            throw new BusinessException('Only a Draft invoice can be edited.');
        }

        $items = $data['items'] ?? null;
        unset($data['items']);

        $this->assertVendorEngageable($data['vendor_id'] ?? null, $invoice->tenant_id);

        DB::transaction(function () use ($invoice, $data, $items) {
            $invoice->update($data);
            if ($items !== null) {
                $invoice->items()->delete();
                $this->syncItems($invoice, $items);
            }
            $invoice->load('items');
            $invoice->recalcTotals();
        });

        $invoice->recordAudit('Purchase Invoice Updated', $actor);

        Log::channel('purchase')->info('Purchase invoice updated', [
            'purchase_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id,
        ]);

        return $invoice->fresh(['items']);
    }

    /** Draft → Awaiting_Payment. Approves the payable and locks line items. */
    public function approve(PurchaseInvoice $invoice, User $actor): PurchaseInvoice
    {
        if ($invoice->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft invoice can be approved.');
        }
        if (! $invoice->vendor_id) {
            throw new BusinessException('Select a vendor before approving the invoice.');
        }
        $this->assertVendorEngageable($invoice->vendor_id, $invoice->tenant_id);
        if ($invoice->items()->count() === 0) {
            throw new BusinessException('Add at least one line item before approving.');
        }

        // 3-way match: block over-billing / price-variance, allow (but record)
        // under-billing. The verdict is stamped onto the invoice so the ledger
        // can show WHY a payable was approved despite a warning.
        [$passes, $match] = $this->threeWayMatch->passesForApproval($invoice);
        if (! $passes) {
            throw new BusinessException($match['summary']);
        }

        $invoice->update([
            'status'      => Status::AWAITING_PAYMENT,
            'approved_at' => now(),
            'approved_by' => $actor->id,
            'match_verdict' => $match['verdict'],
        ]);
        $invoice->recordAudit('Purchase Invoice Approved', $actor, $match['applicable'] ? $match['summary'] : null, [
            'from' => Status::DRAFT, 'to' => Status::AWAITING_PAYMENT,
            'match_verdict' => $match['verdict'],
        ]);

        Log::channel('purchase')->info('Purchase invoice approved', [
            'purchase_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $invoice;
    }

    /**
     * Record a payment against a payable invoice. Guards over-payment against the
     * current balance and rolls the invoice's amount_paid / balance / status.
     */
    public function recordPayment(PurchaseInvoice $invoice, array $data, User $actor): PurchaseInvoice
    {
        if (! $invoice->isPayable()) {
            throw new BusinessException("This invoice is {$invoice->status_label} and cannot accept payments.");
        }

        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw new BusinessException('Payment amount must be greater than zero.');
        }
        if ($amount > (float) $invoice->balance + 0.0001) {
            throw new BusinessException("Payment ({$amount}) exceeds the outstanding balance ({$invoice->balance}).");
        }

        DB::transaction(function () use ($invoice, $data, $amount, $actor) {
            $invoice->payments()->create([
                'tenant_id'    => $invoice->tenant_id,
                'created_by'   => $actor->id,
                'amount'       => $amount,
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'payment_mode' => $data['payment_mode'] ?? 'Bank_Transfer',
                'reference'    => $data['reference'] ?? null,
                'notes'        => $data['notes'] ?? null,
            ]);
            $invoice->recalcPayments();
        });

        $invoice->refresh();
        $invoice->recordAudit('Payment Recorded', $actor, null, [
            'amount' => $amount, 'balance' => $invoice->balance, 'status' => $invoice->status,
        ]);

        Log::channel('purchase')->info('Purchase invoice payment recorded', [
            'purchase_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'amount' => $amount,
        ]);

        return $invoice->fresh(['payments']);
    }

    /** Reverse a payment — removes it and recomputes the invoice. */
    public function deletePayment(PurchaseInvoice $invoice, PurchaseInvoicePayment $payment, User $actor): PurchaseInvoice
    {
        if ((int) $payment->purchase_invoice_id !== (int) $invoice->id) {
            throw new BusinessException('Payment does not belong to this invoice.', 404);
        }

        DB::transaction(function () use ($invoice, $payment) {
            $payment->delete();
            $invoice->refresh();
            $invoice->recalcPayments();
        });

        $invoice->refresh();
        $invoice->recordAudit('Payment Reversed', $actor, null, [
            'amount' => $payment->amount, 'balance' => $invoice->balance, 'status' => $invoice->status,
        ]);

        Log::channel('purchase')->info('Purchase invoice payment reversed', [
            'purchase_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id, 'payment_id' => $payment->id,
        ]);

        return $invoice->fresh(['payments']);
    }

    public function cancel(PurchaseInvoice $invoice, User $actor, ?string $remarks = null): PurchaseInvoice
    {
        if ($invoice->status === Status::PAID) {
            throw new BusinessException('A paid invoice cannot be cancelled.');
        }
        if ($invoice->amount_paid > 0) {
            throw new BusinessException('Reverse the recorded payments before cancelling this invoice.');
        }
        if ($invoice->amount_credited > 0) {
            throw new BusinessException('Reverse the debit-note credits applied to this invoice before cancelling it.');
        }

        $invoice->update(['status' => Status::CANCELLED, 'notes' => $remarks ?? $invoice->notes]);
        $invoice->recordAudit('Purchase Invoice Cancelled', $actor, $remarks, ['to' => Status::CANCELLED]);

        Log::channel('purchase')->info('Purchase invoice cancelled', [
            'purchase_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id,
        ]);

        return $invoice;
    }

    public function destroy(PurchaseInvoice $invoice): void
    {
        if ($invoice->status !== Status::DRAFT) {
            throw new BusinessException('Only a Draft invoice can be deleted.');
        }

        $invoice->delete();

        Log::channel('purchase')->info('Purchase invoice deleted', [
            'purchase_invoice_id' => $invoice->id, 'tenant_id' => $invoice->tenant_id,
        ]);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseInvoice::forTenant($tenantId);

        return [
            'total'        => $base()->count(),
            'draft'        => $base()->where('status', Status::DRAFT)->count(),
            'awaiting'     => $base()->where('status', Status::AWAITING_PAYMENT)->count(),
            'partial'      => $base()->where('status', Status::PARTIALLY_PAID)->count(),
            'paid'         => $base()->where('status', Status::PAID)->count(),
            'outstanding'  => $base()->whereIn('status', Status::PAYABLE)->sum('balance'),
            'overdue'      => $base()->whereDate('due_date', '<', now())->where('balance', '>', 0)
                                     ->whereNotIn('status', [Status::PAID, Status::CANCELLED])->count(),
        ];
    }

    private function syncItems(PurchaseInvoice $invoice, array $items): void
    {
        foreach ($items as $i => $item) {
            $invoice->items()->create([
                ...$item,
                'tenant_id'  => $invoice->tenant_id,
                'sort_order' => $item['sort_order'] ?? $i,
            ]);
        }
    }

    /** Mirrors PurchaseOrderService — guards cross-tenant + non-active vendors. */
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
