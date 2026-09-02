<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Is anything actually draining the queue?
 *
 * QUEUE_CONNECTION is `database` and 21 Mailables implement ShouldQueue, but the
 * live host has no supervisor and no worker — so queued mail does not fail, it
 * simply accumulates in the jobs table and nobody is told. The symptom people
 * report is "the customer never got the email", which looks like a mail problem
 * and is not.
 *
 * This makes that visible in one command, and exits non-zero when the backlog
 * looks stuck so it can be wired to an alert later.
 *
 *   php artisan queue:health
 *   php artisan queue:health --max-age=15 --max-pending=500
 */
class QueueHealth extends Command
{
    protected $signature = 'queue:health
        {--max-age=10 : Minutes the oldest waiting job may sit before this is treated as stuck}
        {--max-pending=1000 : How many waiting jobs is too many}';

    protected $description = 'Report queue backlog and whether a worker appears to be running';

    /** Minutes are unreadable past an hour or two; say it the way a person would. */
    private function humanAge(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        if ($minutes < 60 * 48) {
            $hours = intdiv($minutes, 60);

            return $hours . ' hour' . ($hours === 1 ? '' : 's');
        }

        $days = intdiv($minutes, 60 * 24);

        return $days . ' day' . ($days === 1 ? '' : 's');
    }

    public function handle(): int
    {
        $pending = DB::table('jobs')->count();
        $failed  = DB::table('failed_jobs')->count();

        $this->line('  connection      : ' . config('queue.default'));
        $this->line('  waiting         : ' . $pending);
        $this->line('  failed          : ' . $failed);

        if ($pending === 0) {
            $this->line('  oldest waiting  : —');
            $this->info($failed > 0
                ? 'Queue is drained. Review the failed jobs above.'
                : 'Queue is drained.');

            return self::SUCCESS;
        }

        // available_at is a unix timestamp; a job is only genuinely waiting once
        // it is due, so a delayed job scheduled for next week is not a backlog.
        $oldestDue = DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->min('available_at');

        if ($oldestDue === null) {
            $this->line('  oldest waiting  : nothing due yet (all delayed)');
            $this->info('No due jobs waiting.');

            return self::SUCCESS;
        }

        // Carbon 3 returns a float here, so it is floored — otherwise a 38-day
        // backlog prints as '55604.7810203 minutes', which reads like a bug and
        // buries the number that matters.
        $ageMinutes = (int) floor(Carbon::createFromTimestamp($oldestDue)->diffInMinutes(now()));
        $this->line('  oldest due job  : ' . $this->humanAge($ageMinutes) . ' ago');

        $maxAge     = (int) $this->option('max-age');
        $maxPending = (int) $this->option('max-pending');

        if ($ageMinutes > $maxAge) {
            $this->newLine();
            $this->error('A job has been waiting ' . $this->humanAge($ageMinutes) . '. No worker appears to be running.');
            $this->line('  Start one with:  php artisan queue:work --stop-when-empty');
            $this->line('  Or keep one alive from cron — see DEPLOY.md.');

            return self::FAILURE;
        }

        if ($pending > $maxPending) {
            $this->newLine();
            $this->error("{$pending} jobs are waiting. The worker is running but not keeping up.");

            return self::FAILURE;
        }

        $this->info('A worker appears to be draining the queue.');

        return self::SUCCESS;
    }
}
