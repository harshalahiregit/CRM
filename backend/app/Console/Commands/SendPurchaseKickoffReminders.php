<?php

namespace App\Console\Commands;

use App\Services\Purchase\PurchaseKickoffService;
use Illuminate\Console\Command;

/**
 * Automatic reminders for published Purchase kickoff meetings (24h + 1h before,
 * per config). Idempotent via reminders_sent. Mirrors kickoff:send-reminders.
 */
class SendPurchaseKickoffReminders extends Command
{
    protected $signature = 'purchase-kickoff:send-reminders';

    protected $description = 'Send automatic reminder e-mails for upcoming published Purchase kickoff meetings';

    public function handle(PurchaseKickoffService $service): int
    {
        $sent = $service->runDueReminders();
        // Before-meeting reminders and after-meeting follow-ups in one command,
        // so scheduling one always schedules the other.
        $followed = $service->runDueFollowUps();
        // ...and the third direction: a meeting whose slot passed while it
        // was still open. Neither a reminder nor a follow-up says that.
        $expired  = $service->runDueExpiryNotices();
        $this->info("Purchase kickoff: reminder(s) fired for {$sent} meeting(s), follow-up(s) for {$followed}, expiry notice(s) for {$expired}.");

        return self::SUCCESS;
    }
}
