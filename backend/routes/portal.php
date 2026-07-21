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
Route::middleware(['auth:sanctum', 'vendor.portal'])->prefix('portal')->group(function () {
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
});
