<?php

use App\Http\Controllers\Api\Portal\PurchasePortalCommerceController;
use App\Http\Controllers\Api\Portal\PurchasePortalContactController;
use App\Http\Controllers\Api\Portal\PurchasePortalController;
use App\Http\Controllers\Api\Portal\PurchasePortalWorkforceController;
use App\Http\Controllers\Api\Portal\VendorPortalController;
use App\Http\Controllers\Api\Portal\VendorWorkController;
use App\Http\Controllers\Api\Purchase\PurchaseVendorAuthController;
use Illuminate\Support\Facades\Route;

// ── Vendor "My Work" ────────────────────────────────────────────────────
// The projects/tasks/tickets a vendor or TPV has been assigned. Gated on ROLE
// only (not vendor.portal, which needs a vendor-master profile) because the
// subject is the logged-in User — everything is filtered to $request->user().
Route::middleware(['auth:sanctum', 'role:vendor,third_party_vendor'])->prefix('portal/my-work')->group(function () {
    Route::get('/summary',  [VendorWorkController::class, 'summary']);
    Route::get('/projects', [VendorWorkController::class, 'projects']);
    Route::get('/tasks',    [VendorWorkController::class, 'tasks']);
    Route::get('/tickets',  [VendorWorkController::class, 'tickets']);
    // Knowledge Base (enhancement #6) — published, tenant-scoped help content the
    // dashboard shows next to Projects/Tasks/Tickets. Role-only gate, same as above.
    Route::get('/kb',            [VendorPortalController::class, 'kbArticles']);
    Route::get('/kb/{slug}',     [VendorPortalController::class, 'kbArticle']);
});

