<?php

use App\Http\Controllers\Api\Admin\StaffManagementController;
use Illuminate\Support\Facades\Route;

// ── Admin Only Routes (Sanctum + role:admin) ────────────────────────────
//
// NOTE: both middleware must go in ONE ->middleware([...]) call. Chaining a
// second ->middleware() REPLACES the first and silently drops auth:sanctum —
// which left every route here unauthenticated, so role:admin saw a null user
// and returned 401 "Unauthenticated" to everyone, admins included. The frontend
// treats any 401 as an expired token, so opening Staff Management logged the
// user straight out.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {

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
