<?php

use App\Http\Controllers\Api\Medical\DoctorGeneralController;
use App\Http\Controllers\Api\Medical\DoctorPortalController;
use App\Http\Controllers\Api\Medical\GeneralMedicalAdminController;
use App\Http\Controllers\Api\Medical\MedicalDoctorController;
use App\Http\Controllers\Api\Medical\MedicalVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Medical module
|--------------------------------------------------------------------------
|
| Three surfaces, and they are deliberately separate:
|
|  - The DOCTOR PORTAL, where examinations are performed. One login serves both
|    vendor sides, so every route carries a {module} segment.
|  - The DOCTOR DIRECTORY, where an admin creates those logins.
|  - PUBLIC VERIFICATION, which is what the QR on a certificate opens — no auth,
|    and a deliberately thin answer.
|
| The register and the quality check live with their own module
| (routes/tpv.php, routes/purchase.php), because a certificate belongs to the
| register it was filed in.
*/

/* ── Doctor portal — the Internal Medical Flow ───────────────────────────── */

Route::middleware(['auth:sanctum', 'role:doctor'])->prefix('doctor')->group(function () {
    Route::get('/me',        [DoctorPortalController::class, 'me']);
    Route::put('/me',        [DoctorPortalController::class, 'updateProfile']);
    Route::get('/summary',   [DoctorPortalController::class, 'summary']);

    // {module} is tpv | purchase — a doctor picks the side, then the vendor,
    // then the worker. A side the doctor does not serve 404s.
    Route::prefix('{module}')->whereIn('module', ['tpv', 'purchase'])->group(function () {
        Route::get('/vendors',  [DoctorPortalController::class, 'vendors']);
        Route::get('/workers',  [DoctorPortalController::class, 'workers']);
        Route::get('/workers/{worker}', [DoctorPortalController::class, 'worker'])->whereNumber('worker');

        // The examination form. `is_reexam` in the payload makes it a re-test,
        // chained to the record it supersedes.
        Route::post('/workers/{worker}/examination', [DoctorPortalController::class, 'examine'])->whereNumber('worker');

        // What a ticked group of people have in common. A POST because the set
        // of ids is the request, and a session can be a hundred of them.
        Route::post('/group-findings', [DoctorPortalController::class, 'groupFindings']);

        Route::get('/examinations',            [DoctorPortalController::class, 'examinations']);
        Route::get('/examinations/{medical}',  [DoctorPortalController::class, 'examination'])->whereNumber('medical');
        Route::get('/examinations/{medical}/certificate', [DoctorPortalController::class, 'certificate'])->whereNumber('medical');
    });

    /* ── Everyone who is not a vendor worker ───────────────────────────── */
    //
    // The portal is meant to serve all personnel. The two vendor registers above
    // cover contractors; these three cover the internal team, a client's people,
    // and visitors signing in at the gate. They have no vendor above them, so
    // the flow is one flat searchable list rather than vendor-then-worker.
    Route::post('/visitors', [DoctorGeneralController::class, 'storeVisitor']);

    Route::prefix('{audience}')->whereIn('audience', ['internal', 'client', 'visitor'])->group(function () {
        Route::get('/people',            [DoctorGeneralController::class, 'subjects']);
        Route::get('/people/{subject}',  [DoctorGeneralController::class, 'subject'])->whereNumber('subject');
        Route::post('/people/{subject}/examination', [DoctorGeneralController::class, 'examine'])->whereNumber('subject');
        Route::post('/group-findings',   [DoctorGeneralController::class, 'groupFindings']);
        // These three had no way to read back what the doctor had filed, so
        // "My examinations" 404ed the moment the audience was switched.
        Route::get('/examinations',            [DoctorGeneralController::class, 'examinations']);
        Route::get('/examinations/{medical}',  [DoctorGeneralController::class, 'examination'])->whereNumber('medical');
        Route::get('/records/{medical}/certificate',  [DoctorGeneralController::class, 'certificate'])->whereNumber('medical');
    });
});

/* ── Doctor directory (admin) ────────────────────────────────────────────── */

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('medical/doctors')->group(function () {
    Route::get('/',          [MedicalDoctorController::class, 'index']);
    Route::post('/',         [MedicalDoctorController::class, 'store']);
    Route::put('/{doctor}',  [MedicalDoctorController::class, 'update'])->whereNumber('doctor');
    // A password set at creation is shown once and only hashed after that,
    // so without this an admin who mislaid it had no way back into the account.
    Route::post('/{doctor}/reset-password', [MedicalDoctorController::class, 'resetPassword'])->whereNumber('doctor');
    // Deactivates; never deletes — an issued certificate must keep its author.
    Route::delete('/{doctor}', [MedicalDoctorController::class, 'destroy'])->whereNumber('doctor');
});

/* ── The general register, for an admin ──────────────────────────────────── */
//
// Examinations of internal staff, client contacts and site visitors. The doctor
// portal has been writing these; until now nothing could read them back, which
// made the whole audience half a feature — recorded, and invisible.
//
// Admin-only: these are examinations of employees and named visitors. The two
// vendor registers keep their own module-scoped reviewers and are unaffected.

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('medical/general')->group(function () {
    Route::get('/',        [GeneralMedicalAdminController::class, 'index']);
    Route::get('/report',  [GeneralMedicalAdminController::class, 'report']);
    Route::get('/{medical}', [GeneralMedicalAdminController::class, 'show'])->whereNumber('medical');
    Route::get('/{medical}/certificate', [GeneralMedicalAdminController::class, 'certificate'])->whereNumber('medical');
});

/* ── Public verification (no auth — this is the point of the QR) ─────────── */

Route::get('public/medical/verify/{certificate}', [MedicalVerificationController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('certificate', '[A-Za-z0-9\-]+');
