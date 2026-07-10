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

    // Milestone update/delete are keyed by milestone id (not nested) — declare
    // BEFORE /{project} so "milestones" isn't captured as a project id.
    Route::put('/milestones/{milestone}',    [ProjectMilestoneController::class, 'update']);
    Route::delete('/milestones/{milestone}', [ProjectMilestoneController::class, 'destroy']);

    Route::get('/{project}',           [ProjectController::class, 'show']);
    Route::put('/{project}',           [ProjectController::class, 'update']);
    Route::delete('/{project}',        [ProjectController::class, 'destroy']);
    Route::patch('/{project}/status',  [ProjectController::class, 'updateStatus']);
    Route::get('/{project}/progress',  [ProjectController::class, 'progress']);

    // Members
    Route::post('/{project}/members',  [ProjectController::class, 'members']);

    // Milestones (nested list/create)
    Route::get('/{project}/milestones',  [ProjectMilestoneController::class, 'index']);
    Route::post('/{project}/milestones', [ProjectMilestoneController::class, 'store']);

    // Files
    Route::get('/{project}/files',                     [ProjectFileController::class, 'index']);
    Route::post('/{project}/files',                    [ProjectFileController::class, 'store']);
    Route::get('/{project}/files/{file}/download',     [ProjectFileController::class, 'download']);
    Route::delete('/{project}/files/{file}',           [ProjectFileController::class, 'destroy']);
});
