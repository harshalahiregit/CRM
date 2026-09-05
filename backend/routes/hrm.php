<?php

use App\Http\Controllers\Api\Hrm\HrmAttendanceController;
use App\Http\Controllers\Api\Hrm\HrmAdminController;
use App\Http\Controllers\Api\Hrm\HrmAuthController;
use App\Http\Controllers\Api\Hrm\HrmClaimController;
use App\Http\Controllers\Api\Hrm\HrmFileController;
use App\Http\Controllers\Api\Hrm\HrmLeaveController;
use App\Http\Controllers\Api\Hrm\HrmProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SangoeTrack app compatibility
|--------------------------------------------------------------------------
|
| The attendance app calls /api/Hrm/* on track.sangoe.in. These routes let it
| call the CRM instead, with only its base URL changed.
|
| The app is the fragile side: 271 parse sites, and an unrecognised key renders
| BLANK rather than erroring. Rewriting that in Dart would be far riskier than
| answering in the shape it already expects, so the paths and payloads here
| deliberately mirror theirs rather than the CRM's own conventions.
|
| Responses go through HrmResponse, which reproduces their envelope — integer
| `status`, 200 even on refusal, 403 for validation, and 401 reserved for a
| genuinely dead session because the app treats it as destructive.
|
*/

Route::prefix('Hrm')->group(function () {
    // Open: somebody signing in has no token yet.
    Route::post('/login', [HrmAuthController::class, 'login']);

    // Signed, not authenticated: the app gives these URLs to an image widget,
    // which sends no Authorization header. The signature carries the permission
    // and expires; Laravel rejects a tampered or stale one before the controller.
    Route::get('/file/{attachment}', [HrmFileController::class, 'show'])
        ->name('hrm.file')
        ->middleware('signed');

    // A file attached to an announcement. Signed for the same reason: the app
    // opens these in a viewer that sends no Authorization header.
    Route::get('/announcement-file/{notification}/{index}',
        [\App\Http\Controllers\Api\Hrm\HrmAnnouncementFileController::class, 'show'])
        ->whereNumber('index')
        ->name('hrm.announcement.file')
        ->middleware('signed');

    // Reached from the sign-in screen, before anybody has a token.
    Route::post('/forgot-password', [HrmProfileController::class, 'forgotPassword']);
    Route::post('/demo-request',    [HrmProfileController::class, 'demoRequest']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout',          [HrmAuthController::class, 'logout']);
        Route::post('/refresh',         [HrmAuthController::class, 'refresh']);
        Route::post('/change-password', [HrmAuthController::class, 'changePassword']);

        // The daily path. Paths are the app's, misspellings included:
        // /attendence-history is how its URL table spells it.
        Route::post('/home',                [HrmAttendanceController::class, 'home']);
        Route::post('/clock-in-out',        [HrmAttendanceController::class, 'clock']);
        Route::post('/break-toggle',        [HrmAttendanceController::class, 'breakToggle']);
        Route::post('/attendence-history',  [HrmAttendanceController::class, 'history']);

        // Leave, holidays and "attendance raises" — their name for what the CRM
        // calls a correction, so it is backed by the same service.
        Route::post('/get-leaves',              [HrmLeaveController::class, 'myLeaves']);
        Route::post('/get-leaves-types',        [HrmLeaveController::class, 'leaveTypes']);
        Route::post('/leave-request',           [HrmLeaveController::class, 'applyLeave']);
        Route::post('/leave-balance',           [HrmLeaveController::class, 'leaveBalance']);
        Route::post('/holidays-list',           [HrmLeaveController::class, 'holidays']);
        Route::post('/get-attendance-raises',   [HrmLeaveController::class, 'raises']);
        Route::post('/submit-attendance-raise', [HrmLeaveController::class, 'submitRaise']);

        // Expense claims and advances, on the same services the CRM screens use.
        Route::post('/get-reimbursements',      [HrmClaimController::class, 'reimbursements']);
        Route::post('/submit-reimbursement',    [HrmClaimController::class, 'submitReimbursement']);
        Route::post('/reimbursement-report',    [HrmClaimController::class, 'reimbursements']);
        Route::post('/advance/my-requests',     [HrmClaimController::class, 'myAdvances']);
        Route::post('/advance/submit',          [HrmClaimController::class, 'submitAdvance']);
        Route::post('/advance/detail',          [HrmClaimController::class, 'advanceDetail']);
        Route::post('/advance/submit-settlement',[HrmClaimController::class, 'submitSettlement']);
        Route::post('/advance/ledger',          [HrmClaimController::class, 'advanceLedger']);
        Route::post('/advance/payroll-summary', [HrmClaimController::class, 'payrollSummary']);

        // Profile, salary, calendar and notifications.
        Route::post('/edit-profile',              [HrmProfileController::class, 'editProfile']);
        Route::post('/delete-account',            [HrmProfileController::class, 'deleteAccount']);
        Route::post('/salary-details',            [HrmProfileController::class, 'salaryDetails']);
        // GET, unlike almost everything else the app calls.
        Route::get('/events',                     [HrmProfileController::class, 'events']);
        Route::post('/notifications',             [HrmProfileController::class, 'notifications']);
        Route::post('/notifications/mark-read',   [HrmProfileController::class, 'markNotificationsRead']);
        Route::post('/notification-preferences',  [HrmProfileController::class, 'notificationPreferences']);
        // A SEPARATE save route, not the same one with a body. Found by diffing
        // the app's URL table against what is served — assuming one route did
        // both would have left every preference change silently unsaved.
        Route::post('/notification-preferences/save', [HrmProfileController::class, 'notificationPreferences']);
        Route::post('/fcm-token',                 [HrmProfileController::class, 'fcmToken']);

        // ── Admin ───────────────────────────────────────────────────────
        // A permission refusal here is 200 with status 0, never 401: the app
        // treats 401 as a dead session and wipes local storage, so answering a
        // permission problem that way signs somebody out mid-shift.
        //
        // Two of these are GET, matching the app's own calls.
        Route::get('/admin/dashboard',                    [HrmAdminController::class, 'dashboard']);
        Route::get('/admin/attendance-details',           [HrmAdminController::class, 'attendanceDetails']);
        Route::post('/admin/pending-approvals',           [HrmAdminController::class, 'pendingApprovals']);
        Route::get('/admin/pending-approvals',            [HrmAdminController::class, 'pendingApprovals']);

        Route::post('/admin/approve-reject-leave',         [HrmAdminController::class, 'decideLeave']);
        Route::post('/admin/approve-reject-raise',         [HrmAdminController::class, 'decideRaise']);
        Route::post('/admin/approve-reject-reimbursement', [HrmAdminController::class, 'decideReimbursement']);
        Route::post('/admin/approve-reject-advance',       [HrmAdminController::class, 'decideAdvance']);
        Route::post('/admin/disburse-advance',             [HrmAdminController::class, 'disburseAdvance']);

        Route::get('/admin/pending-settlements',           [HrmAdminController::class, 'pendingSettlements']);
        Route::post('/admin/pending-settlements',          [HrmAdminController::class, 'pendingSettlements']);
        Route::post('/admin/review-settlement',            [HrmAdminController::class, 'reviewSettlement']);

        Route::get('/admin/employees-list',                [HrmAdminController::class, 'employees']);
        Route::post('/admin/employees-list',               [HrmAdminController::class, 'employees']);
        Route::get('/admin/assignable-roles',              [HrmAdminController::class, 'assignableRoles']);
        Route::post('/admin/create-employee',              [HrmAdminController::class, 'createEmployee']);
        Route::post('/admin/reset-employee-password',      [HrmAdminController::class, 'resetPassword']);

        Route::get('/admin/demo-requests',                 [HrmAdminController::class, 'demoRequests']);
        Route::post('/admin/demo-requests',                [HrmAdminController::class, 'demoRequests']);
        Route::post('/admin/update-demo-request',          [HrmAdminController::class, 'updateDemoRequest']);

        Route::get('/admin/payroll-overview',              [HrmAdminController::class, 'payrollOverview']);
        Route::post('/admin/set-employee-salary',          [HrmAdminController::class, 'setEmployeeSalary']);
        Route::get('/admin/reports',                       [HrmAdminController::class, 'reports']);
        Route::post('/admin/reports',                      [HrmAdminController::class, 'reports']);
        Route::get('/admin/reports-summary',               [HrmAdminController::class, 'reportsSummary']);
        Route::post('/admin/reports-summary',              [HrmAdminController::class, 'reportsSummary']);
    });
});
