<?php

use App\Http\Controllers\Api\Sales\PublicProposalController;
use App\Http\Controllers\Api\Sales\PublicWebToLeadController;
use Illuminate\Support\Facades\Route;

// ── Public (unauthenticated) client-facing routes ───────────────────────
Route::prefix('public')->group(function () {
    Route::get('/proposals/{token}',        [PublicProposalController::class, 'show']);
    Route::get('/proposals/{token}/track',  [PublicProposalController::class, 'trackOpen']);

    // Web-to-Lead public form (throttled to curb spam submissions)
    Route::get('/web-to-lead/{formKey}',    [PublicWebToLeadController::class, 'show']);
    Route::post('/web-to-lead/{formKey}',   [PublicWebToLeadController::class, 'submit'])
        ->middleware('throttle:10,1');
});
