<?php

namespace App\Console\Commands;

use App\Services\Shared\KickoffMeetingService;
use Illuminate\Console\Command;

/**
 * Fires automatic reminders for published kickoff meetings as each configured
 * lead time before the start is reached (config('meetings.reminder_offsets_minutes')
 * — default 24h and 1h before).
 *
 * Safe to run repeatedly: KickoffMeetingService::runDueReminders() records each
 * fired window on the meeting (reminders_sent), so a due window converts to
 * exactly one send and a catch-up run after downtime never double-sends. It also
 * reads the LIVE scheduled_at, so a rescheduled meeting's reminders move with it.
 */
class SendKickoffReminders extends Command
{
    protected $signature = 'kickoff:send-reminders';

    protected $description = 'Send automatic reminder e-mails for upcoming published kickoff meetings';

    public function handle(KickoffMeetingService $service): int
    {
        $sent = $service->runDueReminders();

        $this->info("Kickoff: reminder(s) fired for {$sent} meeting(s).");

        return self::SUCCESS;
    }
}
