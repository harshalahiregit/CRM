<?php

use App\Models\Sales\SalesInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-settle every invoice that has TDS recorded against it.
 *
 * SalesInvoice::recalcBalance() summed only `amount`, so TDS withheld by the
 * customer never reduced the balance. A ₹1,00,000 invoice paid as ₹98,000 cash
 * plus ₹2,000 TDS stayed "Partially Paid" with a ₹2,000 balance permanently —
 * counted in ageing, and generating overdue reminders to customers who had
 * settled in full.
 *
 * The calculation is fixed in the model, but that only helps invoices touched
 * afterwards: nothing recomputes an invoice on its own. Every one already in
 * that state stays wrong until it is recounted here.
 *
 * Only invoices with a TDS payment are touched — no other invoice's figures
 * change, so this cannot quietly restate anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sales_payments is a hard-delete table — no deleted_at to filter on.
        $ids = DB::table('sales_payments')
            ->where('tds_amount', '>', 0)
            ->distinct()
            ->pluck('invoice_id');

        if ($ids->isEmpty()) {
            return;
        }

        SalesInvoice::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->each(fn (SalesInvoice $invoice) => $invoice->recalcBalance());
    }

    public function down(): void
    {
        // Nothing to undo: this restores figures to what the corrected rule says
        // they always should have been. Reverting would mean deliberately
        // rewriting them back to the wrong values.
    }
};
