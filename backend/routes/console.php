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
