<?php

namespace App\Events\Accounts;

use App\Models\Accounts\Voucher;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a voucher is successfully posted to the ledger.
 *
 * This is the seam for the v2 posting-rule contract (spec v2 §7): downstream
 * listeners (report-cache invalidation, GST/e-invoice jobs, dashboards) hang off
 * this event. NO listeners are registered in Phase 1 — the event is emitted so
 * the wiring exists, but nothing consumes it yet and no other module is touched.
 */
class VoucherPosted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Voucher $voucher)
    {
    }
}
