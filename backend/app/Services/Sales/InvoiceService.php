<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Sales\PaymentReminder;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesLineItem;
use App\Models\Sales\SalesPayment;
use App\Repositories\Sales\InvoiceRepository;
use App\Services\Sales\CommissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InvoiceService
{
    public function __construct(
        private InvoiceRepository $invoiceRepository,
        private CommissionService $commissionService,
    ) {
    }

    public function list(int $tenantId, array $filters)
    {
        $invoices = $this->invoiceRepository->filtered($tenantId, $filters);

        // Auto-mark overdue before returning
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
                'tds_amount'     => $data['tds_amount'] ?? 0,
                'tds_section'    => $data['tds_section'] ?? null,
                'tds_percentage' => $data['tds_percentage'] ?? 0,
                'payment_type'   => $data['payment_type'] ?? 'received',
            ]);

            $invoice->recalcBalance();

            // Generate commission entries when the invoice becomes fully paid.
            // Idempotent (unique rule+source), so repeated partial payments are safe.
            $this->commissionService->computeForInvoice($invoice->fresh());

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

    public function generatePublicLink(SalesInvoice $invoice, int $tenantId, ?int $expiryDays = 30): SalesInvoice
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        $invoice->update([
            'public_link_token'  => Str::random(48),
            'public_link_expiry' => $expiryDays ? now()->addDays($expiryDays) : null,
        ]);

        Log::channel('sales')->info('Invoice public link generated', [
            'invoice_id' => $invoice->id,
            'tenant_id'  => $tenantId,
            'expires_at' => $invoice->public_link_expiry,
        ]);

        return $invoice->fresh();
    }

    /**
     * Manual trigger for a payment reminder. Records the reminder in the
     * audit trail (payment_reminders table) and marks it sent. Does not
     * dispatch an actual email — Sales has no Mailable/SMTP wiring yet,
     * and automated/scheduled reminders are explicitly deferred per the
     * Sales Master Plan V2. This gives ops a real, queryable record of
     * "a reminder was manually triggered for this invoice, by whom, when".
     */
    public function sendPaymentReminder(SalesInvoice $invoice, int $tenantId): PaymentReminder
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        $reminder = PaymentReminder::create([
            'tenant_id'      => $tenantId,
            'invoice_id'     => $invoice->id,
            'reminder_type'  => 'on_due',
            'days_offset'    => 0,
            'scheduled_for'  => now(),
            'sent_at'        => now(),
            'email_to'       => null,
            'status'         => 'sent',
        ]);

        Log::channel('sales')->info('Payment reminder manually triggered', [
            'invoice_id' => $invoice->id,
            'tenant_id'  => $tenantId,
            'reminder_id'=> $reminder->id,
        ]);

        return $reminder;
    }

    /**
     * Manual trigger for a post-payment feedback request. Marks the flag
     * on the invoice; does not dispatch an actual email (same rationale
     * as sendPaymentReminder above).
     */
    public function sendFeedbackRequest(SalesInvoice $invoice, int $tenantId): SalesInvoice
    {
        $this->assertBelongsToTenant($invoice, $tenantId);

        if ($invoice->balance > 0) {
            throw new BusinessException('Cannot request feedback on an invoice that is not fully paid.', 422);
        }

        $invoice->update(['feedback_email_sent' => true]);

        Log::channel('sales')->info('Feedback request marked sent', [
            'invoice_id' => $invoice->id,
            'tenant_id'  => $tenantId,
        ]);

        return $invoice->fresh();
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
