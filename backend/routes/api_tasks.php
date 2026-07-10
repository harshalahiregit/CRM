<?php

use App\Http\Controllers\Api\Task\TaskController;
use Illuminate\Support\Facades\Route;

// ── Task Module (owner: Shivam, Sanctum) ────────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('tasks')->group(function () {
    Route::get('/',                 [TaskController::class, 'index']);
    Route::post('/',                [TaskController::class, 'store']);
    Route::get('/{task}',           [TaskController::class, 'show']);
    Route::put('/{task}',           [TaskController::class, 'update']);
    Route::delete('/{task}',        [TaskController::class, 'destroy']);
    Route::patch('/{task}/status',  [TaskController::class, 'updateStatus']);
});
