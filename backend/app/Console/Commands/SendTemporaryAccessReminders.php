<?php

namespace App\Console\Commands;

use App\Services\Tpv\TpvAccessService;
use Illuminate\Console\Command;

/**
 * Dispatches Temporary TPV expiry reminders (7d/3d/1d/6h, once each) and lazily
 * expires windows that have run out. Scheduled hourly (see routes/console.php).
 */
class SendTemporaryAccessReminders extends Command
{
    protected $signature = 'tpv:temporary-access-reminders';

    protected $description = 'Send due Temporary TPV expiry reminders and expire lapsed windows';

    public function handle(TpvAccessService $access): int
    {
        $result = $access->sendDueReminders();

        $this->info("Temporary access sweep: {$result['reminders_sent']} reminder(s) sent, {$result['expired']} expired.");

        return self::SUCCESS;
    }
}
