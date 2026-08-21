<?php

use App\Http\Controllers\Api\Shared\KickoffMeetingController;
use App\Http\Controllers\Api\Shared\KickoffMeetingLinkController;
use App\Http\Controllers\Api\Shared\MeetingLinkController;
use App\Http\Controllers\Api\Shared\MeetingPlatformController;
use App\Http\Controllers\Api\Shared\MeetingTypeSettingsController;
use App\Http\Controllers\Api\Shared\PollController;
use App\Http\Controllers\Api\Shared\PublicKickoffController;
use App\Http\Controllers\Api\Shared\ReactionController;
use Illuminate\Support\Facades\Route;

// ── PUBLIC — vendor acknowledgement of kickoff minutes ──────────────────
// No auth by design: the vendor's signatory has no CRM login. The 48-char token
// is the bearer credential. Rate-limited per IP; the service burns the token on
// acknowledgement so the link is single-use.
Route::prefix('kickoff/ack')->middleware('throttle:60,1')->group(function () {
    Route::get('/{token}', [PublicKickoffController::class, 'show']);
    // Read the minutes before signing them. Same token, same throttle, but
    // read-only and repeatable — it is never burned, so the vendor can reopen
    // the PDF while deciding. Declared before nothing else can shadow it.
    Route::get('/{token}/mom', [PublicKickoffController::class, 'mom']);
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
    Route::get('/meetings/stats', [KickoffMeetingController::class, 'stats']);
    Route::get('/meetings/dashboard', [KickoffMeetingController::class, 'dashboard']);
    // Declared before /meetings/{kickoffMeeting} so the wildcard cannot swallow it.
    Route::get('/meetings/carry-forward', [KickoffMeetingController::class, 'carryForward']);
    Route::get('/meetings/history', [KickoffMeetingController::class, 'history']);
    Route::get('/meeting-types', [KickoffMeetingController::class, 'meetingTypes']);
    // Admin Types/Templates settings — reads open to staff, writes admin-gated
    // in-controller. Layers over config/meetings.php (MeetingTypeCatalog).
    Route::get('/meeting-type-settings', [MeetingTypeSettingsController::class, 'index']);
    Route::post('/meeting-type-settings', [MeetingTypeSettingsController::class, 'store']);
    Route::put('/meeting-type-settings/{meetingType}', [MeetingTypeSettingsController::class, 'update']);
    Route::delete('/meeting-type-settings/{meetingType}', [MeetingTypeSettingsController::class, 'destroy']);
    // AI layer (Meeting.docx §18) — suggest agenda before, summarise minutes after.
    Route::post('/ai/suggest-agenda', [KickoffMeetingController::class, 'aiSuggestAgenda']);
    Route::post('/meetings/{kickoffMeeting}/ai-summary', [KickoffMeetingController::class, 'aiSummary']);
    Route::get('/projects', [KickoffMeetingController::class, 'projects']);
    Route::get('/projects/{project}/meetings', [KickoffMeetingController::class, 'projectMeetings'])->where('project', '[0-9]+');
    // Live vendor governance status for the §4 template auto-load.
    Route::get('/vendor-status', [KickoffMeetingController::class, 'vendorStatus']);
    Route::get('/meetings', [KickoffMeetingController::class, 'index']);
    Route::post('/meetings', [KickoffMeetingController::class, 'store']);
    Route::get('/meetings/{kickoffMeeting}', [KickoffMeetingController::class, 'show']);
    Route::put('/meetings/{kickoffMeeting}', [KickoffMeetingController::class, 'update']);
    Route::post('/meetings/{kickoffMeeting}/transition', [KickoffMeetingController::class, 'transition']);
    Route::patch('/meetings/{kickoffMeeting}/attendance', [KickoffMeetingController::class, 'attendance']);
    Route::post('/meetings/{kickoffMeeting}/remind', [KickoffMeetingController::class, 'remind']);
    Route::post('/meetings/{kickoffMeeting}/mom', [KickoffMeetingController::class, 'uploadMom']);
    Route::post('/meetings/{kickoffMeeting}/mom/generate', [KickoffMeetingController::class, 'generateMom']);
    Route::get('/meetings/{kickoffMeeting}/mom', [KickoffMeetingController::class, 'momFile']);
    // MOM approval workflow — submit → approve/return → (publish = distribute).
    Route::post('/meetings/{kickoffMeeting}/mom/submit', [KickoffMeetingController::class, 'momSubmit']);
    Route::post('/meetings/{kickoffMeeting}/mom/decide', [KickoffMeetingController::class, 'momDecide']);
    Route::post('/meetings/{kickoffMeeting}/mom/revise', [KickoffMeetingController::class, 'momRevise']);
    Route::post('/meetings/{kickoffMeeting}/publish', [KickoffMeetingController::class, 'publish']);
    // Action Engine — progress a single MOM action + read its evidence.
    Route::post('/meetings/{kickoffMeeting}/mom-items/{momItem}/progress', [KickoffMeetingController::class, 'progressAction']);
    Route::post('/meetings/{kickoffMeeting}/mom-items/{momItem}/push-task', [KickoffMeetingController::class, 'pushActionTask']);
    Route::get('/meetings/{kickoffMeeting}/mom-items/{momItem}/evidence', [KickoffMeetingController::class, 'actionEvidence']);
    // Issue register — progress lifecycle + escalate to an Incident.
    Route::post('/meetings/{kickoffMeeting}/issues/{meetingIssue}/progress', [KickoffMeetingController::class, 'progressIssue']);
    Route::post('/meetings/{kickoffMeeting}/issues/{meetingIssue}/convert', [KickoffMeetingController::class, 'convertIssue']);
    Route::post('/meetings/{kickoffMeeting}/issues/{meetingIssue}/convert-task', [KickoffMeetingController::class, 'convertIssueTask']);
    Route::delete('/meetings/{kickoffMeeting}', [KickoffMeetingController::class, 'destroy']);

    // ── Online meeting link generation ────────────────────────────────────────
    Route::post('/meetings/{kickoffMeeting}/generate-link', [KickoffMeetingLinkController::class, 'generate']);
    Route::get('/meetings/{kickoffMeeting}/link', [KickoffMeetingLinkController::class, 'show']);
});

