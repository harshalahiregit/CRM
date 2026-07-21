<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseCreditApplication;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Credit netting — applying a debit note's open balance against a payable
 * invoice's balance, and reversing it.
 *
 * A dedicated service because the operation spans two aggregates (debit note and
 * invoice) and must keep both consistent in one transaction. Putting it on
 * either entity's service would bury a cross-entity money movement inside a
 * single-entity class.
 */
class PurchaseCreditApplicationService
{
    /**
     * Apply part (or all) of a debit note's balance to an invoice.
     *
     * Guards, in order of what they protect:
     *  - the note must be Open with balance left (nothing to apply otherwise);
     *  - the invoice must be payable (you cannot credit a Draft that was never
     *    approved, nor a Paid/Cancelled one);
     *  - SAME VENDOR — a credit from vendor A can never offset vendor B's bill;
     *    this is the load-bearing rule of the whole feature;
     *  - the amount cannot exceed either side's remaining balance.
     */
    public function apply(PurchaseDebitNote $dn, array $data, User $actor): PurchaseCreditApplication
    {
        $invoice = PurchaseInvoice::forTenant($dn->tenant_id)->find($data['purchase_invoice_id'] ?? null);
        if (! $invoice) {
            throw new BusinessException('Invoice not found.', 404);
        }

        if (! $dn->isApplicable()) {
            throw new BusinessException(
                "This debit note is {$dn->status_label} with no balance to apply."
            );
        }
        if (! $invoice->isPayable()) {
            throw new BusinessException(
                "Invoice {$invoice->invoice_number} is {$invoice->status_label} and cannot take a credit."
            );
        }
        // The rule that makes netting sound accounting rather than a way to move
        // money between unrelated vendors.
        if ((int) $dn->vendor_id !== (int) $invoice->vendor_id) {
            throw new BusinessException(
                'A debit note can only be applied to an invoice from the same vendor.'
            );
        }
        if (! $dn->vendor_id) {
            throw new BusinessException('This debit note has no vendor, so it cannot be netted against an invoice.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new BusinessException('Credit amount must be greater than zero.');
        }
        if ($amount > (float) $dn->balance + 0.0001) {
            throw new BusinessException("Credit ({$amount}) exceeds the debit note's available balance ({$dn->balance}).");
        }
        if ($amount > (float) $invoice->balance + 0.0001) {
            throw new BusinessException("Credit ({$amount}) exceeds the invoice's outstanding balance ({$invoice->balance}).");
        }

        $application = DB::transaction(function () use ($dn, $invoice, $amount, $data, $actor) {
            $application = PurchaseCreditApplication::create([
                'tenant_id'              => $dn->tenant_id,
                'purchase_debit_note_id' => $dn->id,
                'purchase_invoice_id'    => $invoice->id,
                'created_by'             => $actor->id,
                'amount'                 => $amount,
                'applied_date'           => $data['applied_date'] ?? now()->toDateString(),
                'reference'              => $data['reference'] ?? null,
                'notes'                  => $data['notes'] ?? null,
            ]);

            // Both sides recompute from the applications table — the row we just
            // wrote is included in each sum, so the two stay in lockstep.
            $invoice->refresh()->recalcPayments();
            $dn->refresh()->recalcRefunds();

            return $application;
        });

        $dn->refresh();
        $invoice->refresh();

        $note = "Applied {$amount} to invoice {$invoice->invoice_number}";
        $dn->recordAudit('Credit Applied', $actor, $note, [
            'amount' => $amount, 'invoice' => $invoice->invoice_number, 'dn_balance' => $dn->balance,
        ]);
        $invoice->recordAudit('Credit Applied', $actor, "Credit {$amount} from debit note {$dn->debit_number}", [
            'amount' => $amount, 'debit_note' => $dn->debit_number, 'balance' => $invoice->balance, 'status' => $invoice->status,
        ]);

        Log::channel('purchase')->info('Debit-note credit applied', [
            'application_id' => $application->id, 'debit_note_id' => $dn->id,
            'invoice_id' => $invoice->id, 'amount' => $amount, 'tenant_id' => $dn->tenant_id,
        ]);

        return $application;
    }

    /**
     * Reverse an application — restores both balances and recomputes both
     * statuses (a Settled note reopens, a Paid invoice drops back to partial).
     */
    public function reverse(PurchaseDebitNote $dn, PurchaseCreditApplication $application, User $actor): PurchaseDebitNote
    {
        if ((int) $application->purchase_debit_note_id !== (int) $dn->id) {
            throw new BusinessException('This credit application does not belong to the debit note.', 404);
        }

        $invoice = PurchaseInvoice::forTenant($dn->tenant_id)->find($application->purchase_invoice_id);
        $amount  = (float) $application->amount;
        $invNumber = $invoice?->invoice_number ?? '(deleted invoice)';

        DB::transaction(function () use ($dn, $invoice, $application) {
            $application->delete();

            // The invoice may be Paid (fully settled by this credit); recalc is
            // skipped on Cancelled, so guard the relink but always recompute what
            // we can.
            if ($invoice) {
                $invoice->refresh()->recalcPayments();
            }
            $dn->refresh()->recalcRefunds();
        });

        $dn->refresh();

        $dn->recordAudit('Credit Reversed', $actor, "Reversed {$amount} applied to invoice {$invNumber}", [
            'amount' => $amount, 'invoice' => $invNumber, 'dn_balance' => $dn->balance,
        ]);
        if ($invoice) {
            $invoice->refresh();
            $invoice->recordAudit('Credit Reversed', $actor, "Reversed credit {$amount} from debit note {$dn->debit_number}", [
                'amount' => $amount, 'balance' => $invoice->balance, 'status' => $invoice->status,
            ]);
        }

        Log::channel('purchase')->info('Debit-note credit reversed', [
            'debit_note_id' => $dn->id, 'invoice_id' => $application->purchase_invoice_id,
            'amount' => $amount, 'tenant_id' => $dn->tenant_id,
        ]);

        return $dn->fresh(['creditApplications']);
    }

    /**
     * Invoices this debit note's remaining balance could be applied to — same
     * vendor, still payable, with an outstanding balance. Powers the apply modal.
     */
    public function applicableInvoices(PurchaseDebitNote $dn): array
    {
        if (! $dn->vendor_id) {
            return [];
        }

        return PurchaseInvoice::forTenant($dn->tenant_id)
            ->where('vendor_id', $dn->vendor_id)
            ->payable()
            ->where('balance', '>', 0)
            ->orderBy('due_date')
            ->get(['id', 'invoice_number', 'total', 'balance', 'due_date', 'status'])
            ->all();
    }
}
