<?php

use App\Http\Controllers\Api\OfferPortalController;
use Illuminate\Support\Facades\Route;

// ── Public candidate Offer Letter portal (no auth — scoped by the {token}) ────
Route::prefix('offer/{token}')->group(function () {
    Route::get('/',         [OfferPortalController::class, 'show']);
    Route::get('/letter',   [OfferPortalController::class, 'letter']);
    Route::post('/accept',  [OfferPortalController::class, 'accept'])->middleware('throttle:20,1');
    Route::post('/decline', [OfferPortalController::class, 'decline'])->middleware('throttle:20,1');
    Route::post('/clarify', [OfferPortalController::class, 'clarify'])->middleware('throttle:20,1');
    Route::post('/tasks',   [OfferPortalController::class, 'task'])->middleware('throttle:40,1');
});