// ── Polls (shared: Tasks / Helpdesk / Projects) ─────────────────────────
// One poll engine for every editor's "Poll" button. Access is NOT decided
// here — PollService delegates to the module that owns each poll's context,
// so staff-only is the outer guard and per-item visibility is the inner one.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('polls')->group(function () {
    Route::get('/', [PollController::class, 'index']);   // ?context_type=&context_id=
    Route::post('/', [PollController::class, 'store']);
    Route::post('/{poll}/vote', [PollController::class, 'vote']);
    Route::post('/{poll}/close', [PollController::class, 'close']);
    Route::delete('/{poll}', [PollController::class, 'destroy']);
})->where(['poll' => '[0-9]+']);

// ── Ad-hoc meeting links for the message composers ──────────────────────
// The composer's "Meeting" button mints a Zoom / Google Meet / Jitsi link to
// drop into a message. Distinct from /kickoff (which schedules a meeting record).
Route::middleware(['auth:sanctum', 'role:admin,staff'])->group(function () {
    Route::post('/meeting-links', [MeetingLinkController::class, 'store']);
});

// ── Emoji reactions on message threads (task/ticket/project) ────────────
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('reactions')->group(function () {
    Route::get('/', [ReactionController::class, 'index']);    // ?subject_type=&subject_ids[]=
    Route::post('/toggle', [ReactionController::class, 'toggle']);
});

// ── Tenant default meeting platform ─────────────────────────────────────
// Sits outside the /kickoff prefix: it is a tenant-wide preference, not a
// property of any one meeting. Reading it is open to staff (the Kickoff form
// needs it); only an admin may change it.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('settings')->group(function () {
    Route::get('/meeting-platform', [MeetingPlatformController::class, 'show']);
    Route::put('/meeting-platform', [MeetingPlatformController::class, 'update']);
});
