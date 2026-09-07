<?php

use App\Http\Controllers\Api\Settings\AccessCatalogController;
use App\Http\Controllers\Api\Settings\CompanySettingController;
use App\Http\Controllers\Api\Settings\DocumentNumberingController;
use App\Http\Controllers\Api\Settings\EmailTemplateController;
use App\Http\Controllers\Api\Settings\FormatSettingController;
use App\Http\Controllers\Api\Settings\GeneralSettingController;
use App\Http\Controllers\Api\Settings\MailSettingController;
use App\Http\Controllers\Api\Settings\WhatsAppSettingController;
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

    // ST2 — global recycle bin: list soft-deleted records + restore one.
    Route::get('/recycle-bin', [\App\Http\Controllers\Api\Settings\RecycleBinController::class, 'index']);
    Route::post('/recycle-bin/restore', [\App\Http\Controllers\Api\Settings\RecycleBinController::class, 'restore']);

    // Generic settings groups — Upload / Security / Notification preferences.
    // One controller + one registry; add a group by registering it + the constraint.
    Route::get('/group/{group}', [SettingsGroupController::class, 'show'])
        ->where('group', 'localization|currency|numbering|upload|security|notifications');
    Route::put('/group/{group}', [SettingsGroupController::class, 'update'])
        ->where('group', 'localization|currency|numbering|upload|security|notifications');

    // Roles & Departments — created from the UI instead of by a developer.
    // These are staff JOB roles (users.internal_role), not account types:
    // an account type is a whole portal and stays in code. See the
    // access_roles migration for why the two are kept apart.
    Route::get('/roles',                   [AccessCatalogController::class, 'roles']);
    Route::post('/roles',                  [AccessCatalogController::class, 'storeRole']);
    Route::put('/roles/{role}',            [AccessCatalogController::class, 'updateRole'])->whereNumber('role');
    Route::delete('/roles/{role}',         [AccessCatalogController::class, 'destroyRole'])->whereNumber('role');

    Route::get('/departments',                   [AccessCatalogController::class, 'departments']);
    Route::post('/departments',                  [AccessCatalogController::class, 'storeDepartment']);
    Route::put('/departments/{department}',      [AccessCatalogController::class, 'updateDepartment'])->whereNumber('department');
    Route::delete('/departments/{department}',   [AccessCatalogController::class, 'destroyDepartment'])->whereNumber('department');

    // Document Numbering Engine — the single source of truth for document numbers.
    // `validate` is declared before `{type}` so it is never captured as a type.
    Route::get('/numbering',                    [DocumentNumberingController::class, 'index']);
    Route::post('/numbering/validate',          [DocumentNumberingController::class, 'validateConfig']);
    Route::get('/numbering/{type}',             [DocumentNumberingController::class, 'show']);
    Route::put('/numbering/{type}',             [DocumentNumberingController::class, 'update']);
    Route::post('/numbering/{type}/preview',    [DocumentNumberingController::class, 'preview']);
    Route::post('/numbering/{type}/reset',      [DocumentNumberingController::class, 'reset']);

    // Email Templates — the single source of truth for outgoing email content.
    // `validate` is declared before `{key}` so it is never captured as a key.
    // Template keys contain dots (e.g. auth.welcome), hence the explicit `where`.
    Route::get('/email-templates',                  [EmailTemplateController::class, 'index']);
    Route::post('/email-templates/validate',        [EmailTemplateController::class, 'validateTemplate']);
    Route::get('/email-templates/{key}',            [EmailTemplateController::class, 'show'])->where('key', '[A-Za-z0-9_.\-]+');
    Route::put('/email-templates/{key}',            [EmailTemplateController::class, 'update'])->where('key', '[A-Za-z0-9_.\-]+');
    Route::post('/email-templates/{key}/preview',   [EmailTemplateController::class, 'preview'])->where('key', '[A-Za-z0-9_.\-]+');
    Route::post('/email-templates/{key}/restore',   [EmailTemplateController::class, 'restore'])->where('key', '[A-Za-z0-9_.\-]+');

    // Email / SMTP (per-tenant outgoing mail)
    Route::get('/mail',       [MailSettingController::class, 'show']);
    Route::put('/mail',       [MailSettingController::class, 'update']);
    Route::post('/mail/test', [MailSettingController::class, 'testSend']);

    // WhatsApp sender — same shape as /mail: read, save, prove it works.
    Route::get('/whatsapp',         [WhatsAppSettingController::class, 'show']);
    Route::put('/whatsapp',         [WhatsAppSettingController::class, 'update']);
    Route::post('/whatsapp/verify', [WhatsAppSettingController::class, 'verify']);
    Route::post('/whatsapp/test',   [WhatsAppSettingController::class, 'testSend']);

    // Company & Finance (registered state + GSTIN for tax auto-split)
    Route::get('/company', [CompanySettingController::class, 'show']);
    Route::put('/company', [CompanySettingController::class, 'update']);

    // Staff master email switches (meeting: single disable-all-emails control)
    Route::get('/staff-emails',                [MailSettingController::class, 'staffEmails']);
    Route::patch('/staff-emails/{user}/toggle', [MailSettingController::class, 'toggleStaffEmails']);
});
