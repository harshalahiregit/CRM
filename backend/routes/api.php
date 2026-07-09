<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Admin\StaffManagementController;
use App\Http\Controllers\Api\Hr\HRDashboardController;
use App\Http\Controllers\Api\Hr\ManpowerRequestController;
use App\Http\Controllers\Api\Hr\JobPostingController;
use App\Http\Controllers\Api\Hr\CandidateController;
use App\Http\Controllers\Api\Hr\ResumeController;
use App\Http\Controllers\Api\Hr\InterviewController;
use App\Http\Controllers\Api\Hr\OfferController;
use App\Http\Controllers\Api\Hr\OnboardingController;
use App\Http\Controllers\Api\Hr\EmployeeController;
// Sales module
use App\Http\Controllers\Api\Sales\SalesDashboardController;
use App\Http\Controllers\Api\Sales\ItemController;
use App\Http\Controllers\Api\Sales\ProposalController;
use App\Http\Controllers\Api\Sales\EstimateController;
use App\Http\Controllers\Api\Sales\InvoiceController;
use App\Http\Controllers\Api\Sales\CreditNoteController;
use App\Http\Controllers\Api\Sales\DeliveryNoteController;
use App\Http\Controllers\Api\Sales\LeadController;
use App\Http\Controllers\Api\Sales\LeadSettingController;
use Illuminate\Support\Facades\Route;

// ── Public Auth Routes ──────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/login',           [AuthController::class, 'login'])->name('login');
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/register/vendor', [AuthController::class, 'registerVendor']);
    Route::post('/register/tpv',    [AuthController::class, 'registerTPV']);
    Route::post('/register/client', [AuthController::class, 'registerClient']);
});

// ── Test Route (NO AUTH) ────────────────────────────────────────────────
Route::get('/test-dashboard', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is working!',
        'timestamp' => now()->toDateTimeString()
    ]);
});

// ── Test HR Dashboard (NO AUTH - for debugging) ─────────────────────────
Route::get('/test-hr-dashboard', function () {
    $user = \App\Models\User::where('email', 'admin@demo.com')->first();
    if (!$user) {
        return response()->json(['error' => 'User not found']);
    }
    
    auth()->login($user);
    $controller = new \App\Http\Controllers\Api\Hr\HRDashboardController();
    return $controller->index();
});

