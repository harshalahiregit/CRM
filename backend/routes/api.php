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
// Sales module
use App\Http\Controllers\Api\Sales\SalesDashboardController;
use App\Http\Controllers\Api\Sales\ItemController;
use App\Http\Controllers\Api\Sales\ProposalController;
use App\Http\Controllers\Api\Sales\EstimateController;
use App\Http\Controllers\Api\Sales\InvoiceController;
use App\Http\Controllers\Api\Sales\CreditNoteController;
use App\Http\Controllers\Api\Sales\DeliveryNoteController;
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
    });
});


