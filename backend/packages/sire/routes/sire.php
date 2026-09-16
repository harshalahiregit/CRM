<?php

/**
 * SIRE — the complete route file.
 *
 * Loaded by SireServiceProvider::boot(). Register the provider and every SIRE
 * endpoint exists; there is nothing to paste anywhere.
 *
 * NOTHING HERE IS HARDCODED
 *
 * The prefix, the middleware group and the authentication and authorization
 * middleware all come from config('sire.host.*). SIRE does not know whether
 * your application authenticates with Sanctum, a session, Passport, a JWT guard
 * or something you wrote — and a route file that assumed one of them would be
 * wrong in most installations.
 *
 *     'route_prefix'     => 'api/sire'      // or 'engineering/issues', anything
 *     'middleware_group' => 'api'           // or 'web'
 *     'auth_middleware'  => ['auth:sanctum']
 *     'role_middleware'  => []              // optional extra gate
 *
 * ONE MIDDLEWARE ARRAY, ONE GROUP
 *
 * Everything is composed into a SINGLE ->middleware([...]) array below. A second
 * chained ->middleware() call REPLACES the first rather than adding to it, and
 * the mistake silently drops authentication: the discovery report for the first
 * host attributes a live cross-vendor leak to exactly that. If you add routes,
 * add them INSIDE this group.
 *
 * FAILS CLOSED
 *
 * If auth_middleware resolves to nothing, SireRouteMiddleware substitutes a
 * guard that denies everything. An engineering issue tracker published to
 * anonymous traffic is not a state anyone should reach by leaving a config key
 * blank.
 */

use Illuminate\Support\Facades\Route;
use Sire\Http\Controllers\ReportController;
use Sire\Http\Controllers\ReportWorkflowController;
use Sire\Http\Controllers\SireAiController;
use Sire\Http\Controllers\SireCapaController;
use Sire\Http\Controllers\SireCommentController;
use Sire\Http\Controllers\SireDashboardController;
use Sire\Http\Controllers\SireKbLinkController;
use Sire\Http\Controllers\SireQualityController;
use Sire\Http\Controllers\SireQueueController;
use Sire\Http\Controllers\SireRecurrenceController;
use Sire\Http\Controllers\SireRelationController;
use Sire\Http\Controllers\SireReleaseController;
use Sire\Http\Controllers\SireReleaseGovernanceController;
use Sire\Http\Controllers\SireReleaseNotesController;
use Sire\Http\Controllers\SireRootCauseController;
use Sire\Http\Controllers\SireTestCaseController;
use Sire\Http\Controllers\SireCustomerController;
use Sire\Http\Controllers\SireTimelineController;
use Sire\Http\Controllers\SireWatcherController;

