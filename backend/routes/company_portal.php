<?php

use App\Http\Controllers\Api\CompanyPortal\CompanyAdminController;
use App\Http\Controllers\Api\CompanyPortal\CompanyPortalController;
use Illuminate\Support\Facades\Route;

/*
 | External Company Portal — authenticated, company-scoped self-service.
 |
 | Both middleware in one call: chaining a second ->middleware() would drop
 | auth:sanctum. The company.portal gate resolves the ambient company from the
 | user; no company id ever appears in a URL. Sprint 1 = identity + dashboard.
 */
Route::middleware(['auth:sanctum', 'company.portal'])->prefix('company-portal')->group(function () {
    Route::get('/me',        [CompanyPortalController::class, 'me']);
    Route::get('/dashboard', [CompanyPortalController::class, 'dashboard']);
    Route::get('/meta',      [CompanyPortalController::class, 'meta']);
    Route::get('/logo',      [CompanyPortalController::class, 'logo']);

    // Hiring requests (Sprint 2)
    Route::get('/hiring-requests',                    [CompanyPortalController::class, 'hiringRequests']);
    Route::post('/hiring-requests',                   [CompanyPortalController::class, 'storeRequest']);
    Route::get('/hiring-requests/{hiringRequest}',    [CompanyPortalController::class, 'showRequest']);
    Route::put('/hiring-requests/{hiringRequest}',    [CompanyPortalController::class, 'updateRequest']);
    Route::post('/hiring-requests/{hiringRequest}/submit', [CompanyPortalController::class, 'submitRequest']);

    // Messages
    Route::get('/hiring-requests/{hiringRequest}/messages',  [CompanyPortalController::class, 'messages']);
    Route::post('/hiring-requests/{hiringRequest}/messages', [CompanyPortalController::class, 'postMessage']);

    // Documents
    Route::get('/hiring-requests/{hiringRequest}/documents',  [CompanyPortalController::class, 'documents']);
    Route::post('/hiring-requests/{hiringRequest}/documents', [CompanyPortalController::class, 'uploadDocument']);
    Route::get('/hiring-requests/{hiringRequest}/documents/{document}/download', [CompanyPortalController::class, 'downloadDocument']);
    Route::delete('/hiring-requests/{hiringRequest}/documents/{document}', [CompanyPortalController::class, 'deleteDocument']);

    // Candidates (Sprint 3)
    Route::get('/hiring-requests/{hiringRequest}/candidates',              [CompanyPortalController::class, 'candidates']);
    Route::get('/hiring-requests/{hiringRequest}/candidates/{submission}', [CompanyPortalController::class, 'candidateShow']);
    Route::post('/hiring-requests/{hiringRequest}/candidates/{submission}/review',  [CompanyPortalController::class, 'reviewCandidate']);
    Route::get('/hiring-requests/{hiringRequest}/candidates/{submission}/resume',   [CompanyPortalController::class, 'candidateResume']);
    Route::post('/hiring-requests/{hiringRequest}/candidates/{submission}/share-resume', [CompanyPortalController::class, 'shareCandidateResume']);
    Route::get('/hiring-requests/{hiringRequest}/candidates/{submission}/comments',  [CompanyPortalController::class, 'candidateComments']);
    Route::post('/hiring-requests/{hiringRequest}/candidates/{submission}/comments', [CompanyPortalController::class, 'postCandidateComment']);

    // Interviews (Sprint 4)
    Route::get('/hiring-requests/{hiringRequest}/interviews',                     [CompanyPortalController::class, 'interviews']);
    Route::post('/hiring-requests/{hiringRequest}/interviews/{interview}/action', [CompanyPortalController::class, 'interviewAction']);
    Route::get('/hiring-requests/{hiringRequest}/interviews/{interview}/invite',  [CompanyPortalController::class, 'interviewInvite']);

    // Offers (Sprint 4)
    Route::get('/hiring-requests/{hiringRequest}/offers',                     [CompanyPortalController::class, 'offers']);
    Route::post('/hiring-requests/{hiringRequest}/offers/{offer}/decision',   [CompanyPortalController::class, 'offerDecision']);
    Route::get('/hiring-requests/{hiringRequest}/offers/{offer}/download',    [CompanyPortalController::class, 'offerDownload']);

    // Company administration (Sprint 5)
    Route::get('/admin/profile',        [CompanyAdminController::class, 'profile']);
    Route::put('/admin/profile',        [CompanyAdminController::class, 'updateProfile']);
    Route::post('/admin/logo',          [CompanyAdminController::class, 'uploadLogo']);
    Route::put('/admin/branding',       [CompanyAdminController::class, 'updateBranding']);
    Route::put('/admin/notifications',  [CompanyAdminController::class, 'updateNotifications']);
    Route::get('/admin/users',          [CompanyAdminController::class, 'users']);
    Route::post('/admin/users',         [CompanyAdminController::class, 'createUser']);
    Route::post('/admin/users/{user}/role',           [CompanyAdminController::class, 'assignRole']);
    Route::post('/admin/users/{user}/status',         [CompanyAdminController::class, 'setStatus']);
    Route::post('/admin/users/{user}/reset-password', [CompanyAdminController::class, 'resetUserPassword']);
    Route::delete('/admin/users/{user}',[CompanyAdminController::class, 'removeUser']);
    Route::get('/admin/reports',        [CompanyAdminController::class, 'reports']);
    Route::get('/admin/activity',       [CompanyAdminController::class, 'activity']);
    Route::get('/admin/security',       [CompanyAdminController::class, 'sessions']);
    Route::post('/admin/change-password', [CompanyAdminController::class, 'changePassword']);
});