// ── Vendor Self-Service Portal ──────────────────────────────────────────
// A vendor/third_party_vendor login sees ONLY its own records. The subject is
// resolved from the token by the vendor.portal middleware — there is no vendor
// id in any of these URLs to forge. Sub-resource routes bind a specific
// order/invoice, and the controller's assertOwned() 404s anything not the
// caller's (existence-hiding), while the middleware 403s wrong-role access.
//
// Read-only for v1: the brief scopes this slice to onboarding, orders and
// invoices visibility. Write actions (uploading onboarding docs, etc.) land in a
// later slice behind the same ownership layer.
//
// NOTE: both middleware in ONE ->middleware([...]) call — chaining a second
// ->middleware() replaces the first and silently drops auth:sanctum.
Route::middleware(['auth:sanctum', 'vendor.portal', 'temp.access'])->prefix('portal')->group(function () {
    Route::get('/me',                    [VendorPortalController::class, 'me']);
    // One-time post-activation welcome banner (dismissal persisted server-side).
    Route::post('/welcome/dismiss',      [VendorPortalController::class, 'dismissWelcomeBanner']);
    Route::get('/onboarding',            [VendorPortalController::class, 'onboarding']);

    // Compliance documents — the portal's write actions. Ownership on resubmit/
    // download is enforced by the controller's assertOwned() (404 if not yours).
    Route::get('/documents',                        [VendorPortalController::class, 'documents']);
    Route::post('/documents',                       [VendorPortalController::class, 'uploadDocument']);
    Route::post('/documents/{document}/resubmit',   [VendorPortalController::class, 'resubmitDocument']);
    Route::get('/documents/{document}/download',    [VendorPortalController::class, 'downloadDocument']);

    Route::get('/orders',                [VendorPortalController::class, 'orders']);
    Route::get('/orders/{purchaseOrder}', [VendorPortalController::class, 'order']);

    Route::get('/invoices',              [VendorPortalController::class, 'invoices']);
    Route::get('/invoices/{purchaseInvoice}', [VendorPortalController::class, 'invoice']);

    // ── TPV Self-Service (third_party_vendor only via vendor.portal) ─────────
    // Vendor is always resolved from the authenticated token — never a URL param.
    // Admin-only actions (approve, activate, badge, suspend, terminate) are absent
    // by design; the TPV portal has no authority to perform them.

    // Onboarding — wizard read + write (vendor edits their own record)
    Route::get('/onboarding/{onboarding}',                [VendorPortalController::class, 'onboardingShow']);
    Route::get('/onboarding/{onboarding}/progress',       [VendorPortalController::class, 'onboardingProgress']);
    Route::get('/onboarding/{onboarding}/kickoff',        [VendorPortalController::class, 'kickoffPdf']);
    Route::get('/onboarding/{onboarding}/work-start-letter', [VendorPortalController::class, 'workStartLetter']);
    Route::post('/onboarding/{onboarding}/kickoff/accept',[VendorPortalController::class, 'acceptKickoff']);
    Route::post('/onboarding/{onboarding}/kickoff/log',   [VendorPortalController::class, 'logKickoffEvent']);
    Route::post('/onboarding/{onboarding}/profile',       [VendorPortalController::class, 'saveProfile']);
    Route::patch('/onboarding/{onboarding}/step',         [VendorPortalController::class, 'setStep']);
    Route::post('/onboarding/{onboarding}/submit',        [VendorPortalController::class, 'submitOnboarding']);

    // Contacts — own vendor only (no vendor_id in URL; controller resolves from portalVendor)
    Route::get('/contacts',                               [VendorPortalController::class, 'contacts']);
    Route::post('/contacts',                              [VendorPortalController::class, 'storeContact']);
    Route::put('/contacts/{contact}',                     [VendorPortalController::class, 'updateContact']);
    Route::patch('/contacts/{contact}/status',            [VendorPortalController::class, 'setContactStatus']);

    // Workers — own vendor only (vendor_id injected server-side, never from URL)
    // PPE catalogue + issue/return for the vendor's own workers. Stock is
    // Inventory's; this only reads it and posts movements through the service.
    // The shared catalogue and item photos are tenant-wide by design — there is
    // ONE central store and a vendor has to see what is on the shelf. They expose
    // no other vendor's holdings, so they stay on the Inventory-backed controller.
    Route::get('/ppe',                                    [\App\Http\Controllers\Api\Tpv\PpeController::class, 'catalogue']);
    Route::get('/ppe/item/{product}/image',               [\App\Http\Controllers\Api\Tpv\PpeController::class, 'image']);

    // Everything worker-specific goes through VendorPortalController, which
    // resolves the vendor from the token and calls assertWorkerOwned(). These were
    // the ADMIN PpeController/PpeRequirementController, which guard on tenant
    // ALONE — any vendor could read, issue against and write off another vendor's
    // workers, moving shared Inventory stock. The summary is scoped the same way.
    Route::get('/ppe/summary',                            [VendorPortalController::class, 'ppeSummary']);
    Route::get('/ppe/compliance/workers/{worker}',        [VendorPortalController::class, 'workerPpeCompliance']);
    Route::get('/ppe/workers/{worker}',                   [VendorPortalController::class, 'workerPpe']);
    Route::post('/ppe/workers/{worker}/issue',            [VendorPortalController::class, 'issueWorkerPpe']);
    Route::post('/ppe/issues/{issue}/return',             [VendorPortalController::class, 'returnWorkerPpe']);
    Route::get('/workers/stats',                          [VendorPortalController::class, 'workerStats']);
    Route::get('/workers',                                [VendorPortalController::class, 'workers']);
    Route::post('/workers',                               [VendorPortalController::class, 'storeWorker']);
    Route::get('/workers/{worker}',                       [VendorPortalController::class, 'showWorker']);
    Route::get('/workers/{worker}/progress',              [VendorPortalController::class, 'workerProgress']);
    Route::put('/workers/{worker}',                       [VendorPortalController::class, 'updateWorker']);
    Route::post('/workers/{worker}/medical',              [VendorPortalController::class, 'saveMedical']);
    Route::post('/workers/{worker}/induction',            [VendorPortalController::class, 'saveInduction']);
    // Punch + entry card. These were the portal's last two calls into the ADMIN
    // /tpv/* group, which is why third_party_vendor had been added to that
    // group's role gate — exposing every vendor's onboarding along with it.
    // Same TpvWorkerService, ownership-checked instead of role-gated.
    Route::post('/workers/{worker}/mark-punch',           [VendorPortalController::class, 'markWorkerPunch']);
    Route::post('/workers/{worker}/mark-card-status',     [VendorPortalController::class, 'markWorkerCardStatus']);
    Route::get('/workers/{worker}/attendance',            [VendorPortalController::class, 'workerAttendance']);
    Route::get('/workers/{worker}/strikes',               [VendorPortalController::class, 'workerStrikes']);

    // Gate / Attendance / Strikes — read-only for TPV
    Route::get('/gate/stats',                             [VendorPortalController::class, 'gateStats']);
    Route::get('/gate-log',                               [VendorPortalController::class, 'gateLog']);
    Route::get('/attendance',                             [VendorPortalController::class, 'attendance']);
    Route::get('/strikes',                                [VendorPortalController::class, 'strikes']);
});

