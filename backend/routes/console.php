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
