<?php

use App\Http\Controllers\Api\Hrm\HrmAttendanceController;
use App\Http\Controllers\Api\Hrm\HrmAuthController;
use App\Http\Controllers\Api\Hrm\HrmClaimController;
use App\Http\Controllers\Api\Hrm\HrmFileController;
use App\Http\Controllers\Api\Hrm\HrmLeaveController;
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
    });
});
