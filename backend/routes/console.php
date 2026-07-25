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
