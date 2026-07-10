<?php

use App\Http\Controllers\Api\Task\TaskChecklistController;
use App\Http\Controllers\Api\Task\TaskCommentController;
use App\Http\Controllers\Api\Task\TaskController;
use App\Http\Controllers\Api\Task\TaskTimerController;
use Illuminate\Support\Facades\Route;

// ── Task Module (owner: Shivam, Sanctum) ────────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('tasks')->group(function () {
    // Static segments BEFORE /{task} so they aren't captured as a task id.
    Route::get('/billable',                 [TaskController::class, 'billable']);
    Route::patch('/checklist/{item}/toggle', [TaskChecklistController::class, 'toggle']);

    Route::get('/',                 [TaskController::class, 'index']);
    Route::post('/',                [TaskController::class, 'store']);
    Route::get('/{task}',           [TaskController::class, 'show']);
    Route::put('/{task}',           [TaskController::class, 'update']);
    Route::delete('/{task}',        [TaskController::class, 'destroy']);
    Route::patch('/{task}/status',  [TaskController::class, 'updateStatus']);

    // Assignees / followers
    Route::post('/{task}/assignees', [TaskController::class, 'assignees']);
    Route::post('/{task}/followers', [TaskController::class, 'followers']);

    // Checklist
    Route::get('/{task}/checklist',  [TaskChecklistController::class, 'index']);
    Route::post('/{task}/checklist', [TaskChecklistController::class, 'store']);

    // Comments
    Route::get('/{task}/comments',   [TaskCommentController::class, 'index']);
    Route::post('/{task}/comments',  [TaskCommentController::class, 'store']);

    // Timers
    Route::post('/{task}/timer/start', [TaskTimerController::class, 'start']);
    Route::post('/{task}/timer/stop',  [TaskTimerController::class, 'stop']);
    Route::get('/{task}/total-time',   [TaskTimerController::class, 'total']);
});