// ── Purchase Vendor Portal — auth (public) ──────────────────────────────
// Completely independent of the shared vendor auth: registers/logs in a
// PurchaseVendor identity only (Sanctum token, tokenable = purchase_vendors).
Route::prefix('purchase-vendor')->group(function () {
    Route::post('/register',        [PurchaseVendorAuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/verify-email',    [PurchaseVendorAuthController::class, 'verifyEmail']);
    Route::post('/login',           [PurchaseVendorAuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/forgot-password', [PurchaseVendorAuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
    Route::post('/reset-password',  [PurchaseVendorAuthController::class, 'resetPassword'])->middleware('throttle:10,1');
});

// ── Purchase Vendor Portal — authenticated (Sanctum + PurchaseVendor only) ─
// The purchase.vendor.portal middleware requires the token subject to BE a
// PurchaseVendor, isolating this portal from the shared vendor / TPV portal.
// URLs are unchanged (/api/portal/purchase/*).
Route::middleware(['auth:sanctum', 'purchase.vendor.portal'])->prefix('portal/purchase')->group(function () {
    Route::post('/logout',                            [PurchaseVendorAuthController::class, 'logout']);
    Route::get('/dashboard',                          [PurchasePortalController::class, 'dashboard']);
    Route::get('/ppe',                                [\App\Http\Controllers\Api\Tpv\PpeController::class, 'catalogue']);
    // NOT PpeController::summary — that one totals every issue in the tenant, so
    // it showed a Purchase vendor how much PPE the TPV vendors were holding. The
    // shelf is shared and stays tenant-wide; the issued figures are the vendor's own.
    Route::get('/ppe/summary',                        [PurchasePortalController::class, 'ppeSummary']);
    Route::get('/ppe/item/{product}/image',           [\App\Http\Controllers\Api\Tpv\PpeController::class, 'image']);
    Route::get('/tasks',                              [PurchasePortalController::class, 'tasks']);
    Route::get('/me',                                 [PurchasePortalController::class, 'me']);
    // One-time post-activation welcome banner (dismissal persisted server-side).
    Route::post('/welcome/dismiss',                   [PurchasePortalController::class, 'dismissWelcomeBanner']);
    Route::put('/profile',                            [PurchasePortalController::class, 'updateProfile']);

    Route::get('/onboarding',                         [PurchasePortalController::class, 'onboarding']);
    Route::get('/onboarding/{onboarding}',            [PurchasePortalController::class, 'onboardingShow']);
    Route::get('/onboarding/{onboarding}/progress',   [PurchasePortalController::class, 'onboardingProgress']);
    Route::get('/onboarding/{onboarding}/kickoff',        [PurchasePortalController::class, 'onboardingKickoffPdf']);
    Route::post('/onboarding/{onboarding}/kickoff/accept',[PurchasePortalController::class, 'onboardingAcceptKickoff']);
    Route::post('/onboarding/{onboarding}/kickoff/log',   [PurchasePortalController::class, 'onboardingLogKickoffEvent']);
    Route::post('/onboarding/{onboarding}/profile',   [PurchasePortalController::class, 'saveProfile']);
    Route::patch('/onboarding/{onboarding}/step',     [PurchasePortalController::class, 'setStep']);
    Route::post('/onboarding/{onboarding}/submit',    [PurchasePortalController::class, 'submitOnboarding']);

    Route::get('/documents',                          [PurchasePortalController::class, 'documents']);
    Route::post('/documents',                         [PurchasePortalController::class, 'uploadDocument']);
    Route::post('/documents/{document}/resubmit',     [PurchasePortalController::class, 'resubmitDocument']);
    Route::get('/documents/{document}/download',      [PurchasePortalController::class, 'downloadDocument']);

    Route::get('/kickoff',                            [PurchasePortalController::class, 'kickoff']);
    Route::post('/kickoff/accept',                    [PurchasePortalController::class, 'acceptKickoff']);

    // ── Contacts (own vendor only) ──────────────────────────────────────
    Route::get('/contacts',                           [PurchasePortalContactController::class, 'index']);
    Route::post('/contacts',                          [PurchasePortalContactController::class, 'store']);
    Route::get('/contacts/{contact}',                 [PurchasePortalContactController::class, 'show']);
    Route::put('/contacts/{contact}',                 [PurchasePortalContactController::class, 'update']);
    Route::patch('/contacts/{contact}/status',        [PurchasePortalContactController::class, 'setStatus']);
    Route::delete('/contacts/{contact}',              [PurchasePortalContactController::class, 'destroy']);

    // ── Workforce (own vendor only; workers + medical/training/induction) ─
    Route::get('/workers',                            [PurchasePortalWorkforceController::class, 'index']);
    Route::get('/workers/summary',                    [PurchasePortalWorkforceController::class, 'summary']);
    Route::post('/workers',                           [PurchasePortalWorkforceController::class, 'store']);
    Route::get('/workers/{worker}',                   [PurchasePortalWorkforceController::class, 'show']);
    Route::put('/workers/{worker}',                   [PurchasePortalWorkforceController::class, 'update']);
    Route::delete('/workers/{worker}',                [PurchasePortalWorkforceController::class, 'destroy']);
    Route::get('/workers/{worker}/readiness',         [PurchasePortalWorkforceController::class, 'readiness']);
    Route::post('/workers/{worker}/documents',        [PurchasePortalWorkforceController::class, 'uploadDocument']);
    Route::post('/workers/{worker}/medical',          [PurchasePortalWorkforceController::class, 'saveMedical']);
    Route::post('/workers/{worker}/training',         [PurchasePortalWorkforceController::class, 'saveTraining']);
    Route::post('/workers/{worker}/induction',        [PurchasePortalWorkforceController::class, 'saveInduction']);

    // ── Workforce step 4 (PPE) and step 5 (badge, read-only) ──────────────
    // Vendor-owned: every worker/issue id is resolved through the caller's own
    // vendor, so another vendor's row reads as absent. No warehouse_id is
    // accepted — the server resolves the site, which is what stops a vendor
    // moving stock between warehouses. Activation is NOT here: it is admin-only.
    Route::get('/workers/{worker}/ppe',               [PurchasePortalWorkforceController::class, 'workerPpe']);
    Route::get('/workers/{worker}/ppe/compliance',    [PurchasePortalWorkforceController::class, 'workerPpeCompliance']);
    Route::post('/workers/{worker}/ppe/issue',        [PurchasePortalWorkforceController::class, 'issueWorkerPpe']);
    Route::post('/ppe/issues/{issue}/return',         [PurchasePortalWorkforceController::class, 'returnWorkerPpe']);
    Route::get('/workers/{worker}/badge',             [PurchasePortalWorkforceController::class, 'workerBadge']);

    // ── Commercial (own vendor only; read-only) ─────────────────────────
    Route::get('/orders',                             [PurchasePortalCommerceController::class, 'orders']);
    Route::get('/orders/{id}',                        [PurchasePortalCommerceController::class, 'order']);
    Route::get('/quotations',                         [PurchasePortalCommerceController::class, 'quotations']);
    Route::get('/quotations/{id}',                    [PurchasePortalCommerceController::class, 'quotation']);
    Route::get('/contracts',                          [PurchasePortalCommerceController::class, 'contracts']);
    Route::get('/contracts/{id}',                     [PurchasePortalCommerceController::class, 'contract']);
    Route::get('/invoices',                           [PurchasePortalCommerceController::class, 'invoices']);
    Route::get('/invoices/{id}',                      [PurchasePortalCommerceController::class, 'invoice']);
    Route::get('/debit-notes',                        [PurchasePortalCommerceController::class, 'debitNotes']);
    Route::get('/debit-notes/{id}',                   [PurchasePortalCommerceController::class, 'debitNote']);
    Route::get('/payments',                           [PurchasePortalCommerceController::class, 'payments']);
});

// ── Customer Portal — public (no auth) ───────────────────────────────────
// The old CRM's model, restored: the customer company never signs in, its
// CONTACTS do. There is deliberately no /register — access always begins with
// a staff member inviting a real contact of a real customer.
Route::prefix('client-portal')->group(function () {
    Route::post('/login',           [\App\Http\Controllers\Api\Customer\ClientPortalAuthController::class, 'login'])->middleware('throttle:20,1');
    Route::post('/forgot-password', [\App\Http\Controllers\Api\Customer\ClientPortalAuthController::class, 'forgotPassword'])->middleware('throttle:10,1');
    Route::post('/set-password',    [\App\Http\Controllers\Api\Customer\ClientPortalAuthController::class, 'setPassword'])->middleware('throttle:10,1');
});

// ── Customer Portal — authenticated (Sanctum + ClientContact only) ───────
// No route here takes a client id: the contact's own client comes off the
// token, so "show me another customer" is not expressible.
Route::middleware(['auth:sanctum', 'client.portal'])->prefix('portal/client')->group(function () {
    Route::post('/logout',          [\App\Http\Controllers\Api\Customer\ClientPortalAuthController::class, 'logout']);
    Route::get('/me',               [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'me']);
    Route::get('/dashboard',        [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'dashboard']);
    Route::put('/profile',          [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'updateProfile']);
    Route::post('/change-password', [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'changePassword']);

    Route::get('/invoices',      [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'invoices']);
    Route::get('/payments',      [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'payments']);
    Route::get('/credit-notes',  [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'creditNotes']);
    Route::get('/statement',     [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'statement']);
    Route::get('/estimates',     [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'estimates']);
    Route::get('/proposals',     [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'proposals']);
    Route::get('/contracts',     [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'contracts']);
    Route::get('/projects',      [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'projects']);
    Route::get('/tickets',       [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'tickets']);
    // Raising goes through Helpdesk's TicketIntakeContract, never a direct insert.
    Route::post('/tickets',      [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'raiseTicket']);
    // §10 — the customer answers the survey, rather than staff typing in
    // a score they were told on the phone.
    Route::get('/feedback',      [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'myFeedback']);
    Route::post('/feedback',     [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'submitFeedback']);
    Route::get('/notes',         [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'notes']);
    Route::get('/files',         [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'files']);
    Route::get('/contacts',      [\App\Http\Controllers\Api\Customer\ClientPortalController::class, 'contacts']);
});

