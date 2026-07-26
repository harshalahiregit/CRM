<?php

use App\Http\Controllers\Api\Hr\EmployeeTrainingController;
use App\Http\Controllers\Api\Hr\TrainingAssessmentController;
use App\Http\Controllers\Api\Hr\TrainingAttendanceController;
use App\Http\Controllers\Api\Hr\TrainingCategoryController;
use App\Http\Controllers\Api\Hr\TrainingCertificateController;
use App\Http\Controllers\Api\Hr\TrainingCompletionController;
use App\Http\Controllers\Api\Hr\TrainingProgramController;
use App\Http\Controllers\Api\Hr\TrainingProviderController;
use App\Http\Controllers\Api\Hr\TrainingQuizController;
use App\Http\Controllers\Api\Hr\TrainingReportController;
use App\Http\Controllers\Api\Hr\TrainingSessionController;
use App\Http\Controllers\Api\Hr\TrainingTypeController;
use Illuminate\Support\Facades\Route;

// ── Learning & Development — Phases 1-7 (complete module). Sanctum, /hr/learning. ──
Route::middleware('auth:sanctum')->prefix('hr/learning')->group(function () {

    // Training Categories
    Route::get('/categories',              [TrainingCategoryController::class, 'index']);
    Route::post('/categories',             [TrainingCategoryController::class, 'store']);
    Route::put('/categories/{id}',         [TrainingCategoryController::class, 'update']);
    Route::patch('/categories/{id}/status',[TrainingCategoryController::class, 'updateStatus']);

    // Training Types
    Route::get('/types',              [TrainingTypeController::class, 'index']);
    Route::post('/types',             [TrainingTypeController::class, 'store']);
    Route::put('/types/{id}',         [TrainingTypeController::class, 'update']);
    Route::patch('/types/{id}/status',[TrainingTypeController::class, 'updateStatus']);

    // Training Providers
    Route::get('/providers',              [TrainingProviderController::class, 'index']);
    Route::post('/providers',             [TrainingProviderController::class, 'store']);
    Route::put('/providers/{id}',         [TrainingProviderController::class, 'update']);
    Route::patch('/providers/{id}/status',[TrainingProviderController::class, 'updateStatus']);

    // Training Programs (Phase 2)
    Route::get('/programs',              [TrainingProgramController::class, 'index']);
    Route::get('/programs/{id}',         [TrainingProgramController::class, 'show'])->whereNumber('id');
    Route::post('/programs',             [TrainingProgramController::class, 'store']);
    Route::put('/programs/{id}',         [TrainingProgramController::class, 'update']);
    Route::patch('/programs/{id}/status',[TrainingProgramController::class, 'updateStatus']);

    // Training Sessions & Calendar (Phase 3)
    Route::get('/sessions',              [TrainingSessionController::class, 'index']);
    Route::get('/sessions/calendar',     [TrainingSessionController::class, 'calendar']);
    Route::get('/sessions/{id}',         [TrainingSessionController::class, 'show'])->whereNumber('id');
    Route::post('/sessions',             [TrainingSessionController::class, 'store']);
    Route::put('/sessions/{id}',         [TrainingSessionController::class, 'update']);
    Route::patch('/sessions/{id}/status',[TrainingSessionController::class, 'updateStatus']);

    // Employee Training Assignments (Phase 4)
    Route::get('/assignments',                    [EmployeeTrainingController::class, 'index']);
    Route::get('/assignments/history',            [EmployeeTrainingController::class, 'history']);
    Route::get('/assignments/employee/{employee}',[EmployeeTrainingController::class, 'forEmployee'])->whereNumber('employee');
    Route::get('/assignments/{id}',               [EmployeeTrainingController::class, 'show'])->whereNumber('id');
    Route::post('/assignments',                   [EmployeeTrainingController::class, 'store']);
    Route::patch('/assignments/{id}/start',       [EmployeeTrainingController::class, 'start'])->whereNumber('id');
    Route::patch('/assignments/{id}/complete',    [EmployeeTrainingController::class, 'complete'])->whereNumber('id');
    Route::patch('/assignments/{id}/cancel',      [EmployeeTrainingController::class, 'cancel'])->whereNumber('id');

    // Training Attendance (Phase 5) — separate from office attendance / SangoeTrack.
    Route::get('/attendance',                 [TrainingAttendanceController::class, 'index']);
    Route::get('/attendance/roster/{session}',[TrainingAttendanceController::class, 'roster'])->whereNumber('session');
    Route::get('/attendance/{id}',            [TrainingAttendanceController::class, 'show'])->whereNumber('id');
    Route::post('/attendance',                [TrainingAttendanceController::class, 'store']);
    Route::put('/attendance/{id}',            [TrainingAttendanceController::class, 'update'])->whereNumber('id');

    // Training Assessments (Phase 5)
    Route::get('/assessments',      [TrainingAssessmentController::class, 'index']);
    Route::get('/assessments/{id}', [TrainingAssessmentController::class, 'show'])->whereNumber('id');
    Route::post('/assessments',     [TrainingAssessmentController::class, 'store']);
    Route::put('/assessments/{id}', [TrainingAssessmentController::class, 'update'])->whereNumber('id');

    // Training Quizzes (Phase 5)
    Route::get('/quizzes',      [TrainingQuizController::class, 'index']);
    Route::get('/quizzes/{id}', [TrainingQuizController::class, 'show'])->whereNumber('id');
    Route::post('/quizzes',     [TrainingQuizController::class, 'store']);
    Route::put('/quizzes/{id}', [TrainingQuizController::class, 'update'])->whereNumber('id');

    // Training Certificates (Phase 6)
    Route::get('/certificates',              [TrainingCertificateController::class, 'index']);
    Route::get('/certificates/{id}',         [TrainingCertificateController::class, 'show'])->whereNumber('id');
    Route::get('/certificates/{id}/download',[TrainingCertificateController::class, 'download'])->whereNumber('id');
    Route::post('/certificates',             [TrainingCertificateController::class, 'store']);
    Route::post('/certificates/{id}/upload', [TrainingCertificateController::class, 'upload'])->whereNumber('id');
    Route::patch('/certificates/{id}/expire',[TrainingCertificateController::class, 'expire'])->whereNumber('id');

    // Training Completion (Phase 6) — read-only derived view.
    Route::get('/completion',                    [TrainingCompletionController::class, 'index']);
    Route::get('/completion/employee/{employee}',[TrainingCompletionController::class, 'forEmployee'])->whereNumber('employee');

    // Training Reports & Analytics (Phase 7) — read-only + CSV/PDF export.
    Route::get('/reports/filters',      [TrainingReportController::class, 'filterOptions']);
    Route::get('/reports/dashboard',    [TrainingReportController::class, 'dashboard']);
    Route::get('/reports/employees',    [TrainingReportController::class, 'employees']);
    Route::get('/reports/departments',  [TrainingReportController::class, 'departments']);
    Route::get('/reports/programs',     [TrainingReportController::class, 'programs']);
    Route::get('/reports/trainers',     [TrainingReportController::class, 'trainers']);
    Route::get('/reports/attendance',   [TrainingReportController::class, 'attendance']);
    Route::get('/reports/assessments',  [TrainingReportController::class, 'assessments']);
    Route::get('/reports/certificates', [TrainingReportController::class, 'certificates']);
    Route::get('/reports/completion',   [TrainingReportController::class, 'completion']);
    Route::get('/reports/trends',       [TrainingReportController::class, 'trends']);
    Route::get('/reports/export',       [TrainingReportController::class, 'export']);
});
