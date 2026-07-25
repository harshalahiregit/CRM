<?php

namespace App\Console\Commands;

use App\Services\Project\ProjectService;
use App\Services\Task\TaskService;
use Illuminate\Console\Command;

/**
 * Drives everything on the Task module that happens on a clock: spawning the next
 * copy of recurring templates, firing due reminders, and sending due-soon/overdue
 * nudges.
 *
 * Safe to run repeatedly — every section is idempotent (last_recurring_date,
 * notified_at and deadline_notified are the guards), so a double-run or a catch-up
 * run after downtime won't duplicate anything.
 */
class RunTaskSchedule extends Command
{
    protected $signature = 'tasks:run-schedule {--dry : Report what would fire without writing}';

    protected $description = 'Spawn due recurring tasks and send task reminders/deadline notices';

    public function handle(TaskService $tasks, ProjectService $projects): int
    {
        if ($this->option('dry')) {
            $this->warn('Dry run is not supported yet — this command writes. Aborting.');

            return self::FAILURE;
        }

        $r = $tasks->runScheduled();
        $projectDeadlines = $projects->fireDueDeadlines();

        $this->info(sprintf(
            'Tasks: %d recurring created, %d reminders sent, %d deadline notices. Projects: %d deadline notices.',
            $r['recurring'], $r['reminders'], $r['deadlines'], $projectDeadlines,
        ));

        return self::SUCCESS;
    }
}