Route::middleware(\Sire\Http\SireRouteMiddleware::stack())
    ->prefix((string) config('sire.host.route_prefix', 'api/sire'))
    ->name((string) config('sire.host.route_name', 'sire.'))
    ->group(function () {
    // ---- Report Issue (Phase 0) ------------------------------------------
    // Filing an issue and opening one. Both were missing: ReportController's
    // MERGE NOTE says to take only store() and storeAttachment() from its slice,
    // and the slice that was meant to carry these routes never landed -- so the
    // Report Issue button posted to a 404 and the detail screen could not load.
    // The three lists the global Report Issue form needs, and nothing else.
    // Kept off the dashboard options endpoint: the button is on every screen
    // for every staff member, and that payload carries filter-bar rosters.
    Route::get('report-options', [ReportController::class, 'options']);
    Route::post('reports', [ReportController::class, 'store']);
    Route::get('reports/{report}', [ReportController::class, 'show']);

    Route::get('reports/{report}/attachments',  [ReportController::class, 'indexAttachments']);
    Route::post('reports/{report}/attachments', [ReportController::class, 'storeAttachment']);
    // The disk is private, so evidence is streamed through here rather than
    // linked. {attachment} is the stored FILENAME -- matched against the
    // report's own listing rather than read from the disk directly. A full
    // stored path still resolves, which is why the pattern still allows
    // slashes, but nothing builds one: a path in a URL segment encodes them as
    // %2F, and the production web server 404s that before Laravel sees it.
    Route::get('reports/{report}/attachments/{attachment}', [ReportController::class, 'downloadAttachment'])
        ->where('attachment', '.*');

    // ---- engineering workflow (Phase 1) -----------------------------------
    // ONE transition endpoint, not one per state change. The state machine in
    // SireWorkflowService already knows what is legal from where; twenty routes
    // would be twenty places for those rules to drift.
    Route::post('reports/{report}/transitions', [ReportWorkflowController::class, 'transition']);
    Route::post('reports/{report}/actions',     [ReportWorkflowController::class, 'action']);

    // ---- activity ----------------------------------------------------------
    Route::get('reports/{report}/timeline', SireTimelineController::class);

    // ---- watchers ---------------------------------------------------------
    // Who else hears about this issue. Subscription only -- these never widen
    // what anybody may READ, which is why they sit behind the same ownership
    // check as every other route-bound endpoint.
    // ---- affected customer -------------------------------------------------
    // Read-only against the host's own directory, through SireCustomerProvider.
    // SIRE names a customer on a defect so the register can answer "which
    // customers are hitting this"; it never writes to a customer record.
    Route::get('customers', [SireCustomerController::class, 'index']);
    Route::put('reports/{report}/customer', [SireCustomerController::class, 'update']);

    Route::get('reports/{report}/watchers', [SireWatcherController::class, 'index']);
    Route::post('reports/{report}/watchers', [SireWatcherController::class, 'store']);
    Route::delete('reports/{report}/watchers/{user}', [SireWatcherController::class, 'destroy']);

    Route::get('reports/{report}/comments',             [SireCommentController::class, 'index']);
    Route::post('reports/{report}/comments',            [SireCommentController::class, 'store']);
    Route::patch('reports/{report}/comments/{note}',    [SireCommentController::class, 'update']);
    Route::delete('reports/{report}/comments/{note}',   [SireCommentController::class, 'destroy']);
    //
    // There is NO route to edit or delete a system event. audit_logs rows are
    // written by the workflow and are not reachable by any write endpoint in
    // SIRE. This absence is the feature — do not add one.

    // ---- dashboard ---------------------------------------------------------
    Route::get('dashboard',          [SireDashboardController::class, 'tiles']);
    Route::get('dashboard/register', [SireDashboardController::class, 'register']);
    Route::get('dashboard/options',  [SireDashboardController::class, 'options']);

    // ---- work queues -------------------------------------------------------
    Route::get('queues/development', [SireQueueController::class, 'development']);
    Route::get('queues/qa',          [SireQueueController::class, 'qa']);

    // ======================= Phase 2 — quality and governance =======================

    // ---- root cause ---------------------------------------------------------
    Route::get('reports/{report}/root-cause',         [SireRootCauseController::class, 'show']);
    Route::post('reports/{report}/root-cause',        [SireRootCauseController::class, 'store']);
    Route::post('reports/{report}/root-cause/confirm',[SireRootCauseController::class, 'confirm']);

    // ---- relationships: duplicates, links, regressions -----------------------
    Route::get('reports/{report}/relations',             [SireRelationController::class, 'index']);
    Route::post('reports/{report}/links',                [SireRelationController::class, 'storeLink']);
    Route::delete('links/{link}',                        [SireRelationController::class, 'destroyLink']);
    Route::post('reports/{report}/regression',           [SireRelationController::class, 'markRegression']);
    Route::delete('reports/{report}/regression',         [SireRelationController::class, 'clearRegression']);

    // ---- recurrence ---------------------------------------------------------
    Route::get('recurrence-groups',                                    [SireRecurrenceController::class, 'index']);
    Route::post('recurrence-groups',                                   [SireRecurrenceController::class, 'store']);
    Route::get('recurrence-groups/{group}',                            [SireRecurrenceController::class, 'show']);
    Route::post('recurrence-groups/{group}/occurrences',               [SireRecurrenceController::class, 'addOccurrence']);
    Route::delete('recurrence-groups/{group}/occurrences/{report}',    [SireRecurrenceController::class, 'removeOccurrence']);
    Route::post('recurrence-groups/{group}/recompute',                 [SireRecurrenceController::class, 'recompute']);

    // ---- releases -----------------------------------------------------------
    Route::get('releases',                       [SireReleaseController::class, 'index']);
    Route::post('releases',                      [SireReleaseController::class, 'store']);
    Route::get('releases/{release}',             [SireReleaseController::class, 'show']);
    Route::patch('releases/{release}',           [SireReleaseController::class, 'update']);
    // Shipping and rollback are GOVERNED transitions — see the governance block.

    // ---- release notes ------------------------------------------------------
    Route::post('releases/{release}/notes',         [SireReleaseNotesController::class, 'generate']);
    Route::get('release-notes/{note}',              [SireReleaseNotesController::class, 'show']);
    Route::patch('release-notes/{note}',            [SireReleaseNotesController::class, 'update']);
    Route::post('release-notes/{note}/regenerate',  [SireReleaseNotesController::class, 'regenerate']);
    Route::post('release-notes/{note}/submit',      [SireReleaseNotesController::class, 'requestApproval']);
    Route::post('release-notes/{note}/approve',     [SireReleaseNotesController::class, 'approve']);
    Route::post('release-notes/{note}/publish',     [SireReleaseNotesController::class, 'publish']);

    // ---- knowledge base linkage --------------------------------------------
    Route::get('reports/{report}/kb-links',        [SireKbLinkController::class, 'index']);
    Route::post('reports/{report}/kb-links',       [SireKbLinkController::class, 'store']);
    Route::post('reports/{report}/kb-article',     [SireKbLinkController::class, 'createArticle']);
    Route::delete('kb-links/{link}',               [SireKbLinkController::class, 'destroy']);

    // ---- CAPA ---------------------------------------------------------------
    Route::get('capa',                                        [SireCapaController::class, 'index']);
    Route::post('reports/{report}/capa',                      [SireCapaController::class, 'storeForReport']);
    Route::post('recurrence-groups/{group}/capa',             [SireCapaController::class, 'storeForGroup']);
    Route::post('capa/{action}/start',                        [SireCapaController::class, 'start']);
    Route::post('capa/{action}/complete',                     [SireCapaController::class, 'complete']);
    Route::post('capa/{action}/verify',                       [SireCapaController::class, 'verify']);
    Route::post('capa/{action}/cancel',                       [SireCapaController::class, 'cancel']);

    // ---- quality dashboard --------------------------------------------------
    Route::get('quality', SireQualityController::class);

    // ======================= Phase 3 — release governance =======================

    // ---- the release board --------------------------------------------------
    Route::get('release-board',                       [SireReleaseGovernanceController::class, 'board']);
    Route::get('release-gates',                       [SireReleaseGovernanceController::class, 'gateConfig']);
    Route::get('releases/{release}/governance',       [SireReleaseGovernanceController::class, 'show']);
    Route::post('releases/{release}/gates/evaluate',  [SireReleaseGovernanceController::class, 'evaluate']);

    // ---- governed lifecycle: approve / release / cancel / roll back ----------
    Route::post('releases/{release}/transitions',     [SireReleaseGovernanceController::class, 'transition']);

    // ---- emergency override, and the register of them ------------------------
    Route::post('releases/{release}/override',        [SireReleaseGovernanceController::class, 'override']);
    Route::post('release-overrides/{override}/revoke',[SireReleaseGovernanceController::class, 'revokeOverride']);
    Route::get('release-overrides',                   [SireReleaseGovernanceController::class, 'overrides']);

    // ======================= Phase 3 — AI foundation =======================
    // ======================= Contracts, boundary and persistence only. No capability performs analysis; =======================
    // ======================= suggest returns `unavailable` because the only provider declines everything. =======================

    Route::get('ai/status',        [SireAiController::class, 'status']);
    Route::get('ai/capabilities',  [SireAiController::class, 'capabilities']);

    Route::post('ai/reports/{report}/suggest',     [SireAiController::class, 'suggestForReport']);
    Route::get('ai/reports/{report}/suggestions',  [SireAiController::class, 'forReport']);
    Route::post('ai/suggestions/{suggestion}/decide', [SireAiController::class, 'decide']);

    // ---- AI: classification and duplicate detection (computed locally) -------
    // Not on the issue-creation path. Creating an issue never calls these.
    Route::post('ai/reports/{report}/insights', [SireAiController::class, 'insights']);

    // ---- test cases (CORE — works with or without any AI) --------------------
    Route::get('reports/{report}/test-cases',      [SireTestCaseController::class, 'index']);
    Route::post('reports/{report}/test-cases',     [SireTestCaseController::class, 'store']);
    Route::patch('test-cases/{testCase}',          [SireTestCaseController::class, 'update']);
    Route::delete('test-cases/{testCase}',         [SireTestCaseController::class, 'destroy']);
    // The only path by which a test result is ever written.
    Route::post('test-cases/{testCase}/result',    [SireTestCaseController::class, 'result']);
    Route::delete('test-cases/{testCase}/result',  [SireTestCaseController::class, 'resetResult']);

    // ---- AI: the remaining assistance (all computed locally) ------------------
    // Release risk is ADVISORY. The release gates decide what blocks.
    Route::post('ai/releases/{release}/insights', [SireAiController::class, 'releaseInsights']);
    Route::get('ai/engineering-insights',         [SireAiController::class, 'engineeringInsights']);
});
