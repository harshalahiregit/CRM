<?php

use App\Http\Controllers\Api\Helpdesk\HelpdeskAgentController;
use App\Http\Controllers\Api\Helpdesk\HelpdeskDashboardController;
use App\Http\Controllers\Api\Helpdesk\HelpdeskFeedbackController;
use App\Http\Controllers\Api\Helpdesk\HelpdeskMeController;
use App\Http\Controllers\Api\Helpdesk\HelpdeskSettingsController;
use App\Http\Controllers\Api\Helpdesk\HelpdeskWidgetController;
use App\Http\Controllers\Api\Helpdesk\PublicHelpdeskController;
use App\Http\Controllers\Api\Helpdesk\TicketCollaborationController;
use App\Http\Controllers\Api\Helpdesk\TicketController;
use App\Http\Controllers\Api\Helpdesk\TicketReplyController;
use App\Http\Controllers\Api\Helpdesk\TicketTagController;
use App\Http\Controllers\Api\Helpdesk\CannedResponseController;
use App\Http\Controllers\Api\Helpdesk\KbCategoryController;
use App\Http\Controllers\Api\Helpdesk\KbSubcategoryController;
use App\Http\Controllers\Api\Helpdesk\KbArticleController;
use Illuminate\Support\Facades\Route;

// ── Public: one-click feedback from the closure email ───────────────────
// No auth — the 'signed' middleware validates the emailed signature instead.
Route::get('/helpdesk/feedback/{ticket}/{rating}', [HelpdeskFeedbackController::class, 'submit'])
    ->middleware('signed')
    ->name('helpdesk.feedback.oneclick')
    ->where(['ticket' => '[0-9]+', 'rating' => '[1-5]']);

// ── Public: one-click reopen from the closure email (signed, no auth) ────
Route::get('/helpdesk/reopen/{ticket}', [HelpdeskFeedbackController::class, 'reopen'])
    ->middleware('signed')
    ->name('helpdesk.reopen.oneclick')
    ->where(['ticket' => '[0-9]+']);

// ── Public: embeddable support widget + public knowledge base (no auth) ──
// Tenant resolved from the widget public key; submission is throttled + honeypot.
Route::post('/helpdesk/public/widget/{key}/tickets', [PublicHelpdeskController::class, 'submitTicket'])
    ->middleware('throttle:15,1');
// Inbound email → ticket reply (provider route/webhook or IMAP poller). Auth is a
// shared secret + the per-ticket token in the threaded recipient, not a session.
Route::post('/helpdesk/public/inbound-email', [PublicHelpdeskController::class, 'inboundEmail'])
    ->middleware('throttle:60,1');
Route::get('/helpdesk/public/widget/{key}/kb', [PublicHelpdeskController::class, 'kbTree']);
Route::get('/helpdesk/public/widget/{key}/kb/search', [PublicHelpdeskController::class, 'kbSearch']);
Route::get('/helpdesk/public/kb/articles/{slug}', [PublicHelpdeskController::class, 'article']);
Route::patch('/helpdesk/public/kb/articles/{slug}/vote', [PublicHelpdeskController::class, 'vote'])
    ->middleware('throttle:30,1');
// Public star rating + optional comment (REQ-11) — same throttle as the vote.
Route::post('/helpdesk/public/kb/articles/{slug}/feedback', [KbArticleController::class, 'publicFeedback'])
    ->middleware('throttle:30,1');

