<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\SalesInvoice;
use App\Models\SalesLineItem;
use App\Models\SalesPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    public function list(int $tenantId, array $filters)
    {
        $query = SalesInvoice::forTenant($tenantId)->with(['lineItems', 'payments']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        // Auto-mark overdue before returning
        $invoices = $query->latest()->get();
        $invoices->each(fn ($inv) => $inv->updateOverdueStatus());

        return $invoices;
    }

    public function create(array $data, int $tenantId, int $userId): SalesInvoice
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $lineItems = $data['line_items'] ?? [];
            unset($data['line_items']);

            $invoice = SalesInvoice::create([
                ...$data,
                'tenant_id'  => $tenantId,
                'created_by' => $userId,
                'status'     => 'Draft',
            ]);

            $this->syncLineItems($invoice, $lineItems);
            $invoice->recalcTotals();
            $invoice->update(['balance' => $invoice->total]);

            Log::channel('sales')->info('Invoice created', [
                'invoice_id' => $invoice->id,
                'tenant_id'  => $tenantId,
            ]);

            return $invoice->load('lineItems');
        });
    }

    public function show(SalesInvoice $invoice, int $tenantId): SalesInvoice
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        return $invoice->load(['lineItems', 'payments', 'creditApplications']);
    }

    public function update(SalesInvoice $invoice, array $data, int $tenantId): SalesInvoice
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        return DB::transaction(function () use ($invoice, $data, $tenantId) {
            $hasLineItems = array_key_exists('line_items', $data);
            $lineItems = $data['line_items'] ?? [];
            unset($data['line_items']);

            $invoice->update($data);

            if ($hasLineItems) {
                $this->syncLineItems($invoice, $lineItems);
                $invoice->recalcTotals();
            }

            Log::channel('sales')->info('Invoice updated', [
                'invoice_id' => $invoice->id,
                'tenant_id'  => $tenantId,
            ]);

            return $invoice->fresh()->load(['lineItems', 'payments']);
        });
    }

    public function delete(SalesInvoice $invoice, int $tenantId): void
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        $invoice->delete();

        Log::channel('sales')->info('Invoice deleted', [
            'invoice_id' => $invoice->id,
            'tenant_id'  => $tenantId,
        ]);
    }

    public function send(SalesInvoice $invoice, int $tenantId): SalesInvoice
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        $invoice->update(['status' => 'Unpaid', 'sent_at' => now()]);

        Log::channel('sales')->info('Invoice sent', [
            'invoice_id' => $invoice->id,
            'tenant_id'  => $tenantId,
        ]);

        return $invoice->fresh();
    }

    public function recordPayment(SalesInvoice $invoice, array $data, int $tenantId, int $userId): array
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        // Safety net: guard against overpayment even if the FormRequest's
        // dynamic max:balance rule was somehow bypassed or evaluated against
        // stale data. This mirrors the original inline validation exactly
        // on the happy path, but never allows an overpayment through.
        if ((float) $data['amount'] > (float) $invoice->balance) {
            Log::channel('sales')->warning('Payment rejected: amount exceeds balance', [
                'invoice_id' => $invoice->id,
                'tenant_id'  => $tenantId,
                'amount'     => $data['amount'],
                'balance'    => $invoice->balance,
            ]);
            throw new BusinessException('Payment amount exceeds outstanding balance.', 422);
        }

        return DB::transaction(function () use ($invoice, $data, $tenantId, $userId) {
            $payment = SalesPayment::create([
                'tenant_id'      => $tenantId,
                'invoice_id'     => $invoice->id,
                'date'           => $data['date'],
                'amount'         => $data['amount'],
                'mode'           => $data['mode'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'note'           => $data['note'] ?? null,
                'created_by'     => $userId,
            ]);

            $invoice->recalcBalance();

            Log::channel('sales')->info('Payment recorded', [
                'invoice_id' => $invoice->id,
                'tenant_id'  => $tenantId,
                'amount'     => $payment->amount,
                'balance'    => $invoice->balance,
                'status'     => $invoice->status,
            ]);

            return [
                'payment'        => $payment,
                'invoice_status' => $invoice->status,
                'balance'        => $invoice->balance,
                'paid'           => $invoice->paid,
            ];
        });
    }

    /* ── Helpers ─────────────────────────────────── */
    private function assertBelongsToTenant(SalesInvoice $invoice, int $tenantId): void
    {
        if ($invoice->tenant_id !== $tenantId) {
            throw new UnauthorizedTenantException();
        }
    }

    private function syncLineItems(SalesInvoice $invoice, array $items): void
    {
        SalesLineItem::where('lineable_type', SalesInvoice::class)
                     ->where('lineable_id', $invoice->id)
                     ->delete();

        foreach ($items as $idx => $item) {
            SalesLineItem::create([
                'lineable_type' => SalesInvoice::class,
                'lineable_id'   => $invoice->id,
                'item_id'       => $item['item_id'] ?? null,
                'item_name'     => $item['item_name'],
                'description'   => $item['description'] ?? null,
                'qty'           => $item['qty'],
                'unit'          => $item['unit'] ?? 'pcs',
                'rate'          => $item['rate'],
                'tax'           => $item['tax'] ?? 0,
                'discount'      => $item['discount'] ?? 0,
                'total'         => SalesLineItem::computeTotal($item),
                'sort_order'    => $idx,
            ]);
        }
    }
}
