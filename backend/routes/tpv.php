<?php

use App\Http\Controllers\Api\Tpv\GateScanController;
use App\Http\Controllers\Api\Tpv\TpvDashboardController;
use App\Http\Controllers\Api\Tpv\TpvGateLogController;
use App\Http\Controllers\Api\Tpv\TpvOnboardingController;
use App\Http\Controllers\Api\Tpv\TpvSafetyStrikeController;
use App\Http\Controllers\Api\Tpv\TpvWorkerController;
use App\Http\Controllers\Api\Tpv\VendorDocumentController;
use Illuminate\Support\Facades\Route;

// ── PUBLIC — the site gate ──────────────────────────────────────────────
// No auth by design: a guard scans a badge with a phone. The 48-char QR token
// is the bearer credential — possession of the card authorises seeing the pass.
// Rate-limited per IP, and every scan is written to the gate log.
Route::prefix('scan')->middleware('throttle:60,1')->group(function () {
    // Read-only: shows the pass card, never mutates attendance.
    Route::get('/{token}',            [GateScanController::class, 'scan']);
    // Explicit actions, so a verification scan can't accidentally sign someone out.
    Route::post('/{token}/check-in',  [GateScanController::class, 'checkIn']);
    Route::post('/{token}/check-out', [GateScanController::class, 'checkOut']);
})->where(['token' => '[A-Za-z0-9]{20,64}']);

// ── TPV Module (Sanctum + role:admin,staff) ─────────────────────────────
// Vendor/workforce data must never cross tenants or reach a client login, so
// this group is role-gated from day one (ARCHITECTURE-PRIMER §1).
//
// The vendor-facing side of the wizard (a `third_party_vendor` login editing
// only their own onboarding) is a separate portal group, not built yet — it
// needs a per-record ownership check, not a blanket role gate.
// NOTE: both middleware must go in ONE ->middleware([...]) call — chaining a
// second ->middleware() replaces the first and silently drops auth:sanctum.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('tpv')->group(function () {

    // Dashboard — read-only aggregation across the whole module.
    Route::get('/dashboard',                          [TpvDashboardController::class, 'index']);

    // Onboarding — 6-step wizard over the shared vendor master
    Route::get('/onboarding/stats',                   [TpvOnboardingController::class, 'stats']);
    Route::get('/onboarding',                         [TpvOnboardingController::class, 'index']);
    Route::post('/onboarding',                        [TpvOnboardingController::class, 'store']);
    Route::get('/onboarding/{onboarding}',            [TpvOnboardingController::class, 'show']);
    Route::get('/onboarding/{onboarding}/progress',   [TpvOnboardingController::class, 'progress']);
    Route::post('/onboarding/{onboarding}/profile',   [TpvOnboardingController::class, 'saveProfile']);
    Route::patch('/onboarding/{onboarding}/step',     [TpvOnboardingController::class, 'setStep']);
    Route::post('/onboarding/{onboarding}/submit',    [TpvOnboardingController::class, 'submit']);
    Route::delete('/onboarding/{onboarding}',         [TpvOnboardingController::class, 'destroy']);

    // Statutory-document validation — upload against the required matrix, resubmit
    // on rejection, download. Reviewing (approve/reject) is admin-only, below.
    Route::get('/vendors/{vendor}/documents',         [VendorDocumentController::class, 'checklist']);
    Route::post('/vendors/{vendor}/documents',        [VendorDocumentController::class, 'upload']);
    Route::get('/documents/{document}/download',      [VendorDocumentController::class, 'download']);
    Route::post('/documents/{document}/resubmit',     [VendorDocumentController::class, 'resubmit']);
    Route::delete('/documents/{document}',            [VendorDocumentController::class, 'destroy']);

    // Workforce — 5-step registration (profile → medical → induction → PPE → badge).
    // Recording the steps is operational; issuing the badge is admin-only, below.
    Route::get('/workers/stats',                          [TpvWorkerController::class, 'stats']);
    Route::get('/workers',                                [TpvWorkerController::class, 'index']);
    Route::post('/workers',                               [TpvWorkerController::class, 'store']);
    Route::get('/workers/{worker}',                       [TpvWorkerController::class, 'show']);
    Route::get('/workers/{worker}/progress',              [TpvWorkerController::class, 'progress']);
    Route::put('/workers/{worker}',                       [TpvWorkerController::class, 'update']);
    Route::delete('/workers/{worker}',                    [TpvWorkerController::class, 'destroy']); // Draft only
    // Step records
    Route::post('/workers/{worker}/medical',              [TpvWorkerController::class, 'saveMedical']);
    Route::post('/workers/{worker}/induction',            [TpvWorkerController::class, 'saveInduction']);
    Route::post('/workers/{worker}/ppe',                  [TpvWorkerController::class, 'issuePpe']);
    Route::delete('/workers/{worker}/ppe/{ppeIssue}',     [TpvWorkerController::class, 'removePpe']);

    // Gate reads — attendance roster, per-worker history, and the gate log.
    Route::get('/gate/stats',                             [TpvGateLogController::class, 'stats']);
    Route::get('/gate-log',                               [TpvGateLogController::class, 'log']);
    Route::get('/attendance',                             [TpvGateLogController::class, 'roster']);
    Route::get('/workers/{worker}/attendance',            [TpvGateLogController::class, 'workerAttendance']);

    // Strike ledger — reading is operational; issuing/voiding is admin, below.
    Route::get('/strikes/stats',                          [TpvSafetyStrikeController::class, 'stats']);
    Route::get('/strikes',                                [TpvSafetyStrikeController::class, 'index']);
    Route::get('/workers/{worker}/strikes',               [TpvSafetyStrikeController::class, 'forWorker']);
});

// Admin approval — activates the vendor for site access.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('tpv')->group(function () {

    Route::post('/onboarding/{onboarding}/approve',   [TpvOnboardingController::class, 'approve']);
    Route::post('/onboarding/{onboarding}/resubmit',  [TpvOnboardingController::class, 'requestResubmit']);

    // Approving/rejecting a statutory document is an admin gate.
    Route::post('/documents/{document}/review',       [VendorDocumentController::class, 'review']);

    // Granting or revoking site access is admin authority.
    Route::post('/workers/{worker}/activate',         [TpvWorkerController::class, 'activate']);
    // Revealing the badge QR for (re)printing — audited on every disclosure.
    Route::get('/workers/{worker}/badge',             [TpvWorkerController::class, 'badge']);
    Route::post('/workers/{worker}/suspend',          [TpvWorkerController::class, 'suspend']);
    Route::post('/workers/{worker}/reinstate',        [TpvWorkerController::class, 'reinstate']);
    Route::post('/workers/{worker}/terminate',        [TpvWorkerController::class, 'terminate']);

    // Safety strikes are admin authority: the third strike (or one Critical)
    // terminates site access, so issuing one can revoke access. Letting staff
    // issue them would route around the admin-only terminate gate.
    Route::post('/workers/{worker}/strikes',          [TpvSafetyStrikeController::class, 'store']);
    Route::post('/strikes/{strike}/void',             [TpvSafetyStrikeController::class, 'void']);
});
