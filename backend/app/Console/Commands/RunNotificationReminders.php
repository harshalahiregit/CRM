<?php

namespace App\Console\Commands;

use App\Services\Notifications\Reminders\ReminderEngine;
use Illuminate\Console\Command;

/**
 * Central Notification Engine — reminder scheduler. Runs the ReminderEngine across
 * all tenants (or one via --tenant): reads reminder rules, generates due reminders
 * and configuration-driven escalations, idempotently. Scheduled daily; safe hourly.
 */
class RunNotificationReminders extends Command
{
    protected $signature = 'notifications:remind {--tenant= : Limit to one tenant id}';

    protected $description = 'Generate due HR reminders and escalations from notification rules';

    public function handle(ReminderEngine $engine): int
    {
        $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $result = $engine->run($tenant);
        $this->info("Reminders generated: {$result['generated']} (escalated: {$result['escalated']})");

        return self::SUCCESS;
    }
}
