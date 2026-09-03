<?php

use App\Http\Controllers\Api\Hrm\HrmAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SangoeTrack app compatibility
|--------------------------------------------------------------------------
|
| The attendance app calls /api/Hrm/* on track.sangoe.in. These routes let it
| call the CRM instead, with only its base URL changed.
|
| The app is the fragile side: 271 parse sites, and an unrecognised key renders
| BLANK rather than erroring. Rewriting that in Dart would be far riskier than
| answering in the shape it already expects, so the paths and payloads here
| deliberately mirror theirs rather than the CRM's own conventions.
|
| Responses go through HrmResponse, which reproduces their envelope — integer
| `status`, 200 even on refusal, 403 for validation, and 401 reserved for a
| genuinely dead session because the app treats it as destructive.
|
*/

Route::prefix('Hrm')->group(function () {
    // Open: somebody signing in has no token yet.
    Route::post('/login', [HrmAuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout',          [HrmAuthController::class, 'logout']);
        Route::post('/refresh',         [HrmAuthController::class, 'refresh']);
        Route::post('/change-password', [HrmAuthController::class, 'changePassword']);
    });
});
