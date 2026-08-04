<?php

use App\Http\Controllers\Api\Hr\SurveyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| #26 — Employee Survey. Sanctum, /hr/surveys.
|--------------------------------------------------------------------------
| Its own file rather than appended to hr.php, matching how leave.php and
| learning.php already separate a whole module.
|
| Static segments precede {id} throughout, or route-model binding swallows them.
*/
Route::middleware('auth:sanctum')->prefix('hr/surveys')->group(function () {

    Route::get('/meta',      [SurveyController::class, 'meta']);
    Route::get('/dashboard', [SurveyController::class, 'dashboard']);

    // Categories
    Route::get('/categories',           [SurveyController::class, 'categories']);
    Route::post('/categories',          [SurveyController::class, 'saveCategory']);
    Route::put('/categories/{id}',      [SurveyController::class, 'saveCategory'])->whereNumber('id');
    Route::delete('/categories/{id}',   [SurveyController::class, 'destroyCategory'])->whereNumber('id');

    // Surveys addressed to one employee — no HR permission; it is their survey.
    Route::get('/employees/{employeeId}/available', [SurveyController::class, 'availableFor'])->whereNumber('employeeId');

    Route::get('/',                 [SurveyController::class, 'index']);
    Route::post('/',                [SurveyController::class, 'save']);
    Route::get('/{id}',             [SurveyController::class, 'show'])->whereNumber('id');
    Route::put('/{id}',             [SurveyController::class, 'save'])->whereNumber('id');
    Route::delete('/{id}',          [SurveyController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/publish',    [SurveyController::class, 'publish'])->whereNumber('id');
    Route::post('/{id}/close',      [SurveyController::class, 'close'])->whereNumber('id');
    Route::post('/{id}/respond',    [SurveyController::class, 'respond'])->whereNumber('id');

    Route::get('/{id}/analytics',   [SurveyController::class, 'analytics'])->whereNumber('id');
    Route::get('/{id}/responses',   [SurveyController::class, 'responses'])->whereNumber('id');
    Route::get('/{id}/export',      [SurveyController::class, 'export'])->whereNumber('id');
});
