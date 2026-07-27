<?php

use App\Http\Controllers\Api\CareerPortalController;
use Illuminate\Support\Facades\Route;

// ── Public Career Portal (no auth — tenant resolved from the {tenantSlug}) ────
Route::prefix('careers/{tenantSlug}')->group(function () {
    Route::get('/',                    [CareerPortalController::class, 'tenant']);
    Route::get('/jobs',                [CareerPortalController::class, 'jobs']);
    Route::get('/jobs/{jobId}',        [CareerPortalController::class, 'job'])->whereNumber('jobId');
    Route::post('/jobs/{jobId}/apply', [CareerPortalController::class, 'apply'])
        ->whereNumber('jobId')
        ->middleware('throttle:15,1'); // basic anti-spam: 15 applications/min per IP

    // Candidate application tracking (public — matched by the applicant's own email/phone)
    Route::post('/jobs/{jobId}/status', [CareerPortalController::class, 'status'])
        ->whereNumber('jobId')->middleware('throttle:40,1');
    Route::post('/jobs/{jobId}/offer/respond', [CareerPortalController::class, 'respondOffer'])
        ->whereNumber('jobId')->middleware('throttle:10,1');
    Route::get('/jobs/{jobId}/offer/letter', [CareerPortalController::class, 'offerLetter'])
        ->whereNumber('jobId')->middleware('throttle:20,1');
});
