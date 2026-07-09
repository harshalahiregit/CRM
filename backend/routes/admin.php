<?php

use App\Http\Controllers\Api\Admin\StaffManagementController;
use Illuminate\Support\Facades\Route;

// ── Admin Only Routes (Sanctum + role:admin) ────────────────────────────
Route::middleware('auth:sanctum')->prefix('admin')->middleware('role:admin')->group(function () {

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
