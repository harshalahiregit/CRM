<?php

use App\Http\Controllers\Api\Helpdesk\HelpdeskDashboardController;
use App\Http\Controllers\Api\Helpdesk\HelpdeskFeedbackController;
use App\Http\Controllers\Api\Helpdesk\HelpdeskWidgetController;
use App\Http\Controllers\Api\Helpdesk\PublicHelpdeskController;
use App\Http\Controllers\Api\Helpdesk\TicketController;
use App\Http\Controllers\Api\Helpdesk\TicketReplyController;
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

// ── Public: embeddable support widget + public knowledge base (no auth) ──
// Tenant resolved from the widget public key; submission is throttled + honeypot.
Route::post('/helpdesk/public/widget/{key}/tickets', [PublicHelpdeskController::class, 'submitTicket'])
    ->middleware('throttle:15,1');
Route::get('/helpdesk/public/widget/{key}/kb', [PublicHelpdeskController::class, 'kbTree']);
Route::get('/helpdesk/public/widget/{key}/kb/search', [PublicHelpdeskController::class, 'kbSearch']);
Route::get('/helpdesk/public/kb/articles/{slug}', [PublicHelpdeskController::class, 'article']);
Route::patch('/helpdesk/public/kb/articles/{slug}/vote', [PublicHelpdeskController::class, 'vote'])
    ->middleware('throttle:30,1');

// ── Helpdesk & Support Module (owner: Shivam, Sanctum) ──────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('helpdesk')->group(function () {

    // ── Manager analytics ───────────────────────────────────────
    Route::get('/analytics', [HelpdeskDashboardController::class, 'analytics']);

    // ── My assigned tasks (assignee dashboard) ──────────────────
    Route::get('/my-tasks', [TicketController::class, 'myTasks']);

    // ── Embeddable widget settings (admin) ──────────────────────
    Route::get('/widget',         [HelpdeskWidgetController::class, 'show']);
    Route::put('/widget',         [HelpdeskWidgetController::class, 'update']);
    Route::post('/widget/rotate', [HelpdeskWidgetController::class, 'rotate']);

    // ── Tickets ─────────────────────────────────────────────────
    Route::get('/tickets',                    [TicketController::class, 'index']);
    Route::post('/tickets',                   [TicketController::class, 'store']);
    Route::get('/tickets/{ticket}',           [TicketController::class, 'show']);
    Route::put('/tickets/{ticket}',           [TicketController::class, 'update']);
    Route::delete('/tickets/{ticket}',        [TicketController::class, 'destroy']);
    Route::patch('/tickets/{ticket}/status',  [TicketController::class, 'updateStatus']);
    Route::patch('/tickets/{ticket}/assign',  [TicketController::class, 'assign']);
    Route::post('/tickets/{ticket}/feedback', [TicketController::class, 'feedback']);

    // ── Ticket Replies (conversation thread) ────────────────────
    Route::get('/tickets/{ticket}/replies',   [TicketReplyController::class, 'index']);
    Route::post('/tickets/{ticket}/replies',  [TicketReplyController::class, 'store']);

    // Secure attachment download (auth + tenant scoped)
    Route::get('/tickets/{ticket}/attachments/{attachment}/download', [TicketReplyController::class, 'download']);

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
    Route::patch('/kb/articles/{article}/publish',   [KbArticleController::class, 'publish']);
    Route::patch('/kb/articles/{article}/unpublish', [KbArticleController::class, 'unpublish']);
});
