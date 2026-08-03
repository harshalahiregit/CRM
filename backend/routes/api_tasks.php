<?php

use App\Http\Controllers\Api\Task\TaskChecklistController;
use App\Http\Controllers\Api\Task\TaskCommentController;
use App\Http\Controllers\Api\Task\TaskConfigController;
use App\Http\Controllers\Api\Task\TaskController;
use App\Http\Controllers\Api\Task\TaskFileController;
use App\Http\Controllers\Api\Task\TaskReminderController;
use App\Http\Controllers\Api\Task\TaskStaffController;
use App\Http\Controllers\Api\Task\TaskSubtaskController;
use App\Http\Controllers\Api\Task\TaskTemplateController;
use App\Http\Controllers\Api\Task\TaskTimerController;
use Illuminate\Support\Facades\Route;

// ── Task Module (owner: Shivam, Sanctum) ────────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
//
// `role:admin,staff` is the whole-module barrier: portal roles (client, vendor,
// third_party_vendor) are never staff, never assignees and never followers, so
// they have no business in internal task management. Guarding the GROUP rather
// than each endpoint means a new task route is protected the day it is added —
// which is how the earlier leaks (a per-controller guard simply forgotten on
// /stats and /staff) happened in the first place.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('tasks')->group(function () {
    // Static segments BEFORE /{task} so they aren't captured as a task id.
    Route::get('/billable',                 [TaskController::class, 'billable']);
    Route::get('/stats',                    [TaskController::class, 'stats']);
    Route::get('/staff',                    [TaskStaffController::class, 'index']);
    Route::get('/vendors',                  [TaskStaffController::class, 'vendors']);
    Route::post('/reorder',                 [TaskController::class, 'reorder']);
    Route::post('/bulk',                    [TaskController::class, 'bulk']);
    Route::patch('/checklist/{item}/toggle', [TaskChecklistController::class, 'toggle']);
    // Edit / (re)assign a single checklist item.
    Route::patch('/checklist/{item}',        [TaskChecklistController::class, 'update']);
    // Trash: list soft-deleted tasks and put one back. Before /{task} so "trash"
    // isn't captured as a task id.
    Route::get('/trash',                    [TaskController::class, 'trash']);

    // Notification / email switches (read: any staff, write: admin).
    Route::get('/settings',  [TaskConfigController::class, 'index']);
    Route::put('/settings',  [TaskConfigController::class, 'update']);

    // Reusable checklist templates (not task-scoped).
    Route::get('/templates',              [TaskTemplateController::class, 'index']);
    Route::post('/templates',             [TaskTemplateController::class, 'store']);
    Route::delete('/templates/{template}', [TaskTemplateController::class, 'destroy']);

    Route::get('/',                 [TaskController::class, 'index']);
    Route::post('/',                [TaskController::class, 'store']);
    Route::get('/{task}',           [TaskController::class, 'show']);
    Route::put('/{task}',           [TaskController::class, 'update']);
    Route::delete('/{task}',        [TaskController::class, 'destroy']);
    Route::post('/{task}/restore',  [TaskController::class, 'restore']);
    Route::patch('/{task}/status',  [TaskController::class, 'updateStatus']);
    Route::post('/{task}/copy',     [TaskController::class, 'copy']);

    // Assignees / followers
    Route::post('/{task}/assignees', [TaskController::class, 'assignees']);
    Route::post('/{task}/followers', [TaskController::class, 'followers']);

    // Subtasks — the recursive tree under a task. `tree` returns every level in
    // one response; `move` re-parents a branch (parent_id null pops it to the top).
    Route::get('/{task}/tree',      [TaskSubtaskController::class, 'tree']);
    Route::post('/{task}/subtasks', [TaskSubtaskController::class, 'store']);
    Route::patch('/{task}/move',    [TaskSubtaskController::class, 'move']);

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
