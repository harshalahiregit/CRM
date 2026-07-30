<?php

use App\Http\Controllers\Api\Settings\CompanySettingController;
use App\Http\Controllers\Api\Settings\GeneralSettingController;
use App\Http\Controllers\Api\Settings\MailSettingController;
use Illuminate\Support\Facades\Route;

// ── Workspace Settings (admin-only) ─────────────────────────────────────
// Home for tenant-level configuration: email/SMTP now; company/finance and
// other sections plug in here as they land.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('settings')->group(function () {

    // General & Branding (generic tenant settings store)
    Route::get('/general', [GeneralSettingController::class, 'show']);
    Route::put('/general', [GeneralSettingController::class, 'update']);

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
