<?php

namespace App\Events\Accounts;

use App\Models\Accounts\Voucher;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a posted voucher is cancelled via a reversing voucher.
 *
 * $original is the now-cancelled voucher; $reversal is the neutralising entry.
 * Same intent as VoucherPosted — a designed-for seam, no Phase 1 listeners.
 */
class VoucherReversed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Voucher $original, public Voucher $reversal)
    {
    }
}
