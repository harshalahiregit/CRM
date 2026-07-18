<?php

use App\Http\Controllers\Api\Compliance\ComplianceChecklistController;
use App\Http\Controllers\Api\Compliance\ComplianceTemplateController;
use App\Http\Controllers\Api\Compliance\PublicChecklistController;
use Illuminate\Support\Facades\Route;

// ── PUBLIC — token fill-in ──────────────────────────────────────────────
// No auth by design (brief §6): a checklist is often filled by a vendor's site
// supervisor who has no CRM login. The 48-char token is the bearer credential.
// Rate-limited per IP; the service refuses writes unless the checklist is still
// Assigned, and clears the token once it closes.
Route::prefix('checklists/fill')->middleware('throttle:60,1')->group(function () {
    Route::get('/{token}',        [PublicChecklistController::class, 'show']);
    Route::post('/{token}/save',  [PublicChecklistController::class, 'save']);
    Route::post('/{token}',       [PublicChecklistController::class, 'submit']);
})->where(['token' => '[A-Za-z0-9]{20,64}']);

// ── Compliance engine (Sanctum + role:admin,staff) ──────────────────────
// A generic engine, not a TPV one — checklists attach polymorphically to any
// allowlisted subject (see Support\Compliance\ChecklistSubject), so HR's exit
// checklists will mount here too rather than growing a second engine.
//
// NOTE: both middleware must go in ONE ->middleware([...]) call — chaining a
// second ->middleware() replaces the first and silently drops auth:sanctum.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('compliance')->group(function () {

    // Templates — reading is operational; authoring is admin-only, below.
    Route::get('/templates/meta',        [ComplianceTemplateController::class, 'meta']);
    Route::get('/templates',             [ComplianceTemplateController::class, 'index']);
    Route::get('/templates/{template}',  [ComplianceTemplateController::class, 'show']);

    // Checklists — issuing and filling are day-to-day operational work.
    Route::get('/checklists/stats',            [ComplianceChecklistController::class, 'stats']);
    Route::get('/checklists',                  [ComplianceChecklistController::class, 'index']);
    Route::post('/checklists',                 [ComplianceChecklistController::class, 'store']);
    Route::get('/checklists/{checklist}',      [ComplianceChecklistController::class, 'show']);
    Route::patch('/checklists/{checklist}/responses', [ComplianceChecklistController::class, 'saveResponses']);
    Route::post('/checklists/{checklist}/submit',     [ComplianceChecklistController::class, 'submit']);

    // Manager tier — the first signature over a submitted checklist.
    Route::post('/checklists/{checklist}/manager-sign', [ComplianceChecklistController::class, 'managerSign']);
});

// ── Admin-only ──────────────────────────────────────────────────────────
// Template authoring defines the scoring rules and the risk thresholds that
// decide whether a vendor is safe to work — that is the same privilege level as
// approving an onboarding, not the same as filling a form in.
//
// The head tier lives here for the same reason it does in TPV: the final
// signature is the one that closes the record.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('compliance')->group(function () {
    Route::post('/templates',                    [ComplianceTemplateController::class, 'store']);
    Route::put('/templates/{template}',          [ComplianceTemplateController::class, 'update']);
    Route::post('/templates/{template}/activate', [ComplianceTemplateController::class, 'activate']);
    Route::post('/templates/{template}/archive',  [ComplianceTemplateController::class, 'archive']);
    Route::post('/templates/{template}/clone',    [ComplianceTemplateController::class, 'clone']);
    Route::delete('/templates/{template}',        [ComplianceTemplateController::class, 'destroy']);

    Route::post('/checklists/{checklist}/head-sign', [ComplianceChecklistController::class, 'headSign']);
    Route::post('/checklists/{checklist}/reopen',    [ComplianceChecklistController::class, 'reopen']);
});
