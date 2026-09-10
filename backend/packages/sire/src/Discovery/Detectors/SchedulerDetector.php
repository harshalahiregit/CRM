<?php

namespace Sire\Discovery\Detectors;

use Sire\Discovery\Finding;

/**
 * SIRE — is anything actually running the scheduler?
 *
 * SIRE needs one scheduled command every fifteen minutes, and needs it for
 * exactly one thing: proactive SLA warning and breach notices. Without a
 * scheduler SLA state is still computed on read, so dashboards and issue pages
 * stay correct — only the "you are about to breach" message is lost.
 *
 * WHY THIS IS HONEST ABOUT WHAT IT CANNOT SEE
 *
 * A PHP process cannot read the system crontab of the user it does not run as,
 * cannot see a Kubernetes CronJob, and cannot know about a supervisor entry on
 * another host. So this reports what it CAN establish — that the scheduler class
 * exists, whether other commands are registered — and says plainly that
 * confirming cron is a human step. Claiming to have verified cron from inside
 * PHP would be the kind of false PASS that makes a doctor worthless.
 */
class SchedulerDetector implements Detector
{
    public function name(): string
    {
        return 'scheduler';
    }

    public function detect(): array
    {
        $out = [];

        $available = class_exists(\Illuminate\Console\Scheduling\Schedule::class);

        $out['scheduler.available'] = Finding::found(
            $available,
            Finding::HIGH,
            ['Illuminate\Console\Scheduling\Schedule '.($available ? 'exists' : 'is missing')],
        );

        try {
            $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
            $events = $schedule->events();

            $out['scheduler.registered_events'] = Finding::found(
                count($events),
                Finding::HIGH,
                ['Schedule::events()'],
            );

            $out['scheduler.host_uses_scheduler'] = Finding::found(
                count($events) > 0,
                count($events) > 0 ? Finding::MEDIUM : Finding::LOW,
                [count($events) > 0
                    ? 'the host already schedules work, so cron is probably running'
                    : 'nothing else is scheduled — cron may not be configured at all'],
            );
        } catch (\Throwable $e) {
            $out['scheduler.registered_events'] = Finding::absent(['could not resolve the scheduler: '.$e->getMessage()]);
        }

        // Said out loud so no report implies more than was checked.
        $out['scheduler.cron_verified'] = Finding::absent([
            'a PHP process cannot verify that cron runs schedule:run',
            'confirm with: crontab -l | grep schedule:run',
            'without it, SLA state is still computed on read; only proactive notices are lost',
        ]);

        return $out;
    }
}
