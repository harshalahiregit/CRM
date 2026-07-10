<?php

use App\Http\Controllers\Api\Sales\PublicProposalController;
use Illuminate\Support\Facades\Route;

// ── Public (unauthenticated) client-facing routes ───────────────────────
Route::prefix('public')->group(function () {
    Route::get('/proposals/{token}',        [PublicProposalController::class, 'show']);
    Route::get('/proposals/{token}/track',  [PublicProposalController::class, 'trackOpen']);
});
