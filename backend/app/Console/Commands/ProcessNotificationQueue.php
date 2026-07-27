<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationQueueService;
use Illuminate\Console\Command;

/**
 * Central Notification Engine — delivery worker. Drains Pending queue items (email
 * live; SMS/WhatsApp/Teams/Slack/Push prepared) and records every outcome on the
 * notification's audit trail. Scheduled every few minutes; idempotent.
 */
class ProcessNotificationQueue extends Command
{
    protected $signature = 'notifications:process-queue {--tenant= : Limit to one tenant id} {--limit=500}';

    protected $description = 'Deliver pending HR notification queue items (email + prepared channels)';

    public function handle(NotificationQueueService $queue): int
    {
        $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $result = $queue->process($tenant, (int) $this->option('limit'));
        $this->info("Processed: {$result['processed']}, sent: {$result['sent']}, failed: {$result['failed']}");

        return self::SUCCESS;
    }
}
