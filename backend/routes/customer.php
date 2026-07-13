<?php

use App\Http\Controllers\Api\Customer\ClientAddressController;
use App\Http\Controllers\Api\Customer\ClientAttachmentController;
use App\Http\Controllers\Api\Customer\ClientContactController;
use App\Http\Controllers\Api\Customer\ClientController;
use App\Http\Controllers\Api\Customer\ClientGroupController;
use App\Http\Controllers\Api\Customer\ClientNoteController;
use App\Http\Controllers\Api\Customer\ClientRecipientController;
use App\Http\Controllers\Api\Customer\ClientReminderController;
use App\Http\Controllers\Api\Customer\ClientVaultController;
use App\Http\Controllers\Api\Customer\CustomFieldController;
use Illuminate\Support\Facades\Route;

// ── Customer / Clients Module (Sanctum) ─────────────────────────────────
// Internal staff surface. Role gating: any staff/admin may manage customers;
// client/vendor roles must not reach these — enforced with role middleware.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('customers')->group(function () {

    // Summary + import/export (static paths BEFORE the {client} wildcard)
    Route::get('/summary',  [ClientController::class, 'summary']);
    Route::post('/import',  [ClientController::class, 'import']);
    Route::get('/export',   [ClientController::class, 'export']);

    // Groups
    Route::get('/groups',            [ClientGroupController::class, 'index']);
    Route::post('/groups',           [ClientGroupController::class, 'store']);
    Route::put('/groups/{group}',    [ClientGroupController::class, 'update']);
    Route::delete('/groups/{group}', [ClientGroupController::class, 'destroy']);

    // Custom field definitions
    Route::get('/custom-fields',                  [CustomFieldController::class, 'index']);
    Route::post('/custom-fields',                 [CustomFieldController::class, 'store']);
    Route::put('/custom-fields/{customField}',    [CustomFieldController::class, 'update']);
    Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy']);

    // Clients CRUD
    Route::get('/',            [ClientController::class, 'index']);
    Route::post('/',           [ClientController::class, 'store']);
    Route::get('/{client}',    [ClientController::class, 'show']);
    Route::put('/{client}',    [ClientController::class, 'update']);
    Route::delete('/{client}', [ClientController::class, 'destroy']);

    // Profile read tabs (loop-ins + rollups)
    Route::get('/{client}/tax',          [ClientController::class, 'taxSummary']);
    Route::get('/{client}/tickets',      [ClientController::class, 'tickets']);
    Route::get('/{client}/invoices',     [ClientController::class, 'invoices']);
    Route::get('/{client}/estimates',    [ClientController::class, 'estimates']);
    Route::get('/{client}/proposals',    [ClientController::class, 'proposals']);
    Route::get('/{client}/credit-notes', [ClientController::class, 'creditNotes']);
    Route::get('/{client}/payments',     [ClientController::class, 'payments']);
    Route::get('/{client}/statement',    [ClientController::class, 'statement']);

    // Customer admins (account managers)
    Route::get('/{client}/admins',  [ClientController::class, 'admins']);
    Route::put('/{client}/admins',  [ClientController::class, 'syncAdmins']);

    // Contacts
    Route::get('/{client}/contacts',                       [ClientContactController::class, 'index']);
    Route::post('/{client}/contacts',                      [ClientContactController::class, 'store']);
    Route::put('/{client}/contacts/{contact}',             [ClientContactController::class, 'update']);
    Route::patch('/{client}/contacts/{contact}/active',    [ClientContactController::class, 'toggleActive']);
    Route::delete('/{client}/contacts/{contact}',          [ClientContactController::class, 'destroy']);

    // Notes
    Route::get('/{client}/notes',          [ClientNoteController::class, 'index']);
    Route::post('/{client}/notes',         [ClientNoteController::class, 'store']);
    Route::delete('/{client}/notes/{note}', [ClientNoteController::class, 'destroy']);

    // Reminders
    Route::get('/{client}/reminders',                  [ClientReminderController::class, 'index']);
    Route::post('/{client}/reminders',                 [ClientReminderController::class, 'store']);
    Route::delete('/{client}/reminders/{reminder}',    [ClientReminderController::class, 'destroy']);

    // Vault (credentials)
    Route::get('/{client}/vault',                          [ClientVaultController::class, 'index']);
    Route::post('/{client}/vault',                         [ClientVaultController::class, 'store']);
    Route::put('/{client}/vault/{vaultEntry}',             [ClientVaultController::class, 'update']);
    Route::get('/{client}/vault/{vaultEntry}/reveal',      [ClientVaultController::class, 'reveal']);
    Route::delete('/{client}/vault/{vaultEntry}',          [ClientVaultController::class, 'destroy']);

    // Attachments
    Route::get('/{client}/attachments',                     [ClientAttachmentController::class, 'index']);
    Route::post('/{client}/attachments',                    [ClientAttachmentController::class, 'store']);
    Route::delete('/{client}/attachments/{attachment}',     [ClientAttachmentController::class, 'destroy']);

    // Address book
    Route::get('/{client}/addresses',              [ClientAddressController::class, 'index']);
    Route::post('/{client}/addresses',             [ClientAddressController::class, 'store']);
    Route::put('/{client}/addresses/{address}',    [ClientAddressController::class, 'update']);
    Route::delete('/{client}/addresses/{address}', [ClientAddressController::class, 'destroy']);

    // Recipients
    Route::get('/{client}/recipients',                [ClientRecipientController::class, 'index']);
    Route::post('/{client}/recipients',               [ClientRecipientController::class, 'store']);
    Route::put('/{client}/recipients/{recipient}',    [ClientRecipientController::class, 'update']);
    Route::delete('/{client}/recipients/{recipient}', [ClientRecipientController::class, 'destroy']);
});
