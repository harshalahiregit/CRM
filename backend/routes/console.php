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

// Accounts: flag post-dated cheques that fall due (daily, early morning)
Schedule::command('accounts:pdc-due')
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Database backup (enhancement #12): one archive a night, kept to the last N
// (config/backup.php). Early hours to avoid the working-day load; the command
// prunes older archives itself so the store never grows without bound.
Schedule::command('backup:run')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Vendor compliance sweep: auto-suspend vendors whose statutory cover has expired
// and reinstate them once renewed. Runs before the working day so a lapse locks
// site access from the morning.
Schedule::command('vendors:enforce-compliance')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();

// Tasks: spawn recurring copies, fire reminders, send due/overdue notices.
// Every 15 minutes so a reminder set for 10:30 doesn't land at 11:00; the command
// is idempotent, so the extra runs are no-ops when nothing is due.
Schedule::command('tasks:run-schedule')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// SangoeTrack: pull the current month's attendance into hr_attendance nightly.
// The whole month is re-fetched so late punches and back-dated corrections made
// in SangoeTrack reach us too; unchanged days are a no-op, so this is cheap.
Schedule::command('sangoetrack:sync-attendance')
    ->dailyAt('23:30')
    ->withoutOverlapping()
    ->runInBackground();

// Leave runs alongside attendance. Separate commands so one failing side cannot
// take the other down and either can be re-run on its own after a fix; the JWT
// is cached, so the two together still cost one login.
Schedule::command('sangoetrack:sync-leaves')
    ->dailyAt('23:30')
    ->withoutOverlapping()
    ->runInBackground();

// Month-end catch-up: on the 1st, re-sync the month that just closed so any
// corrections entered after the 23:30 run on the last day are still picked up.
Schedule::command('sangoetrack:sync-attendance --previous')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sangoetrack:sync-leaves --previous')
    ->monthlyOn(1, '02:00')
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

// Projects: fire due note reminders (in-app bell). Idempotent (reminded flag),
// so quiet no-op runs cost one indexed query.
Schedule::command('projects:run-note-reminders')
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

// Temporary TPV expiry reminders (7d/3d/1d/6h) + lapsed-window expiry — hourly.
Schedule::command('tpv:temporary-access-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Onboarding approval SLA escalations — hourly.
Schedule::command('tpv:approval-escalations')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Central Notification Engine — generate HR reminders + escalations (daily).
Schedule::command('notifications:remind')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();

// Central Notification Engine — deliver queued emails/notifications (every 5 min).
Schedule::command('notifications:process-queue')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// HR: expire offers past their validity window (hourly). Reuses OfferService::expireIfDue;
// previously offers only expired lazily when the candidate opened the portal.
Schedule::command('offers:expire-due')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Inventory: the daily expiry digest.
// Once a day at 07:00, not every fifteen minutes: expiry moves at the speed of
// the calendar, so a batch that enters the alert window overnight is equally
// urgent at seven in the morning as it was at midnight — and a digest that lands
// at the start of the working day is one somebody actually acts on. Low stock is
// NOT on this clock; it's edge-triggered the instant a posting crosses the
// reorder point, which is when it's worth interrupting someone. The command is
// idempotent (expiry_alerted_at), so a catch-up run after downtime sends one
// digest rather than one per missed day.
Schedule::command('inventory:run-alerts')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();

// Inventory: raise the week's random cycle-count sheets.
// Monday morning, before the week fills up — a count sheet that lands on a
// Friday afternoon is a count sheet that gets walked in a hurry or not at all.
// Off unless a tenant switches `cycle_count_auto` on, and skipped for any
// warehouse whose last sheet is still open, so a catch-up run after downtime
// never stacks four weeks of counting onto one person.
Schedule::command('inventory:cycle-counts')
    ->weeklyOn(1, '08:00')
    ->withoutOverlapping()
    ->runInBackground();
