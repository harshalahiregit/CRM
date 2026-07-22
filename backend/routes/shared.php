<?php

use App\Http\Controllers\Api\Shared\KickoffMeetingController;
use App\Http\Controllers\Api\Shared\PublicKickoffController;
use Illuminate\Support\Facades\Route;

// ── PUBLIC — vendor acknowledgement of kickoff minutes ──────────────────
// No auth by design: the vendor's signatory has no CRM login. The 48-char token
// is the bearer credential. Rate-limited per IP; the service burns the token on
// acknowledgement so the link is single-use.
Route::prefix('kickoff/ack')->middleware('throttle:60,1')->group(function () {
    Route::get('/{token}',  [PublicKickoffController::class, 'show']);
    Route::post('/{token}', [PublicKickoffController::class, 'acknowledge']);
})->where(['token' => '[A-Za-z0-9]{20,64}']);

// ── Shared engine (Sanctum + role:admin,staff) ──────────────────────────
// Kickoff meetings are a SHARED entity — they attach polymorphically to any
// allowlisted subject (vendor/onboarding now, Shivam's projects later), so this
// is not a TPV route group even though TPV is the first consumer.
//
// NOTE: both middleware must go in ONE ->middleware([...]) call — chaining a
// second ->middleware() replaces the first and silently drops auth:sanctum.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('kickoff')->group(function () {
    Route::get('/meetings/stats',              [KickoffMeetingController::class, 'stats']);
    Route::get('/meetings',                    [KickoffMeetingController::class, 'index']);
    Route::post('/meetings',                   [KickoffMeetingController::class, 'store']);
    Route::get('/meetings/{kickoffMeeting}',   [KickoffMeetingController::class, 'show']);
    Route::put('/meetings/{kickoffMeeting}',   [KickoffMeetingController::class, 'update']);
    Route::post('/meetings/{kickoffMeeting}/transition', [KickoffMeetingController::class, 'transition']);
    Route::patch('/meetings/{kickoffMeeting}/attendance', [KickoffMeetingController::class, 'attendance']);
    Route::post('/meetings/{kickoffMeeting}/remind',      [KickoffMeetingController::class, 'remind']);
    Route::post('/meetings/{kickoffMeeting}/mom',        [KickoffMeetingController::class, 'uploadMom']);
    Route::post('/meetings/{kickoffMeeting}/mom/generate', [KickoffMeetingController::class, 'generateMom']);
    Route::get('/meetings/{kickoffMeeting}/mom',         [KickoffMeetingController::class, 'momFile']);
    Route::post('/meetings/{kickoffMeeting}/publish',    [KickoffMeetingController::class, 'publish']);
    Route::delete('/meetings/{kickoffMeeting}', [KickoffMeetingController::class, 'destroy']);
});
