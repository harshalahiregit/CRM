<?php

use App\Http\Controllers\Api\Purchase\PurchaseRequestController;
use App\Http\Controllers\Api\Purchase\PurchaseOrderController;
use App\Http\Controllers\Api\Purchase\GoodsReceiptController;
use App\Http\Controllers\Api\Purchase\PurchaseInvoiceController;
use App\Http\Controllers\Api\Purchase\PurchaseDashboardController;
use App\Http\Controllers\Api\Purchase\PurchaseDebitNoteController;
use App\Http\Controllers\Api\Purchase\PurchaseCatalogController;
use App\Http\Controllers\Api\Purchase\PurchaseContractController;
use App\Http\Controllers\Api\Purchase\PurchaseQuotationController;
use App\Http\Controllers\Api\Purchase\PurchaseRfqController;
use Illuminate\Support\Facades\Route;

// ── Purchase & Procurement Module (Sanctum + role:admin,staff) ──────────
// Unlike routes/hr.php and routes/sales.php, this group is role-gated: a
// `client` / `vendor` / `third_party_vendor` login has no business reading the
// tenant's procurement pipeline. See ARCHITECTURE-PRIMER §1.
// NOTE: both middleware must go in ONE ->middleware([...]) call — chaining a
// second ->middleware() replaces the first and silently drops auth:sanctum.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('purchase')->group(function () {

    // Unified procure-to-pay dashboard (read-only aggregation)
    Route::get('/dashboard', [PurchaseDashboardController::class, 'index']);

    // Purchase Requests — Draft → Submitted → Approved → Converted to PO
    Route::get('/requests/stats',                    [PurchaseRequestController::class, 'stats']);
    Route::get('/requests',                          [PurchaseRequestController::class, 'index']);
    Route::post('/requests',                         [PurchaseRequestController::class, 'store']);
    Route::get('/requests/{purchaseRequest}',        [PurchaseRequestController::class, 'show']);
    Route::put('/requests/{purchaseRequest}',        [PurchaseRequestController::class, 'update']);
    Route::delete('/requests/{purchaseRequest}',     [PurchaseRequestController::class, 'destroy']);
    // Lifecycle actions
    Route::post('/requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit']);

    // Purchase Orders — Draft → Issued → Partially_Received → Received → Closed
    Route::get('/orders/stats',                            [PurchaseOrderController::class, 'stats']);
    Route::get('/orders',                                  [PurchaseOrderController::class, 'index']);
    Route::post('/orders',                                 [PurchaseOrderController::class, 'store']);
    // Convert an approved PR into a draft PO (creates a draft only — issuing is admin).
    Route::post('/orders/from-request/{purchaseRequest}',  [PurchaseOrderController::class, 'fromRequest']);
    Route::get('/orders/{purchaseOrder}',                  [PurchaseOrderController::class, 'show']);
    Route::put('/orders/{purchaseOrder}',                  [PurchaseOrderController::class, 'update']);
    Route::delete('/orders/{purchaseOrder}',               [PurchaseOrderController::class, 'destroy']); // Draft only (service-enforced)

    // Goods Receipts (GRN) — receiving is operational, so staff may record + confirm.
    Route::get('/orders/{purchaseOrder}/receipts',         [GoodsReceiptController::class, 'index']);
    Route::post('/orders/{purchaseOrder}/receipts',        [GoodsReceiptController::class, 'store']);
    Route::get('/receipts/{goodsReceipt}',                 [GoodsReceiptController::class, 'show']);
    Route::post('/receipts/{goodsReceipt}/confirm',        [GoodsReceiptController::class, 'confirm']);
    Route::post('/receipts/{goodsReceipt}/cancel',         [GoodsReceiptController::class, 'cancel']);
    Route::delete('/receipts/{goodsReceipt}',              [GoodsReceiptController::class, 'destroy']);

    // Purchase Invoices — Draft → Awaiting_Payment → Partially_Paid → Paid
    Route::get('/invoices/stats',                          [PurchaseInvoiceController::class, 'stats']);
    Route::get('/invoices',                                [PurchaseInvoiceController::class, 'index']);
    Route::post('/invoices',                               [PurchaseInvoiceController::class, 'store']);
    // Raise an invoice from an issued/received PO (creates a draft only).
    Route::post('/invoices/from-order/{purchaseOrder}',    [PurchaseInvoiceController::class, 'fromOrder']);
    Route::get('/invoices/{purchaseInvoice}',              [PurchaseInvoiceController::class, 'show']);
    Route::get('/invoices/{purchaseInvoice}/match',        [PurchaseInvoiceController::class, 'match']);
    Route::put('/invoices/{purchaseInvoice}',              [PurchaseInvoiceController::class, 'update']);
    Route::delete('/invoices/{purchaseInvoice}',           [PurchaseInvoiceController::class, 'destroy']); // Draft only (service-enforced)

    // Debit Notes / Order Returns — Draft → Open (inventory adjusted) → Settled
    Route::get('/debit-notes/stats',                       [PurchaseDebitNoteController::class, 'stats']);
    Route::get('/debit-notes',                             [PurchaseDebitNoteController::class, 'index']);
    Route::post('/debit-notes',                            [PurchaseDebitNoteController::class, 'store']);
    Route::get('/debit-notes/{debitNote}',                 [PurchaseDebitNoteController::class, 'show']);
    Route::put('/debit-notes/{debitNote}',                 [PurchaseDebitNoteController::class, 'update']);
    Route::delete('/debit-notes/{debitNote}',              [PurchaseDebitNoteController::class, 'destroy']); // Draft only
    // Issuing adjusts inventory (reverse of a goods receipt) — operational, like GRN confirm.
    Route::post('/debit-notes/{debitNote}/issue',          [PurchaseDebitNoteController::class, 'issue']);

    // ── Quotations (RFQ) — sourcing upstream of PR→PO ────────────────────
    // Preparing RFQs, sending to vendors, recording quotes and comparing are
    // operational (staff). Awarding (→ PO) is admin authority, below.
    Route::get('/rfqs/stats',                     [PurchaseRfqController::class, 'stats']);
    Route::get('/rfqs',                           [PurchaseRfqController::class, 'index']);
    Route::post('/rfqs',                          [PurchaseRfqController::class, 'store']);
    Route::get('/rfqs/{rfq}',                     [PurchaseRfqController::class, 'show']);
    Route::get('/rfqs/{rfq}/comparison',          [PurchaseRfqController::class, 'comparison']);
    Route::put('/rfqs/{rfq}',                     [PurchaseRfqController::class, 'update']);
    Route::delete('/rfqs/{rfq}',                  [PurchaseRfqController::class, 'destroy']); // Draft only
    Route::post('/rfqs/{rfq}/send',              [PurchaseRfqController::class, 'send']);
    Route::post('/rfqs/{rfq}/cancel',            [PurchaseRfqController::class, 'cancel']);
    // Record a vendor's quotation against the RFQ.
    Route::post('/rfqs/{rfq}/quotations',         [PurchaseQuotationController::class, 'store']);

    Route::get('/quotations',                     [PurchaseQuotationController::class, 'index']);
    Route::get('/quotations/{quotation}',         [PurchaseQuotationController::class, 'show']);
    Route::put('/quotations/{quotation}',         [PurchaseQuotationController::class, 'update']);
    Route::post('/quotations/{quotation}/shortlist', [PurchaseQuotationController::class, 'shortlist']);
    Route::post('/quotations/{quotation}/reject',    [PurchaseQuotationController::class, 'reject']);

    // ── Contracts (MSA / rate contracts) ────────────────────────────────
    // Authoring, submitting for review and uploading the agreement are staff
    // work. Activating (makes it binding) and terminating are admin, below.
    Route::get('/contracts/stats',                [PurchaseContractController::class, 'stats']);
    Route::get('/contracts/referenceable',        [PurchaseContractController::class, 'referenceable']);
    Route::get('/contracts',                      [PurchaseContractController::class, 'index']);
    Route::post('/contracts',                     [PurchaseContractController::class, 'store']);
    Route::get('/contracts/{contract}',           [PurchaseContractController::class, 'show']);
    Route::put('/contracts/{contract}',           [PurchaseContractController::class, 'update']);
    Route::delete('/contracts/{contract}',        [PurchaseContractController::class, 'destroy']); // Draft only
    Route::post('/contracts/{contract}/submit',   [PurchaseContractController::class, 'submit']);
    Route::post('/contracts/{contract}/return',   [PurchaseContractController::class, 'returnToDraft']);
    Route::post('/contracts/{contract}/document', [PurchaseContractController::class, 'uploadDocument']);
    Route::get('/contracts/{contract}/download',  [PurchaseContractController::class, 'download']);

    // ── Catalog (item master) ───────────────────────────────────────────
    // Reference data, not a spend commitment — staff-level throughout (no
    // admin-only gate). /search returns Active items only, for line pickers.
    Route::get('/catalog/stats',                  [PurchaseCatalogController::class, 'stats']);
    Route::get('/catalog/search',                 [PurchaseCatalogController::class, 'search']);
    Route::get('/catalog',                        [PurchaseCatalogController::class, 'index']);
    Route::post('/catalog',                       [PurchaseCatalogController::class, 'store']);
    Route::get('/catalog/{catalogItem}',          [PurchaseCatalogController::class, 'show']);
    Route::put('/catalog/{catalogItem}',          [PurchaseCatalogController::class, 'update']);
    Route::post('/catalog/{catalogItem}/status',  [PurchaseCatalogController::class, 'setStatus']);
    Route::delete('/catalog/{catalogItem}',       [PurchaseCatalogController::class, 'destroy']); // Draft only
});

