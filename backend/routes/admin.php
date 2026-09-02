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
    //
    // FIRST ADOPTER of the permission grid. These routes are already role:admin,
    // and an admin bypasses the grid, so today this changes nothing — which is
    // the point: it proves the wiring end to end without altering anyone's
    // access. Modules with real staff traffic move across afterwards, one at a
    // time, each on its own review.
    Route::get('/staff/stats',              [StaffManagementController::class, 'stats'])->middleware('permission:staff_mgmt,view_global');
    Route::get('/staff',                    [StaffManagementController::class, 'index'])->middleware('permission:staff_mgmt,view_global');
    Route::get('/staff/designations',       [StaffManagementController::class, 'designations'])->middleware('permission:staff_mgmt,view_global');
    Route::get('/staff/departments',        [StaffManagementController::class, 'departments'])->middleware('permission:staff_mgmt,view_global');
    Route::get('/staff/{id}',               [StaffManagementController::class, 'show'])->middleware('permission:staff_mgmt,view_global');
    Route::post('/staff',                   [StaffManagementController::class, 'store'])->middleware('permission:staff_mgmt,create');
    Route::put('/staff/{id}',               [StaffManagementController::class, 'update'])->middleware('permission:staff_mgmt,edit');
    Route::patch('/staff/{id}/toggle-status', [StaffManagementController::class, 'toggleStatus'])->middleware('permission:staff_mgmt,edit');
    Route::delete('/staff/{id}',            [StaffManagementController::class, 'destroy'])->middleware('permission:staff_mgmt,delete');

    // The three tabs beside Profile and Permissions. All of this data already
    // existed — last_login_at, user_sessions, audit_logs, the shared notes table
    // — and none of it was reachable from the staff screen.
    Route::get('/staff/{id}/account',    [StaffManagementController::class, 'account'])->middleware('permission:staff_mgmt,view_global');
    Route::get('/staff/{id}/activity',   [StaffManagementController::class, 'activity'])->middleware('permission:staff_mgmt,view_global');
    Route::get('/staff/{id}/notes',      [StaffManagementController::class, 'notes'])->middleware('permission:staff_mgmt,view_global');
    // Ending a session is not deactivating an account: signing a lost phone out
    // and locking somebody out of the company are different decisions.
    Route::post('/staff/{id}/sessions/revoke', [StaffManagementController::class, 'revokeSessions'])->middleware('permission:staff_mgmt,edit');
    Route::post('/staff/{id}/notes',     [StaffManagementController::class, 'addNote'])->middleware('permission:staff_mgmt,edit');
    Route::delete('/staff/{id}/notes/{noteId}', [StaffManagementController::class, 'deleteNote'])->middleware('permission:staff_mgmt,edit');
});
