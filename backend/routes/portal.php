<?php

use App\Http\Controllers\Api\Portal\VendorPortalController;
use Illuminate\Support\Facades\Route;

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
    Route::get('/workers/stats',                          [VendorPortalController::class, 'workerStats']);
    Route::get('/workers',                                [VendorPortalController::class, 'workers']);
    Route::post('/workers',                               [VendorPortalController::class, 'storeWorker']);
    Route::get('/workers/{worker}',                       [VendorPortalController::class, 'showWorker']);
    Route::get('/workers/{worker}/progress',              [VendorPortalController::class, 'workerProgress']);
    Route::put('/workers/{worker}',                       [VendorPortalController::class, 'updateWorker']);
    Route::post('/workers/{worker}/medical',              [VendorPortalController::class, 'saveMedical']);
    Route::post('/workers/{worker}/induction',            [VendorPortalController::class, 'saveInduction']);
    Route::post('/workers/{worker}/ppe',                  [VendorPortalController::class, 'issuePpe']);
    Route::delete('/workers/{worker}/ppe/{ppeIssue}',     [VendorPortalController::class, 'removePpe']);
    Route::get('/workers/{worker}/attendance',            [VendorPortalController::class, 'workerAttendance']);
    Route::get('/workers/{worker}/strikes',               [VendorPortalController::class, 'workerStrikes']);

    // Gate / Attendance / Strikes — read-only for TPV
    Route::get('/gate/stats',                             [VendorPortalController::class, 'gateStats']);
    Route::get('/gate-log',                               [VendorPortalController::class, 'gateLog']);
    Route::get('/attendance',                             [VendorPortalController::class, 'attendance']);
    Route::get('/strikes',                                [VendorPortalController::class, 'strikes']);
});
