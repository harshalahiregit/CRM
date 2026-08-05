<?php

use App\Http\Controllers\Api\Hr\EmployeeOnboardingController;
use Illuminate\Support\Facades\Route;

/*
 * Employee Onboarding — isolated module. Authenticated only (no public token —
 * unlike candidate onboarding, this is a logged-in portal; future ESS is
 * authenticated employee login/SSO/OTP). Nothing here modifies the existing
 * HR / recruitment / candidate-onboarding routes. Permission is enforced inside
 * the controller (canManageOnboarding), matching the rest of the HR module.
 */
Route::middleware('auth:sanctum')->prefix('employee-onboarding')->group(function () {
    Route::get('/dashboard',           [EmployeeOnboardingController::class, 'dashboard']);
    Route::get('/eligible-employees',  [EmployeeOnboardingController::class, 'eligibleEmployees']);

    Route::get('/',                    [EmployeeOnboardingController::class, 'index']);
    Route::post('/',                   [EmployeeOnboardingController::class, 'store']);
    Route::get('/{onboarding}',        [EmployeeOnboardingController::class, 'show']);

    // 1:1 sections (personal|contact|address|emergency|bank|compliance)
    Route::patch('/{onboarding}/section/{section}', [EmployeeOnboardingController::class, 'saveSection']);

    // Education (1:many)
    Route::post('/{onboarding}/education',          [EmployeeOnboardingController::class, 'storeEducation']);
    Route::put('/{onboarding}/education/{id}',      [EmployeeOnboardingController::class, 'updateEducation']);
    Route::delete('/{onboarding}/education/{id}',   [EmployeeOnboardingController::class, 'destroyEducation']);

    // Employment History (1:many)
    Route::post('/{onboarding}/experience',         [EmployeeOnboardingController::class, 'storeExperience']);
    Route::put('/{onboarding}/experience/{id}',     [EmployeeOnboardingController::class, 'updateExperience']);
    Route::delete('/{onboarding}/experience/{id}',  [EmployeeOnboardingController::class, 'destroyExperience']);

    // Family (1:many)
    Route::post('/{onboarding}/family',             [EmployeeOnboardingController::class, 'storeFamily']);
    Route::put('/{onboarding}/family/{id}',         [EmployeeOnboardingController::class, 'updateFamily']);
    Route::delete('/{onboarding}/family/{id}',      [EmployeeOnboardingController::class, 'destroyFamily']);

    // Assets Allocation (1:many)

    // HR verification of one section (Approve / Reject / Request Correction)
    Route::patch('/{onboarding}/section/{section}/verify', [EmployeeOnboardingController::class, 'verifySection']);

    // Professional references (1:many)
    Route::post('/{onboarding}/references',        [EmployeeOnboardingController::class, 'storeReference']);
    Route::put('/{onboarding}/references/{id}',    [EmployeeOnboardingController::class, 'updateReference']);
    Route::delete('/{onboarding}/references/{id}', [EmployeeOnboardingController::class, 'destroyReference']);

    // Orientation feedback (1:1) — available only after the employee has joined
    Route::patch('/{onboarding}/orientation-feedback', [EmployeeOnboardingController::class, 'saveOrientationFeedback']);

    // Checklist tasks (Orientation/Training/IT/HR/Manager)
    Route::patch('/{onboarding}/tasks/{task}',      [EmployeeOnboardingController::class, 'updateTask']);

    // Progress tracker stage
    Route::patch('/{onboarding}/stage',             [EmployeeOnboardingController::class, 'setStage']);

    // Background Verification (Phase 4)
    Route::patch('/{onboarding}/background-verification', [EmployeeOnboardingController::class, 'saveBackgroundVerification']);

    // Approvals + Activation (Phase 4)
    Route::patch('/{onboarding}/hr-approve',        [EmployeeOnboardingController::class, 'hrApprove']);
    Route::patch('/{onboarding}/manager-approve',   [EmployeeOnboardingController::class, 'managerApprove']);
    Route::patch('/{onboarding}/confirm-joining',   [EmployeeOnboardingController::class, 'confirmJoining']);
});
