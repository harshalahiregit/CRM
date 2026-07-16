<?php

use App\Http\Controllers\Api\Project\ProjectController;
use App\Http\Controllers\Api\Project\ProjectFileController;
use App\Http\Controllers\Api\Project\ProjectMilestoneController;
use Illuminate\Support\Facades\Route;

// ── Projects Module (owner: Shivam, Sanctum) ────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('projects')->group(function () {
    Route::get('/',                    [ProjectController::class, 'index']);
    Route::post('/',                   [ProjectController::class, 'store']);

    // Static segments BEFORE /{project} so they aren't captured as a project id.
    Route::get('/staff',               [ProjectController::class, 'staff']);
    Route::get('/customers',           [ProjectController::class, 'customers']);

    // Milestone update/delete are keyed by milestone id (not nested) — declare
    // BEFORE /{project} so "milestones" isn't captured as a project id.
    Route::put('/milestones/{milestone}',    [ProjectMilestoneController::class, 'update']);
    Route::delete('/milestones/{milestone}', [ProjectMilestoneController::class, 'destroy']);

    Route::get('/{project}',           [ProjectController::class, 'show']);
    Route::put('/{project}',           [ProjectController::class, 'update']);
    Route::delete('/{project}',        [ProjectController::class, 'destroy']);
    Route::patch('/{project}/status',  [ProjectController::class, 'updateStatus']);
    Route::get('/{project}/progress',  [ProjectController::class, 'progress']);
    Route::post('/{project}/copy',     [ProjectController::class, 'copy']);
    Route::post('/{project}/pin',      [ProjectController::class, 'pin']);

    // Members
    Route::post('/{project}/members',  [ProjectController::class, 'members']);

    // Notes / Activity / Timesheets tabs
    Route::get('/{project}/notes',          [ProjectController::class, 'notes']);
    Route::post('/{project}/notes',         [ProjectController::class, 'storeNote']);
    Route::delete('/{project}/notes/{note}', [ProjectController::class, 'destroyNote']);
    Route::get('/{project}/activity',       [ProjectController::class, 'activity']);
    Route::get('/{project}/timesheets',     [ProjectController::class, 'timesheets']);

    // Integration 3a: tickets linked to this project
    Route::get('/{project}/tickets',   [ProjectController::class, 'tickets']);

    // Milestones (nested list/create)
    Route::get('/{project}/milestones',  [ProjectMilestoneController::class, 'index']);
    Route::post('/{project}/milestones', [ProjectMilestoneController::class, 'store']);

    // Files
    Route::get('/{project}/files',                     [ProjectFileController::class, 'index']);
    Route::post('/{project}/files',                    [ProjectFileController::class, 'store']);
    Route::get('/{project}/files/{file}/download',     [ProjectFileController::class, 'download']);
    Route::delete('/{project}/files/{file}',           [ProjectFileController::class, 'destroy']);
});
