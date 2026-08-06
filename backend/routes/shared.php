<?php

use App\Http\Controllers\Api\Shared\KickoffMeetingController;
use App\Http\Controllers\Api\Shared\KickoffMeetingLinkController;
use App\Http\Controllers\Api\Shared\MeetingPlatformController;
use App\Http\Controllers\Api\Shared\PollController;
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

    // ── Online meeting link generation ────────────────────────────────────────
    Route::post('/meetings/{kickoffMeeting}/generate-link', [KickoffMeetingLinkController::class, 'generate']);
    Route::get('/meetings/{kickoffMeeting}/link',           [KickoffMeetingLinkController::class, 'show']);
});

// ── Polls (shared: Tasks / Helpdesk / Projects) ─────────────────────────
// One poll engine for every editor's "Poll" button. Access is NOT decided
// here — PollService delegates to the module that owns each poll's context,
// so staff-only is the outer guard and per-item visibility is the inner one.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('polls')->group(function () {
    Route::get('/',              [PollController::class, 'index']);   // ?context_type=&context_id=
    Route::post('/',             [PollController::class, 'store']);
    Route::post('/{poll}/vote',  [PollController::class, 'vote']);
    Route::post('/{poll}/close', [PollController::class, 'close']);
    Route::delete('/{poll}',     [PollController::class, 'destroy']);
})->where(['poll' => '[0-9]+']);

// ── Tenant default meeting platform ─────────────────────────────────────
// Sits outside the /kickoff prefix: it is a tenant-wide preference, not a
// property of any one meeting. Reading it is open to staff (the Kickoff form
// needs it); only an admin may change it.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('settings')->group(function () {
    Route::get('/meeting-platform', [MeetingPlatformController::class, 'show']);
    Route::put('/meeting-platform', [MeetingPlatformController::class, 'update']);
});
