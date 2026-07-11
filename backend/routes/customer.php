<?php

use App\Http\Controllers\Api\Customer\ClientController;
use App\Http\Controllers\Api\Customer\ClientGroupController;
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
    Route::get('/',                    [ClientController::class, 'index']);
    Route::post('/',                   [ClientController::class, 'store']);
    Route::get('/{client}',            [ClientController::class, 'show']);
    Route::put('/{client}',            [ClientController::class, 'update']);
    Route::delete('/{client}',         [ClientController::class, 'destroy']);

    // Profile sub-resources (loop-in tabs)
    Route::get('/{client}/tax',     [ClientController::class, 'taxSummary']);
    Route::get('/{client}/tickets', [ClientController::class, 'tickets']);
});
