<?php

use App\Http\Controllers\Api\OnboardingPortalController;
use Illuminate\Support\Facades\Route;

// ── Public candidate Onboarding portal (no auth — scoped by the {token}) ──────
Route::prefix('onboarding/{token}')->group(function () {
    Route::get('/',          [OnboardingPortalController::class, 'show']);
    Route::post('/submit',   [OnboardingPortalController::class, 'submit'])->middleware('throttle:20,1');
    Route::post('/documents', [OnboardingPortalController::class, 'uploadDocument'])->middleware('throttle:30,1');
});
