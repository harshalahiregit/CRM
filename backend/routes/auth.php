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
});

// ── Protected Auth + Dashboard Routes (Sanctum) ─────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);
    Route::get('/dashboard',    [DashboardController::class, 'index']);
});
