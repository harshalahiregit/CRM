<?php

namespace App\Providers;

use App\Models\Customer\ClientExpense;
use App\Models\Sales\CreditNote;
use App\Models\Sales\CreditNoteRefund;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesPayment;
use App\Observers\Accounts\SalesAccountingObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Wires Sales/Customer document lifecycle → Accounts auto-posting via static model
 * events (spec v2 §7). Registered entirely from the Accounts side — no Sales or
 * Customer source file is modified. Each hook is best-effort (the observer catches
 * and logs), so an accounting problem can never break a sale.
 */
class AccountingIntegrationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $observer = fn () => $this->app->make(SalesAccountingObserver::class);

        SalesInvoice::saved(fn (SalesInvoice $m) => $observer()->invoiceSaved($m));
        SalesPayment::created(fn (SalesPayment $m) => $observer()->paymentCreated($m));
        CreditNote::saved(fn (CreditNote $m) => $observer()->creditNoteSaved($m));
        CreditNoteRefund::created(fn (CreditNoteRefund $m) => $observer()->refundCreated($m));
        ClientExpense::created(fn (ClientExpense $m) => $observer()->expenseCreated($m));
    }
}
