<?php

namespace App\Console\Commands;

use App\Models\Hr\HrEmployeeOnboarding;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/**
 * Reminds employees whose onboarding is still incomplete. Reuses the existing
 * NotificationService and the existing audit trail — no notification tables.
 */
class SendOnboardingReminders extends Command
{
    protected $signature = 'hr:onboarding-reminders {--days=3 : Remind when untouched for this many days} {--dry-run}';

    protected $description = 'Send reminders for pending / in-progress employee onboarding';

    public function handle(NotificationService $notifications): int
    {
        $days = (int) $this->option('days');
        $dry  = (bool) $this->option('dry-run');
        $sent = 0;

        $due = HrEmployeeOnboarding::with('employee')
            ->whereIn('status', ['Pending', 'In_Progress'])
            ->where('updated_at', '<=', now()->subDays($days))
            ->get();

        foreach ($due as $o) {
            $emp = $o->employee;
            if (! $emp?->email) {
                continue;
            }

            if ($dry) {
                $this->line("  would remind → {$emp->name} ({$o->status}, {$o->progress_percent}%)");
                $sent++;
                continue;
            }

            try {
                $notifications->email($emp->email, 'Reminder: complete your onboarding',
                    'Hello '.$emp->name.', your onboarding is '.$o->progress_percent.'% complete. Please finish the remaining sections.',
                    ['onboarding_id' => $o->id, 'event' => 'onboarding_reminder']);
                $o->recordAudit('Onboarding Reminder Sent', null, null, ['progress' => $o->progress_percent], 'System');
                $this->info("  reminded → {$emp->name} ({$o->progress_percent}%)");
                $sent++;
            } catch (\Throwable $e) {
                $this->error("  FAILED → {$emp->name}: {$e->getMessage()}");
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')."Reminders: {$sent} (pending/in-progress older than {$days} day(s))");

        return self::SUCCESS;
    }
}
