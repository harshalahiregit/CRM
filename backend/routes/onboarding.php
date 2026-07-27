<?php

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
});
