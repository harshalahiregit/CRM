<?php

use App\Http\Controllers\Api\Hr\EmployeeProbationController;
use App\Http\Controllers\Api\Hr\ProbationConfirmationController;
use App\Http\Controllers\Api\Hr\ProbationExtensionController;
use App\Http\Controllers\Api\Hr\ProbationPolicyController;
use App\Http\Controllers\Api\Hr\ProbationReportController;
use App\Http\Controllers\Api\Hr\ProbationReviewController;
use App\Http\Controllers\Api\Hr\ProbationTypeController;
use Illuminate\Support\Facades\Route;

// ── Probation Management — Phases 1-6 (complete module). Sanctum, /hr/probation. ──
Route::middleware('auth:sanctum')->prefix('hr/probation')->group(function () {

    // Probation Types
    Route::get('/types',              [ProbationTypeController::class, 'index']);
    Route::post('/types',             [ProbationTypeController::class, 'store']);
    Route::put('/types/{id}',         [ProbationTypeController::class, 'update']);
    Route::patch('/types/{id}/status',[ProbationTypeController::class, 'updateStatus']);

    // Probation Policies
    Route::get('/policies',              [ProbationPolicyController::class, 'index']);
    Route::get('/policies/{id}',         [ProbationPolicyController::class, 'show']);
    Route::post('/policies',             [ProbationPolicyController::class, 'store']);
    Route::put('/policies/{id}',         [ProbationPolicyController::class, 'update']);
    Route::patch('/policies/{id}/status',[ProbationPolicyController::class, 'updateStatus']);

    // Employee Probation (Phase 2)
    Route::get('/employees',                    [EmployeeProbationController::class, 'index']);
    Route::get('/employees/employee/{employee}',[EmployeeProbationController::class, 'forEmployee'])->whereNumber('employee');
    Route::get('/employees/{id}',               [EmployeeProbationController::class, 'show'])->whereNumber('id');
    Route::post('/employees',                   [EmployeeProbationController::class, 'store']);
    Route::put('/employees/{id}',               [EmployeeProbationController::class, 'update'])->whereNumber('id');
    Route::patch('/employees/{id}/activate',    [EmployeeProbationController::class, 'activate'])->whereNumber('id');
    Route::patch('/employees/{id}/cancel',      [EmployeeProbationController::class, 'cancel'])->whereNumber('id');

    // Probation Reviews (Phase 3)
    Route::get('/reviews',                    [ProbationReviewController::class, 'index']);
    Route::get('/reviews/employee/{employee}',[ProbationReviewController::class, 'forEmployee'])->whereNumber('employee');
    Route::get('/reviews/{id}',               [ProbationReviewController::class, 'show'])->whereNumber('id');
    Route::post('/reviews',                   [ProbationReviewController::class, 'store']);
    Route::put('/reviews/{id}',               [ProbationReviewController::class, 'update'])->whereNumber('id');
    Route::patch('/reviews/{id}/submit',      [ProbationReviewController::class, 'submit'])->whereNumber('id');
    Route::patch('/reviews/{id}/complete',    [ProbationReviewController::class, 'complete'])->whereNumber('id');

    // Probation Extensions (Phase 4)
    Route::get('/extensions',                    [ProbationExtensionController::class, 'index']);
    Route::get('/extensions/history',            [ProbationExtensionController::class, 'history']);
    Route::get('/extensions/employee/{employee}',[ProbationExtensionController::class, 'forEmployee'])->whereNumber('employee');
    Route::get('/extensions/{id}',               [ProbationExtensionController::class, 'show'])->whereNumber('id');
    Route::post('/extensions',                   [ProbationExtensionController::class, 'store']);
    Route::put('/extensions/{id}',               [ProbationExtensionController::class, 'update'])->whereNumber('id');
    Route::patch('/extensions/{id}/approve',     [ProbationExtensionController::class, 'approve'])->whereNumber('id');
    Route::patch('/extensions/{id}/reject',      [ProbationExtensionController::class, 'reject'])->whereNumber('id');

    // Probation Confirmation (Phase 5)
    Route::get('/confirmations',                    [ProbationConfirmationController::class, 'index']);
    Route::get('/confirmations/history',            [ProbationConfirmationController::class, 'history']);
    Route::get('/confirmations/employee/{employee}',[ProbationConfirmationController::class, 'forEmployee'])->whereNumber('employee');
    Route::get('/confirmations/{id}',               [ProbationConfirmationController::class, 'show'])->whereNumber('id');
    Route::post('/confirmations',                   [ProbationConfirmationController::class, 'store']);
    Route::put('/confirmations/{id}',               [ProbationConfirmationController::class, 'update'])->whereNumber('id');
    Route::patch('/confirmations/{id}/approve',     [ProbationConfirmationController::class, 'approve'])->whereNumber('id');
    Route::patch('/confirmations/{id}/reject',      [ProbationConfirmationController::class, 'reject'])->whereNumber('id');
    Route::patch('/confirmations/{id}/confirm',     [ProbationConfirmationController::class, 'confirm'])->whereNumber('id');

    // Probation Reports & Analytics (Phase 6) — read-only + CSV/PDF export.
    Route::get('/reports/filters',       [ProbationReportController::class, 'filterOptions']);
    Route::get('/reports/dashboard',     [ProbationReportController::class, 'dashboard']);
    Route::get('/reports/employees',     [ProbationReportController::class, 'employees']);
    Route::get('/reports/departments',   [ProbationReportController::class, 'departments']);
    Route::get('/reports/policies',      [ProbationReportController::class, 'policies']);
    Route::get('/reports/reviews',       [ProbationReportController::class, 'reviews']);
    Route::get('/reports/extensions',    [ProbationReportController::class, 'extensions']);
    Route::get('/reports/confirmations', [ProbationReportController::class, 'confirmations']);
    Route::get('/reports/trends',        [ProbationReportController::class, 'trends']);
    Route::get('/reports/export',        [ProbationReportController::class, 'export']);
});