// Approval authority is admin-only — a requester must not approve their own PR.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('purchase')->group(function () {

    Route::post('/requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve']);
    Route::post('/requests/{purchaseRequest}/reject',  [PurchaseRequestController::class, 'reject']);

    // Issuing/closing/cancelling a PO commits or unwinds spend — admin authority.
    Route::post('/orders/{purchaseOrder}/issue',  [PurchaseOrderController::class, 'issue']);
    Route::post('/orders/{purchaseOrder}/close',  [PurchaseOrderController::class, 'close']);
    Route::post('/orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);

    // Approving a payable and recording money out are admin authority.
    Route::post('/invoices/{purchaseInvoice}/approve',                [PurchaseInvoiceController::class, 'approve']);
    Route::post('/invoices/{purchaseInvoice}/payments',               [PurchaseInvoiceController::class, 'recordPayment']);
    Route::delete('/invoices/{purchaseInvoice}/payments/{payment}',   [PurchaseInvoiceController::class, 'deletePayment']);
    Route::post('/invoices/{purchaseInvoice}/cancel',                 [PurchaseInvoiceController::class, 'cancel']);

    // Recording vendor refunds (money in) and cancelling are admin authority.
    Route::post('/debit-notes/{debitNote}/refunds',                   [PurchaseDebitNoteController::class, 'recordRefund']);
    Route::delete('/debit-notes/{debitNote}/refunds/{refund}',        [PurchaseDebitNoteController::class, 'deleteRefund']);
    // Credit netting — apply an open debit-note balance against a payable invoice.
    Route::get('/debit-notes/{debitNote}/applicable-invoices',        [PurchaseDebitNoteController::class, 'applicableInvoices']);
    Route::post('/debit-notes/{debitNote}/applications',              [PurchaseDebitNoteController::class, 'applyCredit']);
    Route::delete('/debit-notes/{debitNote}/applications/{application}', [PurchaseDebitNoteController::class, 'reverseCredit']);
    Route::post('/debit-notes/{debitNote}/cancel',                    [PurchaseDebitNoteController::class, 'cancel']);

    // Awarding a quotation creates a PO — commits the tenant to spend, so it is
    // admin authority, like PR-approval and PO-issue.
    Route::post('/quotations/{quotation}/award',   [PurchaseQuotationController::class, 'award']);

    // Activating a contract makes it binding; terminating ends it early — both
    // admin authority.
    Route::post('/contracts/{contract}/activate',  [PurchaseContractController::class, 'activate']);
    Route::post('/contracts/{contract}/terminate', [PurchaseContractController::class, 'terminate']);
});
