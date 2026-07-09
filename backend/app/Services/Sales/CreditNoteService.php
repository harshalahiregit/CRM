<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\CreditNote;
use App\Models\Sales\CreditNoteApplication;
use App\Models\Sales\CreditNoteRefund;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesLineItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreditNoteService
{
    public function list(int $tenantId, array $filters): mixed
    {
        $query = CreditNote::forTenant($tenantId)->with('lineItems');

        if (!empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        return $query->latest()->get();
    }

    public function create(array $data, int $tenantId, int $userId): CreditNote
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $cn = CreditNote::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
                'status'     => 'Open',
            ]);

            if (!empty($data['line_items'])) {
                $this->syncLineItems($cn, $data['line_items']);
            }
            $cn->recalcRemaining(); // sets total from line items

            Log::channel('sales')->info('Credit note created', [
                'credit_note_id' => $cn->id,
                'tenant_id'      => $tenantId,
            ]);

            return $cn;
        });
    }

    public function show(CreditNote $creditNote, int $tenantId): CreditNote
    {
        $this->assertOwnedByTenant($creditNote, $tenantId);

        return $creditNote->load(['lineItems', 'applications', 'refunds']);
    }

    public function void(CreditNote $creditNote, int $tenantId): CreditNote
    {
        $this->assertOwnedByTenant($creditNote, $tenantId);

        $creditNote->update(['status' => 'Void']);

        Log::channel('sales')->info('Credit note voided', [
            'credit_note_id' => $creditNote->id,
            'tenant_id'      => $tenantId,
        ]);

        return $creditNote;
    }

    /**
     * Apply credit note balance to an invoice.
     */
    public function applyToInvoice(CreditNote $creditNote, int $invoiceId, int $tenantId, int $userId): array
    {
        $this->assertOwnedByTenant($creditNote, $tenantId);

        $invoice = SalesInvoice::where('tenant_id', $tenantId)->findOrFail($invoiceId);

        if ($creditNote->remaining <= 0) {
            Log::channel('sales')->warning('Credit note apply rejected: no remaining balance', [
                'credit_note_id' => $creditNote->id,
                'tenant_id'      => $tenantId,
            ]);
            throw new BusinessException('Credit note has no remaining balance', 422);
        }
        if ($invoice->balance <= 0) {
            Log::channel('sales')->warning('Credit note apply rejected: invoice already fully paid', [
                'credit_note_id' => $creditNote->id,
                'invoice_id'     => $invoice->id,
                'tenant_id'      => $tenantId,
            ]);
            throw new BusinessException('Invoice is already fully paid', 422);
        }

        return DB::transaction(function () use ($creditNote, $invoice, $userId, $tenantId) {
            $applyAmount = min($creditNote->remaining, $invoice->balance);

            CreditNoteApplication::create([
                'credit_note_id' => $creditNote->id,
                'invoice_id'     => $invoice->id,
                'amount'         => $applyAmount,
                'date'           => now()->toDateString(),
                'created_by'     => $userId,
            ]);

            $creditNote->recalcRemaining();
            $invoice->recalcBalance();

            Log::channel('sales')->info('Credit note applied to invoice', [
                'credit_note_id' => $creditNote->id,
                'invoice_id'     => $invoice->id,
                'tenant_id'      => $tenantId,
                'amount'         => $applyAmount,
            ]);

            return [
                'applied'         => $applyAmount,
                'cn_remaining'    => $creditNote->remaining,
                'invoice_balance' => $invoice->balance,
                'invoice_status'  => $invoice->status,
            ];
        });
    }

    public function refund(CreditNote $creditNote, array $data, int $tenantId, int $userId): array
    {
        $this->assertOwnedByTenant($creditNote, $tenantId);

        $amount = (float) $data['amount'];

        // Belt-and-suspenders check in addition to the FormRequest's dynamic max:remaining rule.
        if ($amount > $creditNote->remaining) {
            Log::channel('sales')->warning('Credit note refund rejected: amount exceeds remaining balance', [
                'credit_note_id' => $creditNote->id,
                'tenant_id'      => $tenantId,
                'amount'         => $amount,
                'remaining'      => $creditNote->remaining,
            ]);
            throw new BusinessException('Refund amount exceeds the credit note\'s remaining balance.', 422);
        }

        return DB::transaction(function () use ($creditNote, $data, $amount, $userId, $tenantId) {
            CreditNoteRefund::create([
                'credit_note_id' => $creditNote->id,
                'amount'         => $amount,
                'mode'           => $data['mode'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'note'           => $data['note'] ?? null,
                'date'           => now()->toDateString(),
                'created_by'     => $userId,
            ]);

            $creditNote->recalcRemaining();

            Log::channel('sales')->info('Credit note refunded', [
                'credit_note_id' => $creditNote->id,
                'tenant_id'      => $tenantId,
                'amount'         => $amount,
            ]);

            return [
                'remaining' => $creditNote->remaining,
                'status'    => $creditNote->status,
            ];
        });
    }

    /* ── Helpers ─────────────────────────────────── */

    private function assertOwnedByTenant(CreditNote $cn, int $tenantId): void
    {
        if ($cn->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }

    private function syncLineItems(CreditNote $cn, array $items): void
    {
        SalesLineItem::where('lineable_type', CreditNote::class)
                     ->where('lineable_id', $cn->id)->delete();

        $subtotal = $taxTotal = $total = 0;
        foreach ($items as $idx => $item) {
            $lineTotal = SalesLineItem::computeTotal($item);
            $total += $lineTotal;
            SalesLineItem::create([
                'lineable_type' => CreditNote::class,
                'lineable_id'   => $cn->id,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $item['tax'] ?? 0,
                'discount'      => $item['discount'] ?? 0,
                'total'         => $lineTotal,
                'sort_order'    => $idx,
            ]);
        }
        $cn->total     = round($total, 2);
        $cn->remaining = round($total, 2);
        $cn->saveQuietly();
    }
}
