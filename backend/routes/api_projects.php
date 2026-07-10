<?php

use App\Http\Controllers\Api\Project\ProjectController;
use Illuminate\Support\Facades\Route;

// ── Projects Module (owner: Shivam, Sanctum) ────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('projects')->group(function () {
    Route::get('/',                    [ProjectController::class, 'index']);
    Route::post('/',                   [ProjectController::class, 'store']);
    Route::get('/{project}',           [ProjectController::class, 'show']);
    Route::put('/{project}',           [ProjectController::class, 'update']);
    Route::delete('/{project}',        [ProjectController::class, 'destroy']);
    Route::patch('/{project}/status',  [ProjectController::class, 'updateStatus']);
    Route::get('/{project}/progress',  [ProjectController::class, 'progress']);
});
