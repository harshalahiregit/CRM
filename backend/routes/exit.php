<?php

use App\Http\Controllers\Api\Hr\ExitApprovalController;
use App\Http\Controllers\Api\Hr\ExitClearanceController;
use App\Http\Controllers\Api\Hr\ExitPolicyController;
use App\Http\Controllers\Api\Hr\ExitReportController;
use App\Http\Controllers\Api\Hr\ExitRequestController;
use App\Http\Controllers\Api\Hr\ExitSettlementController;
use App\Http\Controllers\Api\Hr\ExitTypeController;
use Illuminate\Support\Facades\Route;

// ── Exit / Separation Management — Phases 1-6. Sanctum, /hr/exit prefix. ────
Route::middleware('auth:sanctum')->prefix('hr/exit')->group(function () {

    // Exit Types master
    Route::get('/types',              [ExitTypeController::class, 'index']);
    Route::post('/types',             [ExitTypeController::class, 'store']);
    Route::put('/types/{id}',         [ExitTypeController::class, 'update']);
    Route::patch('/types/{id}/status',[ExitTypeController::class, 'updateStatus']);

    // Exit Policies
    Route::get('/policies',              [ExitPolicyController::class, 'index']);
    Route::get('/policies/{id}',         [ExitPolicyController::class, 'show']);
    Route::post('/policies',             [ExitPolicyController::class, 'store']);
    Route::put('/policies/{id}',         [ExitPolicyController::class, 'update']);
    Route::patch('/policies/{id}/status',[ExitPolicyController::class, 'updateStatus']);

    // Exit Requests (Phase 2) — Draft → Submitted, Withdrawn from either.
    Route::get('/requests',                    [ExitRequestController::class, 'index']);
    Route::get('/requests/employee/{employee}',[ExitRequestController::class, 'forEmployee']);
    Route::get('/requests/{id}',               [ExitRequestController::class, 'show'])->whereNumber('id');
    Route::get('/requests/{id}/attachment',    [ExitRequestController::class, 'attachment'])->whereNumber('id');
    Route::post('/requests',                   [ExitRequestController::class, 'store']);
    Route::put('/requests/{id}',               [ExitRequestController::class, 'update'])->whereNumber('id');
    Route::patch('/requests/{id}/submit',      [ExitRequestController::class, 'submit'])->whereNumber('id');
    Route::patch('/requests/{id}/withdraw',    [ExitRequestController::class, 'withdraw'])->whereNumber('id');

    // Exit Approval (Phase 3) — Submitted → Under Review → Approved / Rejected.
    Route::get('/approvals',                 [ExitApprovalController::class, 'index']);
    Route::get('/approvals/history',         [ExitApprovalController::class, 'history']);
    Route::get('/approvals/{id}',            [ExitApprovalController::class, 'show'])->whereNumber('id');
    Route::patch('/approvals/{id}/review',   [ExitApprovalController::class, 'startReview'])->whereNumber('id');
    Route::patch('/approvals/{id}/remarks',  [ExitApprovalController::class, 'updateRemarks'])->whereNumber('id');
    Route::patch('/approvals/{id}/approve',  [ExitApprovalController::class, 'approve'])->whereNumber('id');
    Route::patch('/approvals/{id}/reject',   [ExitApprovalController::class, 'reject'])->whereNumber('id');

    // Clearance (Phase 4) — per-department checklist on Approved exits.
    Route::get('/clearances',                        [ExitClearanceController::class, 'index']);
    Route::get('/clearances/history',                [ExitClearanceController::class, 'history']);
    Route::get('/clearances/employee/{employee}',    [ExitClearanceController::class, 'forEmployee'])->whereNumber('employee');
    Route::get('/clearances/{id}',                   [ExitClearanceController::class, 'show'])->whereNumber('id');
    Route::patch('/clearances/{id}/items/{item}/start',  [ExitClearanceController::class, 'start'])->whereNumber('id')->whereNumber('item');
    Route::patch('/clearances/{id}/items/{item}/clear',  [ExitClearanceController::class, 'clear'])->whereNumber('id')->whereNumber('item');
    Route::patch('/clearances/{id}/items/{item}/reject', [ExitClearanceController::class, 'reject'])->whereNumber('id')->whereNumber('item');
    Route::patch('/clearances/{id}/items/{item}/remarks',[ExitClearanceController::class, 'remarks'])->whereNumber('id')->whereNumber('item');

    // Full & Final Settlement (Phase 5) — only for Completed-clearance exits; payroll read-only.
    Route::get('/settlements',                     [ExitSettlementController::class, 'index']);
    Route::get('/settlements/history',             [ExitSettlementController::class, 'history']);
    Route::get('/settlements/employee/{employee}', [ExitSettlementController::class, 'forEmployee'])->whereNumber('employee');
    Route::get('/settlements/{id}',                [ExitSettlementController::class, 'show'])->whereNumber('id');
    Route::post('/settlements/{id}/generate',      [ExitSettlementController::class, 'generate'])->whereNumber('id');
    Route::patch('/settlements/{id}/review',       [ExitSettlementController::class, 'review'])->whereNumber('id');
    Route::patch('/settlements/{id}/approve',      [ExitSettlementController::class, 'approve'])->whereNumber('id');
    Route::patch('/settlements/{id}/settle',       [ExitSettlementController::class, 'settle'])->whereNumber('id');

    // Exit Reports & Analytics (Phase 6) — read-only aggregates + CSV/PDF export.
    Route::get('/reports/filters',     [ExitReportController::class, 'filterOptions']);
    Route::get('/reports/dashboard',   [ExitReportController::class, 'dashboard']);
    Route::get('/reports/employees',   [ExitReportController::class, 'employees']);
    Route::get('/reports/departments', [ExitReportController::class, 'departments']);
    Route::get('/reports/exit-types',  [ExitReportController::class, 'exitTypes']);
    Route::get('/reports/settlements', [ExitReportController::class, 'settlements']);
    Route::get('/reports/clearances',  [ExitReportController::class, 'clearances']);
    Route::get('/reports/trends',      [ExitReportController::class, 'trends']);
    Route::get('/reports/export',      [ExitReportController::class, 'export']);
});
