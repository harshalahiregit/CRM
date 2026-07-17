<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule interview reminders to run every hour
Schedule::command('whatsapp:interview-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Tasks: spawn recurring copies, fire reminders, send due/overdue notices.
// Every 15 minutes so a reminder set for 10:30 doesn't land at 11:00; the command
// is idempotent, so the extra runs are no-ops when nothing is due.
Schedule::command('tasks:run-schedule')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Helpdesk: fire due ticket reminders (in-app + email).
// Every five minutes, not every minute: an agent sets these to the minute ("remind
// me at 14:00"), so quarter-hour granularity would land a 14:01 reminder at 14:15
// — late enough to feel broken. Five minutes keeps the worst-case lag under the
// resolution anyone actually perceives, at a cost of one indexed, usually-empty
// query (notified_at, remind_at). Every minute buys ~4 minutes of accuracy nobody
// asked for and multiplies the wake-ups by five. The command is idempotent, so
// the no-op runs cost nothing.
Schedule::command('helpdesk:run-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Helpdesk: warn on at-risk / breached SLA clocks.
// Every fifteen minutes — SLA targets are measured in hours, so a quarter-hour of
// lag is noise against the deadline, and the alert itself is throttled to once per
// hour per ticket anyway. Running tighter would just re-run the narrowing query.
Schedule::command('helpdesk:check-sla')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
