<?php

use App\Http\Controllers\Api\Sales\PublicContractController;
use App\Http\Controllers\Api\Sales\PublicProposalController;
use App\Http\Controllers\Api\Sales\PublicWebToLeadController;
use Illuminate\Support\Facades\Route;

// ── Public (unauthenticated) client-facing routes ───────────────────────
Route::prefix('public')->group(function () {
    Route::get('/proposals/{token}',        [PublicProposalController::class, 'show'])
        ->middleware('throttle:30,1');
    Route::get('/proposals/{token}/track',  [PublicProposalController::class, 'trackOpen']);
    Route::post('/proposals/{token}/request-otp', [PublicProposalController::class, 'requestOtp'])
        ->middleware('throttle:3,10');
    Route::post('/proposals/{token}/verify-otp',  [PublicProposalController::class, 'verifyOtp'])
        ->middleware('throttle:10,10');
    Route::post('/proposals/{token}/accept',  [PublicProposalController::class, 'accept'])
        ->middleware('throttle:10,1');
    Route::post('/proposals/{token}/decline', [PublicProposalController::class, 'decline'])
        ->middleware('throttle:10,1');

    // Contract portal (view + client signing; QR verification target)
    Route::get('/contracts/{token}',       [PublicContractController::class, 'show'])
        ->middleware('throttle:30,1');
    Route::post('/contracts/{token}/sign', [PublicContractController::class, 'sign'])
        ->middleware('throttle:10,10');
    Route::post('/contracts/{token}/comments', [PublicContractController::class, 'comment'])
        ->middleware('throttle:10,10');
    Route::get('/contracts/{token}/pdf', [PublicContractController::class, 'pdf'])
        ->middleware('throttle:10,1');

    // Web-to-Lead public form (throttled to curb spam submissions)
    Route::get('/web-to-lead/{formKey}',    [PublicWebToLeadController::class, 'show']);
    Route::post('/web-to-lead/{formKey}',   [PublicWebToLeadController::class, 'submit'])
        ->middleware('throttle:10,1');
});
