<?php

namespace App\Observers\Accounts;

use App\Models\Customer\ClientExpense;
use App\Models\Sales\CreditNote;
use App\Models\Sales\CreditNoteRefund;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesPayment;
use App\Services\Accounts\Integration\SalesPostingBridge;
use Illuminate\Support\Facades\Log;

/**
 * Auto-posts Sales/Customer documents to the Accounts ledger (spec v2 §7 — modules
 * emit events, the resolver posts). Registered from the Accounts side, so no
 * Sales/Customer code is modified. Posting is best-effort: any failure is caught
 * and logged so it can NEVER break invoicing/payment; the backfill command retries.
 */
class SalesAccountingObserver
{
    /** Invoice statuses that represent a live (issued) invoice worth booking. */
    private const LIVE_INVOICE = ['Unpaid', 'Partially Paid', 'Paid', 'Overdue'];

    public function __construct(private SalesPostingBridge $bridge)
    {
    }

    public function invoiceSaved(SalesInvoice $invoice): void
    {
        $this->safely(function () use ($invoice) {
            if (in_array($invoice->status, self::LIVE_INVOICE, true)) {
                $this->bridge->postInvoice($invoice);            // idempotent
            } elseif ($invoice->status === 'Cancelled') {
                $this->bridge->reverseForSource('sales_invoice', $invoice->id, $invoice->tenant_id, $invoice->created_by);
            }
        }, 'invoice', $invoice->id);
    }

    public function paymentCreated(SalesPayment $payment): void
    {
        $this->safely(fn () => $this->bridge->postReceipt($payment), 'payment', $payment->id);
    }

    public function creditNoteSaved(CreditNote $creditNote): void
    {
        $this->safely(function () use ($creditNote) {
            if ($creditNote->status === 'Void') {
                $this->bridge->reverseForSource('credit_note', $creditNote->id, $creditNote->tenant_id, $creditNote->created_by);
            } else {
                $this->bridge->postCreditNote($creditNote);      // idempotent
            }
        }, 'credit_note', $creditNote->id);
    }

    public function refundCreated(CreditNoteRefund $refund): void
    {
        $this->safely(fn () => $this->bridge->postRefund($refund), 'credit_note_refund', $refund->id);
    }

    public function expenseCreated(ClientExpense $expense): void
    {
        $this->safely(fn () => $this->bridge->postExpense($expense), 'client_expense', $expense->id);
    }

    /**
     * Purchase invoices (owner: Harshal). Posts once approved, and reverses if a
     * posted invoice is later cancelled — same discipline as the sales side.
     * Typed loosely because Accounts must not hard-depend on the Purchase module
     * being installed.
     */
    public function purchaseInvoiceSaved(object $invoice): void
    {
        $this->safely(function () use ($invoice) {
            if (strtolower((string) ($invoice->status ?? '')) === 'cancelled') {
                $this->bridge->reverseForSource('purchase_invoice', (int) $invoice->id, (int) $invoice->tenant_id, $invoice->created_by ?? null);
            } else {
                $this->bridge->postPurchaseInvoice($invoice);     // idempotent; no-op until approved
            }
        }, 'purchase_invoice', (int) $invoice->id);
    }

    /** Run a posting action; never let an accounting error bubble into the source flow. */
    private function safely(callable $fn, string $kind, int $id): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::channel('accounts')->warning('Auto-post skipped', [
                'source' => $kind, 'id' => $id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
