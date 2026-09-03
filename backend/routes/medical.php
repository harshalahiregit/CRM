<?php

use App\Http\Controllers\Api\Medical\DoctorPortalController;
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

        Route::get('/examinations',            [DoctorPortalController::class, 'examinations']);
        Route::get('/examinations/{medical}',  [DoctorPortalController::class, 'examination'])->whereNumber('medical');
        Route::get('/examinations/{medical}/certificate', [DoctorPortalController::class, 'certificate'])->whereNumber('medical');
    });
});

/* ── Doctor directory (admin) ────────────────────────────────────────────── */

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('medical/doctors')->group(function () {
    Route::get('/',          [MedicalDoctorController::class, 'index']);
    Route::post('/',         [MedicalDoctorController::class, 'store']);
    Route::put('/{doctor}',  [MedicalDoctorController::class, 'update'])->whereNumber('doctor');
    // Deactivates; never deletes — an issued certificate must keep its author.
    Route::delete('/{doctor}', [MedicalDoctorController::class, 'destroy'])->whereNumber('doctor');
});

/* ── Public verification (no auth — this is the point of the QR) ─────────── */

Route::get('public/medical/verify/{certificate}', [MedicalVerificationController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('certificate', '[A-Za-z0-9\-]+');
