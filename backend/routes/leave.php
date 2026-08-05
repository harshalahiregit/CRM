<?php

use App\Http\Controllers\Api\Hr\EmployeeLeaveBalanceController;
use App\Http\Controllers\Api\Hr\HolidayController;
use App\Http\Controllers\Api\Hr\LeaveReportController;
use App\Http\Controllers\Api\Hr\LeaveApplicationController;
use App\Http\Controllers\Api\Hr\LeaveApprovalController;
use App\Http\Controllers\Api\Hr\LeavePolicyController;
use App\Http\Controllers\Api\Hr\LeaveTypeController;
use Illuminate\Support\Facades\Route;

// ── Leave Management — Phase 1 (Foundation). Sanctum, /hr/leave prefix. ─────
Route::middleware('auth:sanctum')->prefix('hr/leave')->group(function () {

    // Leave Types master
    Route::get('/types',              [LeaveTypeController::class, 'index']);
    Route::post('/types',             [LeaveTypeController::class, 'store']);
    Route::put('/types/{id}',         [LeaveTypeController::class, 'update']);
    Route::patch('/types/{id}/status',[LeaveTypeController::class, 'updateStatus']);

    // Leave Policies (+ policy ↔ type mapping)
    Route::get('/policies',              [LeavePolicyController::class, 'index']);
    Route::get('/policies/{id}',         [LeavePolicyController::class, 'show']);
    Route::post('/policies',             [LeavePolicyController::class, 'store']);
    Route::put('/policies/{id}',         [LeavePolicyController::class, 'update']);
    Route::patch('/policies/{id}/status',[LeavePolicyController::class, 'updateStatus']);

    // Employee Leave Balance & Allocation (Phase 2). {employee} kept last (least specific).
    Route::get('/balances',                   [EmployeeLeaveBalanceController::class, 'index']);
    Route::post('/balances/assign',           [EmployeeLeaveBalanceController::class, 'assign']);
    Route::post('/balances/allocate',         [EmployeeLeaveBalanceController::class, 'allocate']);
    Route::post('/balances/adjust',           [EmployeeLeaveBalanceController::class, 'adjust']);
    Route::get('/balances/history/{balance}', [EmployeeLeaveBalanceController::class, 'history']);
    Route::get('/balances/{employee}',        [EmployeeLeaveBalanceController::class, 'forEmployee']);

    // Leave Applications (Phase 3)
    // Static segment before /applications/{id}, or the binding swallows it.
    Route::post('/applications/preview',       [LeaveApplicationController::class, 'preview']);
    Route::get('/applications',                [LeaveApplicationController::class, 'index']);
    Route::post('/applications',               [LeaveApplicationController::class, 'store']);
    Route::get('/applications/{id}',           [LeaveApplicationController::class, 'show']);
    Route::patch('/applications/{id}/submit',  [LeaveApplicationController::class, 'submit']);
    Route::patch('/applications/{id}/cancel',  [LeaveApplicationController::class, 'cancel']);
    Route::get('/applications/{id}/attachment',[LeaveApplicationController::class, 'attachment']);

    // Leave Approval workflow (Phase 4)
    Route::get('/approvals',                    [LeaveApprovalController::class, 'index']);
    Route::get('/approvals/history/{employeeId}',[LeaveApprovalController::class, 'history']);
    Route::get('/approvals/{id}',               [LeaveApprovalController::class, 'show']);
    Route::patch('/approvals/{id}/approve',     [LeaveApprovalController::class, 'approve']);
    Route::patch('/approvals/{id}/reject',      [LeaveApprovalController::class, 'reject']);

    // Holiday Calendar (Phase 5). {id} kept after /calendar (least specific).
    Route::get('/holidays',              [HolidayController::class, 'index']);
    Route::get('/holidays/calendar',     [HolidayController::class, 'calendar']);
    Route::get('/holidays/{id}',         [HolidayController::class, 'show']);
    Route::post('/holidays',             [HolidayController::class, 'store']);
    Route::put('/holidays/{id}',         [HolidayController::class, 'update']);
    Route::patch('/holidays/{id}/status',[HolidayController::class, 'updateStatus']);

    // Leave Reports & Analytics (final phase). Read-only over existing Leave data.
    Route::get('/reports/filters',     [LeaveReportController::class, 'filterOptions']);
    Route::get('/reports/dashboard',   [LeaveReportController::class, 'dashboard']);
    Route::get('/reports/employees',   [LeaveReportController::class, 'employees']);
    Route::get('/reports/departments', [LeaveReportController::class, 'departments']);
    Route::get('/reports/types',       [LeaveReportController::class, 'types']);
    Route::get('/reports/balances',    [LeaveReportController::class, 'balances']);
    Route::get('/reports/holidays',    [LeaveReportController::class, 'holidays']);
    Route::get('/reports/trends',      [LeaveReportController::class, 'trends']);
    Route::get('/reports/export',      [LeaveReportController::class, 'export']);
});
