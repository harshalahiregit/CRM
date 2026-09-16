<?php

namespace App\Console\Commands\Purchase;

use App\Services\Purchase\PurchaseAccessService;
use Illuminate\Console\Command;

/**
 * Warns temporary Purchase Vendors before their access ends (7d/3d/1d/6h, once
 * each) and expires the windows that have run out. Scheduled hourly — see
 * routes/console.php.
 *
 * The Purchase counterpart of tpv:temporary-access-reminders. Purchase had no
 * sweep at all, so a temporary purchase vendor was never warned and never
 * actually expired.
 */
class SendPurchaseTemporaryAccessReminders extends Command
{
    protected $signature = 'purchase:temporary-access-reminders';

    protected $description = 'Send due temporary Purchase Vendor expiry reminders and expire lapsed windows';

    public function handle(PurchaseAccessService $access): int
    {
        $result = $access->sendDueReminders();

        $this->info("Purchase temporary access sweep: {$result['reminders_sent']} reminder(s) sent, "
            ."{$result['expired']} expired.");

        return self::SUCCESS;
    }
}
