<?php

use App\Http\Controllers\Api\Hr\PerformanceController;
use App\Http\Controllers\Api\Hr\PerformanceReviewController;
use App\Http\Controllers\Api\Hr\RecommendationController;
use Illuminate\Support\Facades\Route;

// ── Performance Management System (PMS) — Sanctum, /hr prefix ───────────────
Route::middleware('auth:sanctum')->prefix('hr/performance')->group(function () {

    // Dashboard + employee timeline (read-only)
    Route::get('/dashboard',             [PerformanceController::class, 'dashboard']);
    Route::get('/timeline/{employeeId}', [PerformanceController::class, 'timeline']);

    // KPI master
    Route::get('/kpis',              [PerformanceController::class, 'kpis']);
    Route::post('/kpis',             [PerformanceController::class, 'storeKpi']);
    Route::put('/kpis/{id}',         [PerformanceController::class, 'updateKpi']);
    Route::patch('/kpis/{id}/status',[PerformanceController::class, 'kpiStatus']);

    // Goals / KRA
    Route::get('/goals',      [PerformanceController::class, 'goals']);
    Route::post('/goals',     [PerformanceController::class, 'storeGoal']);
    Route::put('/goals/{id}', [PerformanceController::class, 'updateGoal']);

    // Employee goal assignments
    Route::get('/assignments',       [PerformanceController::class, 'assignments']);
    Route::post('/assignments',      [PerformanceController::class, 'assignGoal']);
    Route::patch('/assignments/{id}',[PerformanceController::class, 'updateAssignment']);

    // Reviews
    Route::get('/reviews',            [PerformanceReviewController::class, 'index']);
    Route::get('/reviews/{id}',       [PerformanceReviewController::class, 'show']);
    Route::post('/reviews',           [PerformanceReviewController::class, 'store']);
    Route::put('/reviews/{id}',       [PerformanceReviewController::class, 'update']);
    Route::patch('/reviews/{id}/status',[PerformanceReviewController::class, 'updateStatus']);

    // Promotion recommendations (Phase 5)
    Route::get('/promotions',            [RecommendationController::class, 'promotions']);
    Route::post('/promotions/generate',  [RecommendationController::class, 'generatePromotion']);
    Route::patch('/promotions/{id}/status',[RecommendationController::class, 'promotionStatus']);

    // Increment recommendations (Phase 6) — recommendation only, never touches Payroll
    Route::get('/increments',            [RecommendationController::class, 'increments']);
    Route::post('/increments/generate',  [RecommendationController::class, 'generateIncrement']);
    Route::patch('/increments/{id}/status',[RecommendationController::class, 'incrementStatus']);
});
