<?php

use App\Http\Controllers\Api\Hr\CompanyApprovalController;
use App\Http\Controllers\Api\Hr\PublicClientTrackingController;
use App\Http\Controllers\Api\Hr\PublicHiringRequestController;
use App\Http\Controllers\Api\Hr\RecruitmentServicesController;
use Illuminate\Support\Facades\Route;

// Recruitment Services — isolated module. Nothing here modifies the existing
// existing HR/recruitment routes.

// Public (no-auth) endpoints — the token is the credential. Throttled to blunt
// token-probing and mass row-creation / notification spam.
Route::middleware('throttle:30,1')->group(function () {
    // External-company submission (company token).
    Route::get('/hiring-request/{token}',  [PublicHiringRequestController::class, 'show']);
    Route::post('/hiring-request/{token}', [PublicHiringRequestController::class, 'store']);

    // Client-tracking portal (Phase 2/3 — per-request tracking token).
    Route::get('/client-tracking/resume/{shareToken}', [PublicClientTrackingController::class, 'resume']);
    Route::get('/client-tracking/{token}',            [PublicClientTrackingController::class, 'show']);
    Route::post('/client-tracking/{token}/feedback',  [PublicClientTrackingController::class, 'feedback']);
    Route::post('/client-tracking/{token}/rating',    [PublicClientTrackingController::class, 'rating']);
});

// Internal (HR) — same auth guard as the rest of the HR module.
Route::middleware('auth:sanctum')->prefix('recruitment-services')->group(function () {
    Route::get('/dashboard', [RecruitmentServicesController::class, 'dashboard']);

    // Company Portal — HR approval of self-registered external companies.
    Route::get('/company-accounts/pending',            [CompanyApprovalController::class, 'pending']);
    Route::post('/company-accounts/{company}/approve', [CompanyApprovalController::class, 'approve']);
    Route::post('/company-accounts/{company}/reject',  [CompanyApprovalController::class, 'reject']);

    Route::get('/companies',            [RecruitmentServicesController::class, 'companies']);
    Route::post('/companies',           [RecruitmentServicesController::class, 'storeCompany']);
    Route::put('/companies/{company}',  [RecruitmentServicesController::class, 'updateCompany']);
    Route::delete('/companies/{company}', [RecruitmentServicesController::class, 'destroyCompany']);

    Route::get('/requests',                          [RecruitmentServicesController::class, 'requests']);
    Route::post('/requests',                         [RecruitmentServicesController::class, 'storeRequest']);
    Route::get('/requests/{hiringRequest}',          [RecruitmentServicesController::class, 'showRequest']);
    Route::put('/requests/{hiringRequest}',          [RecruitmentServicesController::class, 'updateRequest']);
    Route::post('/requests/{hiringRequest}/review',  [RecruitmentServicesController::class, 'review']);
    Route::post('/requests/{hiringRequest}/assign',  [RecruitmentServicesController::class, 'assign']);
    Route::post('/requests/{hiringRequest}/convert', [RecruitmentServicesController::class, 'convert']);

    // Phase 2 — client collaboration
    Route::get('/requests/{hiringRequest}/submittable-candidates', [RecruitmentServicesController::class, 'submittableCandidates']);
    Route::get('/requests/{hiringRequest}/submissions',            [RecruitmentServicesController::class, 'submissions']);
    Route::post('/requests/{hiringRequest}/submit-candidates',     [RecruitmentServicesController::class, 'submitCandidates']);
    Route::post('/requests/{hiringRequest}/notify-client',         [RecruitmentServicesController::class, 'notifyClient']);

    // Phase 3 — recruiter operations (aggregate views)
    Route::get('/recruiter/workspace',   [RecruitmentServicesController::class, 'workspace']);
    Route::get('/recruiter/dashboard',   [RecruitmentServicesController::class, 'recruiterDashboard']);
    Route::get('/recruiter/sla',         [RecruitmentServicesController::class, 'sla']);
    Route::get('/recruiter/performance', [RecruitmentServicesController::class, 'performance']);

    // Phase 3 — per-request: internal notes, client rating, resume sharing
    Route::get('/requests/{hiringRequest}/notes',          [RecruitmentServicesController::class, 'notes']);
    Route::post('/requests/{hiringRequest}/notes',         [RecruitmentServicesController::class, 'addNote']);
    Route::delete('/requests/{hiringRequest}/notes/{note}', [RecruitmentServicesController::class, 'deleteNote']);
    Route::get('/requests/{hiringRequest}/rating',         [RecruitmentServicesController::class, 'clientRating']);
    Route::get('/requests/{hiringRequest}/resume-shares',  [RecruitmentServicesController::class, 'resumeShares']);
    Route::post('/requests/{hiringRequest}/share-resume',  [RecruitmentServicesController::class, 'shareResume']);
});
