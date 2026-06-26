<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Hr\HRDashboardController;
use App\Http\Controllers\Api\Hr\ManpowerRequestController;
use App\Http\Controllers\Api\Hr\JobPostingController;
use App\Http\Controllers\Api\Hr\CandidateController;
use App\Http\Controllers\Api\Hr\InterviewController;
use App\Http\Controllers\Api\Hr\OfferController;
use App\Http\Controllers\Api\Hr\OnboardingController;
use App\Http\Controllers\Api\Hr\EmployeeController;
use Illuminate\Support\Facades\Route;

// ── Public Auth Routes ──────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/login',           [AuthController::class, 'login']);
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/register/vendor', [AuthController::class, 'registerVendor']);
    Route::post('/register/tpv',    [AuthController::class, 'registerTPV']);
    Route::post('/register/client', [AuthController::class, 'registerClient']);
});

// ── Protected Routes (Sanctum) ──────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::get('/dashboard',    [DashboardController::class, 'index']);

    // ── HR Module Routes ────────────────────────────────────────────────
    Route::prefix('hr')->group(function () {

        // Dashboard
        Route::get('/dashboard', [HRDashboardController::class, 'index']);

        // Manpower Requests
        Route::get('/manpower-requests',                    [ManpowerRequestController::class, 'index']);
        Route::get('/manpower-requests/pending-count',      [ManpowerRequestController::class, 'pendingCount']);
        Route::post('/manpower-requests',                   [ManpowerRequestController::class, 'store']);
        Route::get('/manpower-requests/{manpowerRequest}',  [ManpowerRequestController::class, 'show']);
        Route::patch('/manpower-requests/{manpowerRequest}/status', [ManpowerRequestController::class, 'updateStatus'])
            ->middleware('role:hiring_manager,admin'); // Only managers can approve/reject
        Route::patch('/manpower-requests/{manpowerRequest}/assign-manager', [ManpowerRequestController::class, 'assignManager'])
            ->middleware('role:admin,hr_executive'); // Only admin/HR can assign
        Route::delete('/manpower-requests/{manpowerRequest}',       [ManpowerRequestController::class, 'destroy']);

        // Job Postings
        Route::get('/jobs',                         [JobPostingController::class, 'index']);
        Route::post('/jobs',                        [JobPostingController::class, 'store']);
        Route::get('/jobs/{jobPosting}',            [JobPostingController::class, 'show']);
        Route::put('/jobs/{jobPosting}',            [JobPostingController::class, 'update']);
        Route::patch('/jobs/{jobPosting}/status',   [JobPostingController::class, 'updateStatus']);
        Route::delete('/jobs/{jobPosting}',         [JobPostingController::class, 'destroy']);

        // Candidates
        Route::get('/candidates',                           [CandidateController::class, 'index']);
        Route::post('/candidates',                          [CandidateController::class, 'store']);
        Route::post('/candidates/linkedin-parse',           [CandidateController::class, 'linkedinParse']);
        Route::get('/candidates/{candidate}',               [CandidateController::class, 'show']);
        Route::put('/candidates/{candidate}',               [CandidateController::class, 'update']);
        Route::patch('/candidates/{candidate}/stage',       [CandidateController::class, 'updateStage']);
        Route::patch('/candidates/{candidate}/decision',    [CandidateController::class, 'updateDecision']);
        Route::delete('/candidates/{candidate}',            [CandidateController::class, 'destroy']);

        // Interviews
        Route::get('/interviews',                               [InterviewController::class, 'index']);
        Route::post('/interviews',                              [InterviewController::class, 'store']);
        Route::get('/interviews/{interviewRound}',              [InterviewController::class, 'show']);
        Route::patch('/interviews/{interviewRound}/feedback',   [InterviewController::class, 'recordFeedback']);
        Route::post('/interviews/{interviewRound}/meet-link',   [InterviewController::class, 'generateMeetLink']);
        Route::post('/interviews/{interviewRound}/notify',      [InterviewController::class, 'sendNotification']);
        Route::delete('/interviews/{interviewRound}',           [InterviewController::class, 'destroy']);

        // Offers
        Route::get('/offers',                           [OfferController::class, 'index']);
        Route::post('/offers',                          [OfferController::class, 'store']);
        Route::get('/offers/{offer}',                   [OfferController::class, 'show']);
        Route::patch('/offers/{offer}/send',            [OfferController::class, 'send']);
        Route::patch('/offers/{offer}/status',          [OfferController::class, 'updateStatus']);
        Route::delete('/offers/{offer}',                [OfferController::class, 'destroy']);

        // Onboarding
        Route::get('/onboarding',                           [OnboardingController::class, 'index']);
        Route::post('/onboarding',                          [OnboardingController::class, 'store']);
        Route::get('/onboarding/{onboarding}',              [OnboardingController::class, 'show']);
        Route::patch('/onboarding/{onboarding}/step',       [OnboardingController::class, 'toggleStep']);
        Route::delete('/onboarding/{onboarding}',           [OnboardingController::class, 'destroy']);

        // Employees
        Route::get('/employees/stats',          [EmployeeController::class, 'stats']);
        Route::get('/employees',                [EmployeeController::class, 'index']);
        Route::post('/employees',               [EmployeeController::class, 'store']);
        Route::get('/employees/{employee}',     [EmployeeController::class, 'show']);
        Route::put('/employees/{employee}',     [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}',  [EmployeeController::class, 'destroy']);
    });
});