// ── Protected Routes (Sanctum) ──────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::get('/dashboard',    [DashboardController::class, 'index']);

    // ── Admin Only Routes ───────────────────────────────────────────────
    Route::prefix('admin')->middleware('role:admin')->group(function () {
        
        // Staff Management
        Route::get('/staff/stats',              [StaffManagementController::class, 'stats']);
        Route::get('/staff',                    [StaffManagementController::class, 'index']);
        Route::get('/staff/designations',       [StaffManagementController::class, 'designations']);
        Route::get('/staff/departments',        [StaffManagementController::class, 'departments']);
        Route::get('/staff/{id}',               [StaffManagementController::class, 'show']);
        Route::post('/staff',                   [StaffManagementController::class, 'store']);
        Route::put('/staff/{id}',               [StaffManagementController::class, 'update']);
        Route::patch('/staff/{id}/toggle-status', [StaffManagementController::class, 'toggleStatus']);
        Route::delete('/staff/{id}',            [StaffManagementController::class, 'destroy']);
    });

    // ── HR Module Routes ────────────────────────────────────────────────
    Route::prefix('hr')->group(function () {

        // Dashboard
        Route::get('/dashboard', [HRDashboardController::class, 'index']);

        // Manpower Requests — L1/L2 Approval Workflow
        Route::get('/manpower-requests',                            [ManpowerRequestController::class, 'index']);
        Route::get('/manpower-requests/stats',                      [ManpowerRequestController::class, 'stats']);
        Route::get('/manpower-requests/pending-count',              [ManpowerRequestController::class, 'pendingCount']);
        Route::get('/manpower-requests/pending-approvals',          [ManpowerRequestController::class, 'pendingApprovals']);
        Route::post('/manpower-requests',                           [ManpowerRequestController::class, 'store']);
        Route::get('/manpower-requests/{manpowerRequest}',          [ManpowerRequestController::class, 'show']);
        Route::put('/manpower-requests/{manpowerRequest}',          [ManpowerRequestController::class, 'update']);
        Route::delete('/manpower-requests/{manpowerRequest}',       [ManpowerRequestController::class, 'destroy']);
        // L1/L2 Workflow actions
        Route::post('/manpower-requests/{manpowerRequest}/submit',      [ManpowerRequestController::class, 'submit']);
        Route::post('/manpower-requests/{manpowerRequest}/approve-l1',  [ManpowerRequestController::class, 'approveL1']);
        Route::post('/manpower-requests/{manpowerRequest}/reject-l1',   [ManpowerRequestController::class, 'rejectL1']);
        Route::post('/manpower-requests/{manpowerRequest}/approve-l2',  [ManpowerRequestController::class, 'approveL2']);
        Route::post('/manpower-requests/{manpowerRequest}/reject-l2',   [ManpowerRequestController::class, 'rejectL2']);
        Route::patch('/manpower-requests/{manpowerRequest}/assign-manager', [ManpowerRequestController::class, 'assignManager']);

        // Job Postings
        Route::get('/jobs',                         [JobPostingController::class, 'index']);
        Route::post('/jobs',                        [JobPostingController::class, 'store']);
        Route::get('/jobs/{jobPosting}',            [JobPostingController::class, 'show']);
        Route::put('/jobs/{jobPosting}',            [JobPostingController::class, 'update']);
        Route::patch('/jobs/{jobPosting}/status',   [JobPostingController::class, 'updateStatus']);
        Route::patch('/jobs/{jobPosting}/external-id', [JobPostingController::class, 'updateExternalId']);
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
        // Resume upload / download / delete
        Route::post('/candidates/{candidate}/resume',       [ResumeController::class, 'upload']);
        Route::get('/candidates/{candidate}/resume',        [ResumeController::class, 'download']);
        Route::delete('/candidates/{candidate}/resume',     [ResumeController::class, 'delete']);

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

    // ── Sales & Revenue Module ──────────────────────────────────────────
    Route::prefix('sales')->group(function () {

        // Dashboard
        Route::get('/dashboard', [SalesDashboardController::class, 'index']);

        // Items catalog
        Route::get('/items',              [ItemController::class, 'index']);
        Route::post('/items',             [ItemController::class, 'store']);
        Route::get('/items/{item}',       [ItemController::class, 'show']);
        Route::put('/items/{item}',       [ItemController::class, 'update']);
        Route::delete('/items/{item}',    [ItemController::class, 'destroy']);

        // Proposals
        Route::get('/proposals',                              [ProposalController::class, 'index']);
        Route::post('/proposals',                             [ProposalController::class, 'store']);
        Route::get('/proposals/{proposal}',                   [ProposalController::class, 'show']);
        Route::put('/proposals/{proposal}',                   [ProposalController::class, 'update']);
        Route::delete('/proposals/{proposal}',                [ProposalController::class, 'destroy']);
        Route::patch('/proposals/{proposal}/send',            [ProposalController::class, 'send']);
        Route::patch('/proposals/{proposal}/status',          [ProposalController::class, 'updateStatus']);

        // Estimates
        Route::get('/estimates',                                   [EstimateController::class, 'index']);
        Route::post('/estimates',                                  [EstimateController::class, 'store']);
        Route::get('/estimates/{estimate}',                        [EstimateController::class, 'show']);
        Route::put('/estimates/{estimate}',                        [EstimateController::class, 'update']);
        Route::delete('/estimates/{estimate}',                     [EstimateController::class, 'destroy']);
        Route::patch('/estimates/{estimate}/send',                 [EstimateController::class, 'send']);
        Route::post('/estimates/{estimate}/convert-to-invoice',    [EstimateController::class, 'convertToInvoice']);

        // Invoices
        Route::get('/invoices',                                    [InvoiceController::class, 'index']);
        Route::post('/invoices',                                   [InvoiceController::class, 'store']);
        Route::get('/invoices/{invoice}',                          [InvoiceController::class, 'show']);
        Route::put('/invoices/{invoice}',                          [InvoiceController::class, 'update']);
        Route::delete('/invoices/{invoice}',                       [InvoiceController::class, 'destroy']);
        Route::patch('/invoices/{invoice}/send',                   [InvoiceController::class, 'send']);
        Route::post('/invoices/{invoice}/payments',                [InvoiceController::class, 'recordPayment']);

        // Credit Notes
        Route::get('/credit-notes',                                [CreditNoteController::class, 'index']);
        Route::post('/credit-notes',                               [CreditNoteController::class, 'store']);
        Route::get('/credit-notes/{creditNote}',                   [CreditNoteController::class, 'show']);
        Route::delete('/credit-notes/{creditNote}',                [CreditNoteController::class, 'destroy']);
        Route::post('/credit-notes/{creditNote}/apply',            [CreditNoteController::class, 'applyToInvoice']);
        Route::post('/credit-notes/{creditNote}/refund',           [CreditNoteController::class, 'refund']);

        // Delivery Notes
        Route::get('/delivery-notes',                              [DeliveryNoteController::class, 'index']);
        Route::post('/delivery-notes',                             [DeliveryNoteController::class, 'store']);
        Route::get('/delivery-notes/{deliveryNote}',               [DeliveryNoteController::class, 'show']);
        Route::put('/delivery-notes/{deliveryNote}',               [DeliveryNoteController::class, 'update']);
        Route::patch('/delivery-notes/{deliveryNote}/deliver',     [DeliveryNoteController::class, 'markDelivered']);
        Route::delete('/delivery-notes/{deliveryNote}',            [DeliveryNoteController::class, 'destroy']);

        // ── Leads Module ────────────────────────────────────────────

        // Lead Statuses & Sources (settings)
        Route::get('/lead-statuses',                               [LeadSettingController::class, 'statuses']);
        Route::post('/lead-statuses',                              [LeadSettingController::class, 'createStatus']);
        Route::put('/lead-statuses/{status}',                      [LeadSettingController::class, 'updateStatus']);
        Route::delete('/lead-statuses/{status}',                   [LeadSettingController::class, 'deleteStatus']);

        Route::get('/lead-sources',                                [LeadSettingController::class, 'sources']);
        Route::post('/lead-sources',                               [LeadSettingController::class, 'createSource']);
        Route::put('/lead-sources/{source}',                       [LeadSettingController::class, 'updateSource']);
        Route::delete('/lead-sources/{source}',                    [LeadSettingController::class, 'deleteSource']);

        // Lead Goals / Targets
        Route::get('/lead-goals',                                  [LeadSettingController::class, 'goals']);
        Route::post('/lead-goals',                                 [LeadSettingController::class, 'storeGoal']);
        Route::put('/lead-goals/{goal}',                           [LeadSettingController::class, 'updateGoal']);
        Route::delete('/lead-goals/{goal}',                        [LeadSettingController::class, 'deleteGoal']);

        // Questionnaires
        Route::get('/lead-questionnaires',                         [LeadSettingController::class, 'questionnaires']);
        Route::post('/lead-questionnaires',                        [LeadSettingController::class, 'storeQuestionnaire']);
        Route::put('/lead-questionnaires/{questionnaire}',         [LeadSettingController::class, 'updateQuestionnaire']);
        Route::delete('/lead-questionnaires/{questionnaire}',      [LeadSettingController::class, 'deleteQuestionnaire']);

        // Leads CRUD + Actions
        Route::get('/leads/summary',                               [LeadController::class, 'summary']);
        Route::get('/leads/kanban',                                [LeadController::class, 'kanban']);
        Route::post('/leads/bulk',                                 [LeadController::class, 'bulkAction']);
        Route::get('/leads',                                       [LeadController::class, 'index']);
        Route::post('/leads',                                      [LeadController::class, 'store']);
        Route::get('/leads/{lead}',                                [LeadController::class, 'show']);
        Route::put('/leads/{lead}',                                [LeadController::class, 'update']);
        Route::delete('/leads/{lead}',                             [LeadController::class, 'destroy']);
        Route::patch('/leads/{lead}/status',                       [LeadController::class, 'updateStatus']);
        Route::patch('/leads/{lead}/assign',                       [LeadController::class, 'assign']);
        Route::patch('/leads/{lead}/lost',                         [LeadController::class, 'markLost']);
        Route::patch('/leads/{lead}/junk',                         [LeadController::class, 'markJunk']);
        Route::patch('/leads/{lead}/restore',                      [LeadController::class, 'restore']);
        Route::post('/leads/{lead}/convert',                       [LeadController::class, 'convert']);
        Route::post('/leads/{lead}/notes',                         [LeadController::class, 'addNote']);
        Route::post('/leads/{lead}/questionnaire-response',        [LeadController::class, 'submitQuestionnaireResponse']);
    });
});


