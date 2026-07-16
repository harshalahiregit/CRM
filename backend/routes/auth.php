<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\TagController;
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

    // ── In-app notifications (header bell) — scoped to the current user ──
    Route::get('/notifications',                [NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read',    [NotificationController::class, 'markRead'])->where('id', '[0-9]+');
    Route::post('/notifications/read-all',      [NotificationController::class, 'markAllRead']);

    // ── Tags — workspace-wide, shared by every taggable module ──────────
    // Lives here rather than in a module route file precisely because no single
    // module owns them. Attaching tags to a record is done on that record's own
    // endpoint (e.g. PUT /tasks/{id} with tags[]).
    Route::get('/tags',           [TagController::class, 'index']);
    Route::put('/tags/{tag}',     [TagController::class, 'update'])->where('tag', '[0-9]+');
    Route::delete('/tags/{tag}',  [TagController::class, 'destroy'])->where('tag', '[0-9]+');

    // ── Advanced Status Manager — {type} is task|project ────────────────
    // Shared for the same reason as tags: both modules need identical behaviour
    // and one screen configures both lists. Reads are open (every status
    // dropdown needs them); writes are admin-only, enforced in the controller.
    Route::get('/statuses/{type}',              [StatusController::class, 'index']);
    Route::post('/statuses/{type}',             [StatusController::class, 'store']);
    Route::post('/statuses/{type}/reorder',     [StatusController::class, 'reorder']);
    Route::put('/statuses/{type}/{id}',         [StatusController::class, 'update'])->where('id', '[0-9]+');
    Route::delete('/statuses/{type}/{id}',      [StatusController::class, 'destroy'])->where('id', '[0-9]+');
});