// ── Helpdesk & Support Module (owner: Shivam, Sanctum) ──────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('helpdesk')->group(function () {

    // ── Manager analytics ───────────────────────────────────────
    Route::get('/analytics', [HelpdeskDashboardController::class, 'analytics']);

    // ── Current-user capabilities (drives the frontend nav) ─────
    Route::get('/me', [HelpdeskMeController::class, 'show']);

    // ── My assigned tasks (assignee dashboard) ──────────────────
    Route::get('/my-tasks', [TicketController::class, 'myTasks']);

    // ── Assignable people in this tenant (assignee / task pickers) ──
    Route::get('/agents', [HelpdeskAgentController::class, 'index']);

    // ── Support settings: priorities / statuses / departments (Phase 1) ──
    // GET is readable by any authed user (ticket form needs the lists); writes
    // are admin-only (enforced in the FormRequests). Static segments declared
    // before /{id} so they aren't captured as ids.
    Route::get('/settings',                     [HelpdeskSettingsController::class, 'index']);
    Route::put('/settings/general',             [HelpdeskSettingsController::class, 'updateSettings']);
    Route::post('/settings/{type}',             [HelpdeskSettingsController::class, 'storeItem'])->where('type', 'priorities|statuses|departments');
    Route::patch('/settings/{type}/reorder',    [HelpdeskSettingsController::class, 'reorder'])->where('type', 'priorities|statuses|departments');
    Route::put('/settings/{type}/{id}',         [HelpdeskSettingsController::class, 'updateItem'])->where(['type' => 'priorities|statuses|departments', 'id' => '[0-9]+']);
    Route::delete('/settings/{type}/{id}',      [HelpdeskSettingsController::class, 'destroyItem'])->where(['type' => 'priorities|statuses|departments', 'id' => '[0-9]+']);

    // ── Embeddable widget settings (admin) ──────────────────────
    Route::get('/widget',         [HelpdeskWidgetController::class, 'show']);
    Route::put('/widget',         [HelpdeskWidgetController::class, 'update']);
    Route::post('/widget/rotate', [HelpdeskWidgetController::class, 'rotate']);

    // ── Tickets ─────────────────────────────────────────────────
    Route::get('/tickets',                    [TicketController::class, 'index']);
    // Static segment declared BEFORE /tickets/{ticket} so "unseen-count" is never
    // captured as a ticket id (belt-and-braces: {ticket} is also numeric-only).
    Route::get('/tickets/unseen-count',       [TicketController::class, 'unseenCount']);
    Route::get('/tickets/status-counts',      [TicketController::class, 'statusCounts']);
    // Bulk status/assign/delete for the grid's multi-select — one request, not N.
    // Static segment, so declared before the numeric /tickets/{ticket} routes.
    Route::post('/tickets/bulk',              [TicketController::class, 'bulk']);
    Route::post('/tickets',                   [TicketController::class, 'store']);
    Route::get('/tickets/{ticket}',           [TicketController::class, 'show'])->whereNumber('ticket');
    Route::put('/tickets/{ticket}',           [TicketController::class, 'update']);
    Route::delete('/tickets/{ticket}',        [TicketController::class, 'destroy']);
    Route::patch('/tickets/{ticket}/status',  [TicketController::class, 'updateStatus']);
    Route::patch('/tickets/{ticket}/seen',    [TicketController::class, 'seen']); // REQ-05: mark seen
    Route::post('/tickets/{ticket}/summarize', [TicketController::class, 'summarize']); // AI summary (Phase 6)
    Route::patch('/tickets/{ticket}/assign',  [TicketController::class, 'assign']);
    Route::post('/tickets/{ticket}/feedback', [TicketController::class, 'feedback']);

    // Integration with Projects/Tasks (owner: Shivam — both are his modules)
    Route::patch('/tickets/{ticket}/link-project', [TicketController::class, 'linkProject']);
    Route::post('/tickets/{ticket}/create-task',   [TicketController::class, 'createTask']);
    Route::post('/tickets/{ticket}/create-tasks',  [TicketController::class, 'createTasks']); // plural (Phase 4)

    // ── Collaboration: private notes, reminders, related tickets (Phase 2) ──
    Route::get('/tickets/{ticket}/notes',      [TicketCollaborationController::class, 'notes']);
    Route::post('/tickets/{ticket}/notes',     [TicketCollaborationController::class, 'storeNote']);
    Route::get('/tickets/{ticket}/reminders',  [TicketCollaborationController::class, 'reminders']);
    Route::post('/tickets/{ticket}/reminders', [TicketCollaborationController::class, 'storeReminder']);
    Route::patch('/reminders/{reminder}/done', [TicketCollaborationController::class, 'reminderDone']);
    Route::get('/tickets/{ticket}/related',    [TicketCollaborationController::class, 'related']);
    Route::post('/tickets/{ticket}/related',   [TicketCollaborationController::class, 'storeRelated']);
    Route::delete('/tickets/{ticket}/related/{relatedId}', [TicketCollaborationController::class, 'destroyRelated']);

    // ── Merge + Tags (Phase 3) ──────────────────────────────────
    Route::post('/tickets/{ticket}/merge',     [TicketController::class, 'merge']);

    Route::get('/tags',                        [TicketTagController::class, 'index']);
    Route::post('/tags',                       [TicketTagController::class, 'store']);
    Route::get('/tickets/{ticket}/tags',       [TicketTagController::class, 'ticketTags']);
    Route::post('/tickets/{ticket}/tags',      [TicketTagController::class, 'attach']);
    Route::delete('/tickets/{ticket}/tags/{tagId}', [TicketTagController::class, 'detach']);

    // ── Ticket Replies (conversation thread) ────────────────────
    Route::get('/tickets/{ticket}/replies',   [TicketReplyController::class, 'index']);
    Route::post('/tickets/{ticket}/replies',  [TicketReplyController::class, 'store']);

    // Secure attachment download (auth + tenant scoped)
    Route::get('/tickets/{ticket}/attachments/{attachment}/download', [TicketReplyController::class, 'download']);

    // ── Canned Responses (saved replies) ────────────────────────
    Route::get('/canned-responses',            [CannedResponseController::class, 'index']);
    Route::post('/canned-responses',           [CannedResponseController::class, 'store']);
    Route::put('/canned-responses/{id}',       [CannedResponseController::class, 'update']);
    Route::delete('/canned-responses/{id}',    [CannedResponseController::class, 'destroy']);
    Route::post('/canned-responses/{id}/used', [CannedResponseController::class, 'used']);

    // ── Knowledge Base — Categories ─────────────────────────────
    Route::get('/kb/categories',              [KbCategoryController::class, 'index']);
    Route::post('/kb/categories',             [KbCategoryController::class, 'store']);
    Route::put('/kb/categories/{category}',   [KbCategoryController::class, 'update']);
    Route::delete('/kb/categories/{category}', [KbCategoryController::class, 'destroy']);

    // ── Knowledge Base — Sub-categories ─────────────────────────
    Route::get('/kb/subcategories',                 [KbSubcategoryController::class, 'index']);
    Route::post('/kb/subcategories',                [KbSubcategoryController::class, 'store']);
    Route::put('/kb/subcategories/{subcategory}',   [KbSubcategoryController::class, 'update']);
    Route::delete('/kb/subcategories/{subcategory}', [KbSubcategoryController::class, 'destroy']);

    // ── Knowledge Base — Articles ───────────────────────────────
    Route::get('/kb/articles',                [KbArticleController::class, 'index']);
    Route::post('/kb/articles',               [KbArticleController::class, 'store']);
    Route::get('/kb/articles/{article}',      [KbArticleController::class, 'show']);
    Route::put('/kb/articles/{article}',      [KbArticleController::class, 'update']);
    Route::delete('/kb/articles/{article}',   [KbArticleController::class, 'destroy']);
    Route::patch('/kb/articles/{article}/vote',      [KbArticleController::class, 'vote']);
    Route::get('/kb/articles/{article}/feedback',    [KbArticleController::class, 'feedback']); // REQ-11: star-rating list
    Route::patch('/kb/articles/{article}/publish',   [KbArticleController::class, 'publish']);
    Route::patch('/kb/articles/{article}/unpublish', [KbArticleController::class, 'unpublish']);
});
