<?php

use App\Http\Controllers\Api\Notifications\NotificationController;
use App\Http\Controllers\Api\Notifications\NotificationQueueController;
use App\Http\Controllers\Api\Notifications\NotificationRuleController;
use App\Http\Controllers\Api\Notifications\NotificationTemplateController;
use Illuminate\Support\Facades\Route;

// ── Central Notification & Reminder Engine (platform foundation). Sanctum, /hr/notifications. ──
Route::middleware('auth:sanctum')->prefix('hr/notifications')->group(function () {

    // Recipient feed — navbar bell, Notification Center, history
    Route::get('/',                 [NotificationController::class, 'index']);
    Route::get('/bell',             [NotificationController::class, 'bell']);
    Route::get('/unread-count',     [NotificationController::class, 'unreadCount']);
    Route::get('/stats',            [NotificationController::class, 'stats']);
    Route::get('/catalog',          [NotificationTemplateController::class, 'catalog']);
    Route::post('/mark-all-read',   [NotificationController::class, 'markAllRead']);
    Route::get('/employee/{employeeId}', [NotificationController::class, 'forEmployee'])->whereNumber('employeeId');
    Route::get('/{id}',             [NotificationController::class, 'show'])->whereNumber('id');
    Route::patch('/{id}/read',      [NotificationController::class, 'markRead'])->whereNumber('id');
    Route::post('/{id}/resend',     [NotificationController::class, 'resend'])->whereNumber('id');

    // Templates
    Route::get('/templates',              [NotificationTemplateController::class, 'index']);
    Route::post('/templates',             [NotificationTemplateController::class, 'store']);
    Route::post('/templates/seed',        [NotificationTemplateController::class, 'seedDefaults']);
    Route::put('/templates/{id}',         [NotificationTemplateController::class, 'update'])->whereNumber('id');
    Route::patch('/templates/{id}/status',[NotificationTemplateController::class, 'updateStatus'])->whereNumber('id');

    // Reminder / escalation rules
    Route::get('/rules',              [NotificationRuleController::class, 'index']);
    Route::post('/rules',             [NotificationRuleController::class, 'store']);
    Route::put('/rules/{id}',         [NotificationRuleController::class, 'update'])->whereNumber('id');
    Route::patch('/rules/{id}/status',[NotificationRuleController::class, 'updateStatus'])->whereNumber('id');

    // Delivery queue monitor
    Route::get('/queue',              [NotificationQueueController::class, 'index']);
    Route::get('/queue/failed',       [NotificationQueueController::class, 'failed']);
    Route::get('/queue/stats',        [NotificationQueueController::class, 'stats']);
    Route::post('/queue/process',     [NotificationQueueController::class, 'process']);
    Route::post('/queue/{id}/retry',  [NotificationQueueController::class, 'retry'])->whereNumber('id');
});
