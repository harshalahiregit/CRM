<?php

use App\Http\Controllers\Api\Settings\CompanySettingController;
use App\Http\Controllers\Api\Settings\FormatSettingController;
use App\Http\Controllers\Api\Settings\GeneralSettingController;
use App\Http\Controllers\Api\Settings\MailSettingController;
use App\Http\Controllers\Api\Settings\SettingsGroupController;
use Illuminate\Support\Facades\Route;

// ── Formatting contract (any authenticated user of the tenant) ──────────
// Currency + localization the whole app formats against — React, PDF renderers
// and email templates all read this instead of hardcoding symbols/date patterns.
Route::middleware(['auth:sanctum'])->prefix('settings')->group(function () {
    Route::get('/formats', [FormatSettingController::class, 'show']);
});

// ── Workspace Settings (admin-only) ─────────────────────────────────────
// Home for tenant-level configuration: email/SMTP now; company/finance and
// other sections plug in here as they land.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('settings')->group(function () {

    // General & Branding (generic tenant settings store)
    Route::get('/general', [GeneralSettingController::class, 'show']);
    Route::put('/general', [GeneralSettingController::class, 'update']);

    // Generic settings groups — Upload / Security / Notification preferences.
    // One controller + one registry; add a group by registering it + the constraint.
    Route::get('/group/{group}', [SettingsGroupController::class, 'show'])
        ->where('group', 'localization|currency|upload|security|notifications');
    Route::put('/group/{group}', [SettingsGroupController::class, 'update'])
        ->where('group', 'localization|currency|upload|security|notifications');

    // Email / SMTP (per-tenant outgoing mail)
    Route::get('/mail',       [MailSettingController::class, 'show']);
    Route::put('/mail',       [MailSettingController::class, 'update']);
    Route::post('/mail/test', [MailSettingController::class, 'testSend']);

    // Company & Finance (registered state + GSTIN for tax auto-split)
    Route::get('/company', [CompanySettingController::class, 'show']);
    Route::put('/company', [CompanySettingController::class, 'update']);

    // Staff master email switches (meeting: single disable-all-emails control)
    Route::get('/staff-emails',                [MailSettingController::class, 'staffEmails']);
    Route::patch('/staff-emails/{user}/toggle', [MailSettingController::class, 'toggleStaffEmails']);
});
