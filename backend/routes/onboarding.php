<?php

use App\Http\Controllers\Api\OnboardingOfferPortalController;
use App\Http\Controllers\Api\OnboardingPortalController;
use Illuminate\Support\Facades\Route;

// ── Public candidate Onboarding portal (no auth — scoped by the {token}) ──────
Route::prefix('onboarding/{token}')->group(function () {
    Route::get('/',          [OnboardingPortalController::class, 'show']);
    Route::post('/submit',   [OnboardingPortalController::class, 'submit'])->middleware('throttle:20,1');
    Route::post('/documents', [OnboardingPortalController::class, 'uploadDocument'])->middleware('throttle:30,1');

    // Onboarding form (EMPLOYEES ONBOARDING FORM). Same tokenised scoping as above;
    // every write delegates to the existing EmployeeOnboardingService with a null
    // actor, so section status, progress and audit all reuse the HR engine.
    Route::patch('/form/section/{section}',     [OnboardingPortalController::class, 'saveFormSection'])->middleware('throttle:60,1');
    Route::post('/form/{collection}',           [OnboardingPortalController::class, 'saveFormChild'])->middleware('throttle:60,1');
    Route::delete('/form/{collection}/{id}',    [OnboardingPortalController::class, 'deleteFormChild'])->middleware('throttle:60,1');

    // ── The Offer tab, on this same onboarding credential ─────────────────
    // The candidate used to be handed the offer's own raw token to reach these;
    // now the link they already arrived with is the only credential in play.
    // Thin adapter over the identical OfferPortalController actions, so the
    // throttles mirror routes/offer.php rather than inventing new ones.
    Route::get('/offer',          [OnboardingOfferPortalController::class, 'show']);
    Route::get('/offer/letter',   [OnboardingOfferPortalController::class, 'letter']);
    Route::post('/offer/accept',  [OnboardingOfferPortalController::class, 'accept'])->middleware('throttle:20,1');
    Route::post('/offer/decline', [OnboardingOfferPortalController::class, 'decline'])->middleware('throttle:20,1');
    Route::post('/offer/clarify', [OnboardingOfferPortalController::class, 'clarify'])->middleware('throttle:20,1');
    Route::post('/offer/tasks',   [OnboardingOfferPortalController::class, 'task'])->middleware('throttle:40,1');
});
