<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use Illuminate\Support\Facades\Route;

// ── Public Auth Routes ──────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/login',           [AuthController::class, 'login'])->name('login');
    Route::post('/register',        [AuthController::class, 'register']);
    Route::post('/register/vendor', [AuthController::class, 'registerVendor']);
    Route::post('/register/tpv',    [AuthController::class, 'registerTPV']);
    Route::post('/register/client', [AuthController::class, 'registerClient']);
    Route::post('/register/company', [AuthController::class, 'registerCompany'])->middleware('throttle:10,1');
});

// ── Protected Auth + Dashboard Routes (Sanctum) ─────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::get('/dashboard',    [DashboardController::class, 'index']);

    // Session management (Phase 3)
    Route::get('/auth/sessions',                 [AuthController::class, 'sessions']);
    Route::delete('/auth/sessions/{session}',    [AuthController::class, 'revokeSession']);
    Route::post('/auth/sessions/logout-others',  [AuthController::class, 'logoutOthers']);
    Route::post('/auth/heartbeat',               [AuthController::class, 'heartbeat']);

    // Admin: force a user off every device.
    Route::post('/admin/users/{user}/force-logout', [AuthController::class, 'forceLogout'])
        ->middleware('role:admin');
});
