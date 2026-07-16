<?php

use App\Http\Controllers\Api\Task\TaskChecklistController;
use App\Http\Controllers\Api\Task\TaskCommentController;
use App\Http\Controllers\Api\Task\TaskController;
use App\Http\Controllers\Api\Task\TaskFileController;
use App\Http\Controllers\Api\Task\TaskReminderController;
use App\Http\Controllers\Api\Task\TaskStaffController;
use App\Http\Controllers\Api\Task\TaskTemplateController;
use App\Http\Controllers\Api\Task\TaskTimerController;
use Illuminate\Support\Facades\Route;

// ── Task Module (owner: Shivam, Sanctum) ────────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('tasks')->group(function () {
    // Static segments BEFORE /{task} so they aren't captured as a task id.
    Route::get('/billable',                 [TaskController::class, 'billable']);
    Route::get('/stats',                    [TaskController::class, 'stats']);
    Route::get('/staff',                    [TaskStaffController::class, 'index']);
    Route::post('/reorder',                 [TaskController::class, 'reorder']);
    Route::post('/bulk',                    [TaskController::class, 'bulk']);
    Route::patch('/checklist/{item}/toggle', [TaskChecklistController::class, 'toggle']);

    // Reusable checklist templates (not task-scoped).
    Route::get('/templates',              [TaskTemplateController::class, 'index']);
    Route::post('/templates',             [TaskTemplateController::class, 'store']);
    Route::delete('/templates/{template}', [TaskTemplateController::class, 'destroy']);

    Route::get('/',                 [TaskController::class, 'index']);
    Route::post('/',                [TaskController::class, 'store']);
    Route::get('/{task}',           [TaskController::class, 'show']);
    Route::put('/{task}',           [TaskController::class, 'update']);
    Route::delete('/{task}',        [TaskController::class, 'destroy']);
    Route::patch('/{task}/status',  [TaskController::class, 'updateStatus']);
    Route::post('/{task}/copy',     [TaskController::class, 'copy']);

    // Assignees / followers
    Route::post('/{task}/assignees', [TaskController::class, 'assignees']);
    Route::post('/{task}/followers', [TaskController::class, 'followers']);

    // Checklist (+ templates applied to / saved from this task)
    Route::get('/{task}/checklist',  [TaskChecklistController::class, 'index']);
    Route::post('/{task}/checklist', [TaskChecklistController::class, 'store']);
    Route::post('/{task}/checklist/apply-template', [TaskTemplateController::class, 'apply']);
    Route::post('/{task}/checklist/save-template',  [TaskTemplateController::class, 'saveFromTask']);

    // Attachments — download is authenticated + tenant-scoped, files are never public.
    Route::get('/{task}/files',              [TaskFileController::class, 'index']);
    Route::post('/{task}/files',             [TaskFileController::class, 'store']);
    Route::get('/{task}/files/{file}/download', [TaskFileController::class, 'download']);
    Route::delete('/{task}/files/{file}',    [TaskFileController::class, 'destroy']);

    // Reminders
    Route::get('/{task}/reminders',              [TaskReminderController::class, 'index']);
    Route::post('/{task}/reminders',             [TaskReminderController::class, 'store']);
    Route::delete('/{task}/reminders/{reminder}', [TaskReminderController::class, 'destroy']);

    // Comments
    Route::get('/{task}/comments',   [TaskCommentController::class, 'index']);
    Route::post('/{task}/comments',  [TaskCommentController::class, 'store']);

    // Timers
    Route::post('/{task}/timer/start', [TaskTimerController::class, 'start']);
    Route::post('/{task}/timer/stop',  [TaskTimerController::class, 'stop']);
    Route::get('/{task}/total-time',   [TaskTimerController::class, 'total']);
});
