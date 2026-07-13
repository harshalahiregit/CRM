<?php

use App\Http\Controllers\Api\Hr\HRDashboardController;
use App\Http\Controllers\Api\Hr\ManpowerRequestController;
use App\Http\Controllers\Api\Hr\JobPostingController;
use App\Http\Controllers\Api\Hr\CandidateController;
use App\Http\Controllers\Api\Hr\CandidateNoteController;
use App\Http\Controllers\Api\Hr\CandidateDocumentController;
use App\Http\Controllers\Api\Hr\ResumeController;
use App\Http\Controllers\Api\Hr\InterviewController;
use App\Http\Controllers\Api\Hr\OfferController;
use App\Http\Controllers\Api\Hr\OnboardingController;
use App\Http\Controllers\Api\Hr\EmployeeController;
use Illuminate\Support\Facades\Route;

// ── HR Module Routes (Sanctum) ──────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('hr')->group(function () {

    // Dashboard
    Route::get('/dashboard', [HRDashboardController::class, 'index']);

    // Manpower Requests — L1/L2 Approval Workflow → HR Queue → JD → Job Posting
    Route::get('/manpower-requests',                            [ManpowerRequestController::class, 'index']);
    Route::get('/manpower-requests/stats',                      [ManpowerRequestController::class, 'stats']);
    Route::get('/manpower-requests/queue',                      [ManpowerRequestController::class, 'queue']);
    Route::get('/manpower-requests/pending-count',              [ManpowerRequestController::class, 'pendingCount']);
    Route::get('/manpower-requests/pending-approvals',          [ManpowerRequestController::class, 'pendingApprovals']);
    Route::post('/manpower-requests',                           [ManpowerRequestController::class, 'store']);
    Route::get('/manpower-requests/{manpowerRequest}',          [ManpowerRequestController::class, 'show']);
    Route::put('/manpower-requests/{manpowerRequest}',          [ManpowerRequestController::class, 'update']);
    Route::delete('/manpower-requests/{manpowerRequest}',       [ManpowerRequestController::class, 'destroy']);
    // L1/L2 Approval actions
    Route::post('/manpower-requests/{manpowerRequest}/submit',      [ManpowerRequestController::class, 'submit']);
    Route::post('/manpower-requests/{manpowerRequest}/approve-l1',  [ManpowerRequestController::class, 'approveL1']);
    Route::post('/manpower-requests/{manpowerRequest}/reject-l1',   [ManpowerRequestController::class, 'rejectL1']);
    Route::post('/manpower-requests/{manpowerRequest}/approve-l2',  [ManpowerRequestController::class, 'approveL2']);
    Route::post('/manpower-requests/{manpowerRequest}/reject-l2',   [ManpowerRequestController::class, 'rejectL2']);
    Route::post('/manpower-requests/{manpowerRequest}/send-back',   [ManpowerRequestController::class, 'sendBack']);
    // HR Queue actions (post-approval): convert → publish → hiring → close
    Route::post('/manpower-requests/{manpowerRequest}/convert-to-jd', [ManpowerRequestController::class, 'convertToJd']);
    Route::post('/manpower-requests/{manpowerRequest}/publish',       [ManpowerRequestController::class, 'publish']);
    Route::post('/manpower-requests/{manpowerRequest}/start-hiring',  [ManpowerRequestController::class, 'startHiring']);
    Route::post('/manpower-requests/{manpowerRequest}/close',         [ManpowerRequestController::class, 'close']);
    Route::patch('/manpower-requests/{manpowerRequest}/assign-manager', [ManpowerRequestController::class, 'assignManager']);

    // Job Postings — Recruitment Workspace
    Route::get('/jobs',                            [JobPostingController::class, 'index']);
    Route::get('/jobs/stats',                      [JobPostingController::class, 'stats']);
    Route::get('/jobs/channels',                   [JobPostingController::class, 'channels']);
    Route::post('/jobs/bulk',                      [JobPostingController::class, 'bulk']);
    Route::post('/jobs',                           [JobPostingController::class, 'store']);
    Route::get('/jobs/{jobPosting}',               [JobPostingController::class, 'show']);
    Route::put('/jobs/{jobPosting}',               [JobPostingController::class, 'update']);
    Route::patch('/jobs/{jobPosting}/status',      [JobPostingController::class, 'updateStatus']);
    Route::patch('/jobs/{jobPosting}/external-id', [JobPostingController::class, 'updateExternalId']);
    Route::delete('/jobs/{jobPosting}',            [JobPostingController::class, 'destroy']);
    // Lifecycle actions
    Route::post('/jobs/{jobPosting}/publish',      [JobPostingController::class, 'publish']);
    Route::post('/jobs/{jobPosting}/unpublish',    [JobPostingController::class, 'unpublish']);
    Route::post('/jobs/{jobPosting}/pause',        [JobPostingController::class, 'pause']);
    Route::post('/jobs/{jobPosting}/close',        [JobPostingController::class, 'close']);
    Route::post('/jobs/{jobPosting}/cancel',       [JobPostingController::class, 'cancel']);
    Route::post('/jobs/{jobPosting}/duplicate',    [JobPostingController::class, 'duplicate']);
    // Distribution channels (Career Portal, and future LinkedIn/Naukri/Indeed/TrulyTalents)
    Route::post('/jobs/{jobPosting}/publish-to',            [JobPostingController::class, 'publishTo']);
    Route::post('/jobs/{jobPosting}/publish-channels',      [JobPostingController::class, 'publishChannels']);
    Route::delete('/jobs/{jobPosting}/publish-to/{channel}',[JobPostingController::class, 'unpublishFrom']);

    // Candidates
    Route::get('/candidates',                           [CandidateController::class, 'index']);
    Route::get('/candidates/recruiters',                [CandidateController::class, 'recruiters']);
    Route::post('/candidates',                          [CandidateController::class, 'store']);
    Route::post('/candidates/linkedin-parse',           [CandidateController::class, 'linkedinParse']);
    Route::get('/candidates/{candidate}',               [CandidateController::class, 'show']);
    Route::put('/candidates/{candidate}',               [CandidateController::class, 'update']);
    Route::patch('/candidates/{candidate}/stage',       [CandidateController::class, 'updateStage']);
    Route::patch('/candidates/{candidate}/decision',    [CandidateController::class, 'updateDecision']);
    Route::patch('/candidates/{candidate}/assign',      [CandidateController::class, 'assign']);
    Route::delete('/candidates/{candidate}',            [CandidateController::class, 'destroy']);
    // Resume upload / download / delete
    Route::post('/candidates/{candidate}/resume',       [ResumeController::class, 'upload']);
    Route::get('/candidates/{candidate}/resume',        [ResumeController::class, 'download']);
    Route::delete('/candidates/{candidate}/resume',     [ResumeController::class, 'delete']);
    // Collaborative notes thread
    Route::get('/candidates/{candidate}/notes',                 [CandidateNoteController::class, 'index']);
    Route::post('/candidates/{candidate}/notes',                [CandidateNoteController::class, 'store']);
    Route::delete('/candidates/{candidate}/notes/{note}',       [CandidateNoteController::class, 'destroy']);
    // Documents (typed, beyond the primary resume)
    Route::get('/candidates/{candidate}/documents',             [CandidateDocumentController::class, 'index']);
    Route::post('/candidates/{candidate}/documents',            [CandidateDocumentController::class, 'store']);
    Route::get('/candidates/{candidate}/documents/{document}',  [CandidateDocumentController::class, 'download']);
    Route::delete('/candidates/{candidate}/documents/{document}',[CandidateDocumentController::class, 'destroy']);

    // Interviews
    Route::get('/interviews',                               [InterviewController::class, 'index']);
    Route::get('/interviews/stats',                         [InterviewController::class, 'stats']);
    Route::post('/interviews',                              [InterviewController::class, 'store']);
    Route::get('/interviews/{interviewRound}',              [InterviewController::class, 'show']);
    Route::put('/interviews/{interviewRound}',              [InterviewController::class, 'update']);
    Route::patch('/interviews/{interviewRound}/feedback',   [InterviewController::class, 'recordFeedback']);
    Route::patch('/interviews/{interviewRound}/cancel',     [InterviewController::class, 'cancel']);
    Route::post('/interviews/{interviewRound}/meet-link',   [InterviewController::class, 'generateMeetLink']);
    Route::post('/interviews/{interviewRound}/notify',      [InterviewController::class, 'sendNotification']);
    Route::delete('/interviews/{interviewRound}',           [InterviewController::class, 'destroy']);

    // Offers
    Route::get('/offers',                           [OfferController::class, 'index']);
    Route::get('/offers/joining-buckets',           [OfferController::class, 'joiningBuckets']);
    Route::post('/offers',                          [OfferController::class, 'store']);
    Route::get('/offers/{offer}',                   [OfferController::class, 'show']);
    Route::patch('/offers/{offer}/send',            [OfferController::class, 'send']);
    Route::patch('/offers/{offer}/status',          [OfferController::class, 'updateStatus']);
    Route::patch('/offers/{offer}/confirm-joining', [OfferController::class, 'confirmJoining']);
    Route::patch('/offers/{offer}/regenerate',      [OfferController::class, 'regenerate']);
    Route::delete('/offers/{offer}',                [OfferController::class, 'destroy']);

    // Onboarding
    Route::get('/onboarding',                           [OnboardingController::class, 'index']);
    Route::post('/onboarding',                          [OnboardingController::class, 'store']);
    Route::get('/onboarding/{onboarding}',              [OnboardingController::class, 'show']);
    Route::patch('/onboarding/{onboarding}/verify',     [OnboardingController::class, 'verify']);
    Route::get('/onboarding/{onboarding}/documents/{document}', [OnboardingController::class, 'downloadDocument']);
    Route::patch('/onboarding/{onboarding}/documents/{document}/verify', [OnboardingController::class, 'verifyDocument']);
    Route::patch('/onboarding/{onboarding}/step',       [OnboardingController::class, 'toggleStep']);
    Route::delete('/onboarding/{onboarding}',           [OnboardingController::class, 'destroy']);

    // Employees
    Route::get('/employees/stats',          [EmployeeController::class, 'stats']);
    Route::get('/employees',                [EmployeeController::class, 'index']);
    Route::post('/employees',               [EmployeeController::class, 'store']);
    Route::get('/employees/{employee}/profile', [EmployeeController::class, 'profile']);
    Route::get('/employees/{employee}',     [EmployeeController::class, 'show']);
    Route::put('/employees/{employee}',     [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}',  [EmployeeController::class, 'destroy']);
});
