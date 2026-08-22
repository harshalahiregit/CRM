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
use App\Http\Controllers\Api\Purchase\PurchaseOnboardingController;
use App\Http\Controllers\Api\Purchase\PurchaseVendorDocumentController;
use App\Http\Controllers\Api\Purchase\PurchaseContactController;
use App\Http\Controllers\Api\Purchase\PurchaseKickoffController;
use App\Http\Controllers\Api\Purchase\PurchaseApprovalController;
use App\Http\Controllers\Api\Purchase\PurchaseVendorController;
use App\Http\Controllers\Api\Purchase\PurchaseVendorItemController;
use App\Http\Controllers\Api\Purchase\PurchaseWorkforceAdminController;
use App\Http\Controllers\Api\Purchase\PurchaseOrderReturnController;
use App\Http\Controllers\Api\Purchase\PurchaseReportController;
use App\Http\Controllers\Api\Purchase\PurchaseSettingController;
use App\Http\Controllers\Api\Purchase\PurchaseVendorCategoryController;
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

    // ── Settings (read) — the module's key/value config + category master ──
    // Writes live in the role:admin group below: config is an admin concern.
    Route::get('/settings',           [PurchaseSettingController::class, 'index']);
    Route::get('/vendor-categories',  [PurchaseVendorCategoryController::class, 'index']);

    // ── Reports (read-only aggregations, ?period=…) ────────────────────────
    Route::get('/reports/filters',        [PurchaseReportController::class, 'filters']);
    Route::get('/reports/item-cost',      [PurchaseReportController::class, 'itemCost']);
    Route::get('/reports/po-voucher',     [PurchaseReportController::class, 'poVoucher']);
    Route::get('/reports/orders',         [PurchaseReportController::class, 'orders']);
    Route::get('/reports/invoices',       [PurchaseReportController::class, 'invoices']);
    Route::get('/reports/stats-by-count', [PurchaseReportController::class, 'statsByCount']);
    Route::get('/reports/stats-by-cost',  [PurchaseReportController::class, 'statsByCost']);

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

    // ── Purchase Vendor master (Purchase-owned entity: purchase_vendors) ───
    // Independent Purchase Vendor master (purchase_vendors / purchase_vendor_id).
    Route::get('/vendors/stats',                     [PurchaseVendorController::class, 'stats']);
    Route::get('/vendors',                           [PurchaseVendorController::class, 'index']);
    Route::post('/vendors',                          [PurchaseVendorController::class, 'store']);
    Route::get('/vendors/{purchaseVendor}',          [PurchaseVendorController::class, 'show'])->whereNumber('purchaseVendor');
    Route::get('/vendors/{purchaseVendor}/tasks',    [PurchaseVendorController::class, 'tasks'])->whereNumber('purchaseVendor');
    // Workspace Overview dashboard (live per-vendor counts) + directly-linked customers.
    Route::get('/vendors/{purchaseVendor}/overview',  [PurchaseVendorController::class, 'overview'])->whereNumber('purchaseVendor');
    Route::get('/vendors/{purchaseVendor}/customers', [PurchaseVendorController::class, 'customers'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/customers', [PurchaseVendorController::class, 'storeCustomer'])->whereNumber('purchaseVendor');
    Route::put('/vendors/{purchaseVendor}',          [PurchaseVendorController::class, 'update'])->whereNumber('purchaseVendor');
    Route::patch('/vendors/{purchaseVendor}/status', [PurchaseVendorController::class, 'updateStatus'])->whereNumber('purchaseVendor');
    Route::delete('/vendors/{purchaseVendor}',       [PurchaseVendorController::class, 'destroy'])->whereNumber('purchaseVendor');

    // ── Vendor detail workspace: the tabs that hang off one vendor ─────────
    // All inside THIS group, so they inherit auth:sanctum + role:admin,staff.
    // Never chain a second ->middleware(): it replaces rather than appends and
    // silently drops auth:sanctum (see the note at the top of this file).
    //
    // Commercial: payments and the statement are native — every purchase document
    // already keys to purchase_vendor_id, so there is no link step.
    Route::get('/vendors/{purchaseVendor}/payments',  [PurchaseVendorController::class, 'payments'])->whereNumber('purchaseVendor');
    Route::get('/vendors/{purchaseVendor}/statement', [PurchaseVendorController::class, 'statement'])->whereNumber('purchaseVendor');

    // Appointments — shared `appointments` table, mirrored here because
    // /api/sales/appointments carries no role gate and takes subject_type as a
    // free string.
    Route::get('/vendors/{purchaseVendor}/appointments',                       [PurchaseVendorController::class, 'appointments'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/appointments',                      [PurchaseVendorController::class, 'storeAppointment'])->whereNumber('purchaseVendor');
    Route::patch('/vendors/{purchaseVendor}/appointments/{appointment}/complete', [PurchaseVendorController::class, 'completeAppointment'])->whereNumber('purchaseVendor');
    Route::delete('/vendors/{purchaseVendor}/appointments/{appointment}',      [PurchaseVendorController::class, 'destroyAppointment'])->whereNumber('purchaseVendor');

    // Notes — shared polymorphic `notes` table.
    Route::get('/vendors/{purchaseVendor}/notes',            [PurchaseVendorController::class, 'notes'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/notes',           [PurchaseVendorController::class, 'storeNote'])->whereNumber('purchaseVendor');
    Route::put('/vendors/{purchaseVendor}/notes/{note}',     [PurchaseVendorController::class, 'updateNote'])->whereNumber('purchaseVendor');
    Route::delete('/vendors/{purchaseVendor}/notes/{note}',  [PurchaseVendorController::class, 'destroyNote'])->whereNumber('purchaseVendor');

    // Reminders — shared polymorphic `reminders` table. No update action by
    // design: ReminderService::update can retarget a reminder at another record.
    Route::get('/vendors/{purchaseVendor}/reminders',                        [PurchaseVendorController::class, 'reminders'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/reminders',                       [PurchaseVendorController::class, 'storeReminder'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/reminders/{reminder}/complete',   [PurchaseVendorController::class, 'completeReminder'])->whereNumber('purchaseVendor');
    Route::delete('/vendors/{purchaseVendor}/reminders/{reminder}',          [PurchaseVendorController::class, 'destroyReminder'])->whereNumber('purchaseVendor');

    // Attachments — shared folder tree. Static segments and the folder routes
    // are declared ahead of the bare {attachment} wildcard.
    Route::get('/vendors/{purchaseVendor}/attachments',                            [PurchaseVendorController::class, 'attachments'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/attachments',                           [PurchaseVendorController::class, 'storeAttachment'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/attachment-folders',                    [PurchaseVendorController::class, 'storeAttachmentFolder'])->whereNumber('purchaseVendor');
    Route::put('/vendors/{purchaseVendor}/attachment-folders/{folder}',            [PurchaseVendorController::class, 'updateAttachmentFolder'])->whereNumber('purchaseVendor');
    Route::delete('/vendors/{purchaseVendor}/attachment-folders/{folder}',         [PurchaseVendorController::class, 'destroyAttachmentFolder'])->whereNumber('purchaseVendor');
    Route::get('/vendors/{purchaseVendor}/attachments/{attachment}/download',      [PurchaseVendorController::class, 'downloadAttachment'])->whereNumber('purchaseVendor');
    Route::put('/vendors/{purchaseVendor}/attachments/{attachment}',               [PurchaseVendorController::class, 'updateAttachment'])->whereNumber('purchaseVendor');
    Route::delete('/vendors/{purchaseVendor}/attachments/{attachment}',            [PurchaseVendorController::class, 'destroyAttachment'])->whereNumber('purchaseVendor');

    // ── Vendor Items — Purchase Vendor ↔ Inventory Item mapping ────────────
    // Purchase owns the LINK only; inventory_products stays the Item Master and
    // is joined read-only. Item groups/items themselves come from Inventory APIs.
    Route::get('/vendor-items/stats',        [PurchaseVendorItemController::class, 'stats']);
    Route::get('/vendor-items',              [PurchaseVendorItemController::class, 'index']);
    Route::post('/vendor-items',             [PurchaseVendorItemController::class, 'store']);
    Route::get('/vendor-items/{vendorItem}', [PurchaseVendorItemController::class, 'show'])->whereNumber('vendorItem');
    Route::put('/vendor-items/{vendorItem}', [PurchaseVendorItemController::class, 'update'])->whereNumber('vendorItem');
    Route::delete('/vendor-items/{vendorItem}', [PurchaseVendorItemController::class, 'destroy'])->whereNumber('vendorItem');

    // ── Order Returns — goods returned to a Purchase Vendor (OR-####) ──────
    // A separate document from debit notes: own number series + line discounts.
    Route::get('/order-returns/stats',          [PurchaseOrderReturnController::class, 'stats']);
    Route::get('/order-returns',                [PurchaseOrderReturnController::class, 'index']);
    Route::post('/order-returns',               [PurchaseOrderReturnController::class, 'store']);
    Route::get('/order-returns/{orderReturn}',  [PurchaseOrderReturnController::class, 'show'])->whereNumber('orderReturn');
    Route::put('/order-returns/{orderReturn}',  [PurchaseOrderReturnController::class, 'update'])->whereNumber('orderReturn');
    Route::delete('/order-returns/{orderReturn}', [PurchaseOrderReturnController::class, 'destroy'])->whereNumber('orderReturn');
    // Lifecycle: Draft → Issued → Completed, or Cancelled.
    Route::post('/order-returns/{orderReturn}/issue',    [PurchaseOrderReturnController::class, 'issue'])->whereNumber('orderReturn');
    Route::post('/order-returns/{orderReturn}/complete', [PurchaseOrderReturnController::class, 'complete'])->whereNumber('orderReturn');
    Route::post('/order-returns/{orderReturn}/cancel',   [PurchaseOrderReturnController::class, 'cancel'])->whereNumber('orderReturn');

    // ── Vendor onboarding (6-step wizard) ──────────────────────────────────
    Route::get('/onboarding/stats',                  [PurchaseOnboardingController::class, 'stats']);
    Route::get('/onboarding',                        [PurchaseOnboardingController::class, 'index']);
    Route::post('/onboarding',                       [PurchaseOnboardingController::class, 'store']);
    Route::get('/onboarding/{onboarding}',           [PurchaseOnboardingController::class, 'show']);
    Route::get('/onboarding/{onboarding}/progress',  [PurchaseOnboardingController::class, 'progress']);
    Route::post('/onboarding/{onboarding}/profile',  [PurchaseOnboardingController::class, 'saveProfile']);
    Route::patch('/onboarding/{onboarding}/step',    [PurchaseOnboardingController::class, 'setStep']);
    Route::post('/onboarding/{onboarding}/submit',   [PurchaseOnboardingController::class, 'submit']);
    Route::delete('/onboarding/{onboarding}',        [PurchaseOnboardingController::class, 'destroy']);
    // Step 1 — kickoff MOM PDF / acknowledgement (Purchase-owned kickoff engine).
    Route::get('/onboarding/{onboarding}/kickoff',        [PurchaseOnboardingController::class, 'kickoffPdf']);
    Route::post('/onboarding/{onboarding}/kickoff/accept',[PurchaseOnboardingController::class, 'acceptKickoff']);
    Route::post('/onboarding/{onboarding}/kickoff/log',   [PurchaseOnboardingController::class, 'logKickoffEvent']);
    // Approval chain (read) — Registration → Document → Commercial → Purchase → Activation.
    Route::get('/onboarding/{onboarding}/approvals',      [PurchaseApprovalController::class, 'index']);

    // ── Purchase workforce (admin/staff review) ────────────────────────────
    // Tenant-scoped, not vendor-scoped: an admin legitimately sees every vendor's
    // workers. Badge ACTIVATION is not here — it sits in the role:admin group
    // below, so staff can review but not decide who may enter the site.
    Route::get('/workforce/workers',                  [PurchaseWorkforceAdminController::class, 'index']);
    Route::get('/workforce/workers/{worker}',         [PurchaseWorkforceAdminController::class, 'show']);
    Route::get('/workforce/workers/{worker}/ppe',     [PurchaseWorkforceAdminController::class, 'ppe']);
    Route::get('/workforce/workers/{worker}/gate',    [PurchaseWorkforceAdminController::class, 'gate']);
    // Vendor detail Medical / Training tabs. Vendor-scoped (?vendor_id=) and
    // strict about it — declared before the {worker} wildcard above would ever
    // be consulted, since these are static segments.
    Route::get('/workforce/medicals',                 [PurchaseWorkforceAdminController::class, 'medicals']);
    Route::get('/workforce/trainings',                [PurchaseWorkforceAdminController::class, 'trainings']);

    // ── Compliance register (mirror of TPV §21 — purchase_vendor_compliance) ─
    Route::get('/vendor-compliance',                              [\App\Http\Controllers\Api\Purchase\PurchaseComplianceController::class, 'index']);
    Route::get('/vendors/{purchaseVendor}/compliance',            [\App\Http\Controllers\Api\Purchase\PurchaseComplianceController::class, 'vendorMatrix'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/compliance',           [\App\Http\Controllers\Api\Purchase\PurchaseComplianceController::class, 'upsert'])->whereNumber('purchaseVendor');
    Route::delete('/vendor-compliance/{compliance}',              [\App\Http\Controllers\Api\Purchase\PurchaseComplianceController::class, 'destroy'])->whereNumber('compliance');

    // ── Non-Conformance Reports (mirror of TPV §24 — purchase_ncrs) ─────────
    Route::get('/ncrs',                    [\App\Http\Controllers\Api\Purchase\PurchaseNcrController::class, 'index']);
    Route::post('/ncrs',                   [\App\Http\Controllers\Api\Purchase\PurchaseNcrController::class, 'store']);
    Route::put('/ncrs/{ncr}',              [\App\Http\Controllers\Api\Purchase\PurchaseNcrController::class, 'update'])->whereNumber('ncr');
    Route::post('/ncrs/{ncr}/transition',  [\App\Http\Controllers\Api\Purchase\PurchaseNcrController::class, 'transition'])->whereNumber('ncr');
    Route::delete('/ncrs/{ncr}',           [\App\Http\Controllers\Api\Purchase\PurchaseNcrController::class, 'destroy'])->whereNumber('ncr');

    // ── CAPA register (mirror of TPV §25 — purchase_capas) ─────────────────
    Route::get('/capas',                   [\App\Http\Controllers\Api\Purchase\PurchaseCapaController::class, 'index']);
    Route::post('/capas',                  [\App\Http\Controllers\Api\Purchase\PurchaseCapaController::class, 'store']);
    Route::put('/capas/{capa}',            [\App\Http\Controllers\Api\Purchase\PurchaseCapaController::class, 'update'])->whereNumber('capa');
    Route::post('/capas/{capa}/transition',[\App\Http\Controllers\Api\Purchase\PurchaseCapaController::class, 'transition'])->whereNumber('capa');
    Route::delete('/capas/{capa}',         [\App\Http\Controllers\Api\Purchase\PurchaseCapaController::class, 'destroy'])->whereNumber('capa');

    // ── Governance analytics (mirror of TPV §33 — distinct from /reports/*) ─
    Route::get('/analytics',               [\App\Http\Controllers\Api\Purchase\PurchaseAnalyticsController::class, 'index']);
    Route::get('/analytics/export',        [\App\Http\Controllers\Api\Purchase\PurchaseAnalyticsController::class, 'export']);

    // ── Document Vault (mirror of TPV §30 — read-only over 4 stores) ────────
    Route::get('/document-vault',                        [\App\Http\Controllers\Api\Purchase\PurchaseDocumentVaultController::class, 'index']);
    Route::get('/vendors/{purchaseVendor}/vault',        [\App\Http\Controllers\Api\Purchase\PurchaseDocumentVaultController::class, 'vendor'])->whereNumber('purchaseVendor');

    // ── Communications Centre (mirror of TPV §31 — derived alerts + send/log) ─
    Route::get('/communications',          [\App\Http\Controllers\Api\Purchase\PurchaseCommunicationController::class, 'index']);
    Route::post('/communications/send',    [\App\Http\Controllers\Api\Purchase\PurchaseCommunicationController::class, 'send']);

    // ── Inspections & Audits (mirror of TPV §22 — escalate finding → NCR) ───
    Route::get('/inspections',                             [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'index']);
    Route::post('/inspections',                            [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'store']);
    Route::get('/inspections/{inspection}',               [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'show'])->whereNumber('inspection');
    Route::put('/inspections/{inspection}',               [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'update'])->whereNumber('inspection');
    Route::delete('/inspections/{inspection}',            [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'destroy'])->whereNumber('inspection');
    Route::post('/inspections/{inspection}/findings',     [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'addFinding'])->whereNumber('inspection');
    Route::put('/inspection-findings/{finding}',          [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'updateFinding'])->whereNumber('finding');
    Route::delete('/inspection-findings/{finding}',       [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'destroyFinding'])->whereNumber('finding');
    Route::post('/inspection-findings/{finding}/escalate', [\App\Http\Controllers\Api\Purchase\PurchaseInspectionController::class, 'escalateFinding'])->whereNumber('finding');

    // ── Vendor Violations & Strikes (mirror of TPV §26 — points → enforce) ──
    Route::get('/violations',                             [\App\Http\Controllers\Api\Purchase\PurchaseViolationController::class, 'index']);
    Route::post('/violations',                            [\App\Http\Controllers\Api\Purchase\PurchaseViolationController::class, 'store']);
    Route::put('/violations/{violation}',                 [\App\Http\Controllers\Api\Purchase\PurchaseViolationController::class, 'update'])->whereNumber('violation');
    Route::delete('/violations/{violation}',              [\App\Http\Controllers\Api\Purchase\PurchaseViolationController::class, 'destroy'])->whereNumber('violation');
    Route::get('/vendors/{purchaseVendor}/violation-escalation', [\App\Http\Controllers\Api\Purchase\PurchaseViolationController::class, 'escalation'])->whereNumber('purchaseVendor');
    Route::post('/vendors/{purchaseVendor}/violation-enforce',   [\App\Http\Controllers\Api\Purchase\PurchaseViolationController::class, 'enforce'])->whereNumber('purchaseVendor');

    // ── Vendor Performance Index (mirror of TPV §27 — governance-scored) ────
    Route::get('/vpi',                     [\App\Http\Controllers\Api\Purchase\PurchaseVendorPerformanceController::class, 'index']);
    Route::get('/vendors/{purchaseVendor}/vpi', [\App\Http\Controllers\Api\Purchase\PurchaseVendorPerformanceController::class, 'show'])->whereNumber('purchaseVendor');

    // ── Renewal & Extension (mirror of TPV §28 — assess via VPI + decide) ───
    Route::get('/renewals',                          [\App\Http\Controllers\Api\Purchase\PurchaseRenewalController::class, 'index']);
    Route::post('/renewals',                         [\App\Http\Controllers\Api\Purchase\PurchaseRenewalController::class, 'store']);
    Route::get('/vendors/{purchaseVendor}/renewal-assessment', [\App\Http\Controllers\Api\Purchase\PurchaseRenewalController::class, 'assess'])->whereNumber('purchaseVendor');
    Route::post('/renewals/{renewal}/reassess',      [\App\Http\Controllers\Api\Purchase\PurchaseRenewalController::class, 'reassess'])->whereNumber('renewal');
    Route::post('/renewals/{renewal}/decide',        [\App\Http\Controllers\Api\Purchase\PurchaseRenewalController::class, 'decide'])->whereNumber('renewal');
    Route::delete('/renewals/{renewal}',             [\App\Http\Controllers\Api\Purchase\PurchaseRenewalController::class, 'destroy'])->whereNumber('renewal');

    // ── Offboarding / Closure (mirror of TPV §29 — checklist → final status) ─
    Route::get('/offboardings',                      [\App\Http\Controllers\Api\Purchase\PurchaseOffboardingController::class, 'index']);
    Route::post('/offboardings',                     [\App\Http\Controllers\Api\Purchase\PurchaseOffboardingController::class, 'store']);
    Route::get('/offboardings/{offboarding}',        [\App\Http\Controllers\Api\Purchase\PurchaseOffboardingController::class, 'show'])->whereNumber('offboarding');
    Route::put('/offboardings/{offboarding}/checklist', [\App\Http\Controllers\Api\Purchase\PurchaseOffboardingController::class, 'updateChecklist'])->whereNumber('offboarding');
    Route::post('/offboardings/{offboarding}/complete', [\App\Http\Controllers\Api\Purchase\PurchaseOffboardingController::class, 'complete'])->whereNumber('offboarding');
    Route::delete('/offboardings/{offboarding}',     [\App\Http\Controllers\Api\Purchase\PurchaseOffboardingController::class, 'destroy'])->whereNumber('offboarding');

    // ── Meetings (Purchase-owned engine: purchase_kickoff_* tables) ─────────
    // Kickoff is one configurable meeting type here, not a separate module (§9/§39).
    Route::get('/meeting-types',                    [PurchaseKickoffController::class, 'meetingTypes']);
    Route::get('/kickoff/stats',                   [PurchaseKickoffController::class, 'stats']);
    Route::get('/kickoff',                         [PurchaseKickoffController::class, 'index']);
    Route::post('/kickoff',                        [PurchaseKickoffController::class, 'store']);
    Route::get('/kickoff/{kickoff}',               [PurchaseKickoffController::class, 'show']);
    Route::put('/kickoff/{kickoff}',               [PurchaseKickoffController::class, 'update']);
    Route::post('/kickoff/{kickoff}/transition',   [PurchaseKickoffController::class, 'transition']);
    Route::patch('/kickoff/{kickoff}/attendance',  [PurchaseKickoffController::class, 'attendance']);
    Route::post('/kickoff/{kickoff}/remind',       [PurchaseKickoffController::class, 'remind']);
    Route::post('/kickoff/{kickoff}/mom',          [PurchaseKickoffController::class, 'uploadMom']);
    Route::post('/kickoff/{kickoff}/mom/generate', [PurchaseKickoffController::class, 'generateMom']);
    Route::get('/kickoff/{kickoff}/mom',           [PurchaseKickoffController::class, 'momFile']);
    Route::post('/kickoff/{kickoff}/publish',      [PurchaseKickoffController::class, 'publish']);
    Route::delete('/kickoff/{kickoff}',            [PurchaseKickoffController::class, 'destroy']);

    // ── Vendor contacts (Purchase-owned engine: purchase_contacts) ─────────
    Route::get('/vendors/{vendor}/contacts',                    [PurchaseContactController::class, 'index']);
    Route::post('/vendors/{vendor}/contacts',                   [PurchaseContactController::class, 'store']);
    Route::get('/vendors/{vendor}/contacts/{contact}',         [PurchaseContactController::class, 'show']);
    Route::put('/vendors/{vendor}/contacts/{contact}',         [PurchaseContactController::class, 'update']);
    Route::patch('/vendors/{vendor}/contacts/{contact}/status',[PurchaseContactController::class, 'setStatus']);
    Route::delete('/vendors/{vendor}/contacts/{contact}',      [PurchaseContactController::class, 'destroy']);

    // ── Vendor documents (Purchase-owned engine: purchase_documents) ─────
    // Purchase-scoped surface over the SAME model/service. Review is admin, below.
    Route::get('/vendors/{vendor}/documents',                       [PurchaseVendorDocumentController::class, 'checklist']);
    Route::post('/vendors/{vendor}/documents',                      [PurchaseVendorDocumentController::class, 'upload']);
    Route::get('/documents/{document}/download',                    [PurchaseVendorDocumentController::class, 'download']);
    Route::post('/documents/{document}/resubmit',                   [PurchaseVendorDocumentController::class, 'resubmit']);
    Route::delete('/documents/{document}',                          [PurchaseVendorDocumentController::class, 'destroy']);
    Route::get('/documents/{document}/versions',                    [PurchaseVendorDocumentController::class, 'versions']);
    Route::get('/documents/{document}/versions/{version}/download', [PurchaseVendorDocumentController::class, 'downloadVersion']);
    Route::post('/documents/{document}/versions/{version}/restore', [PurchaseVendorDocumentController::class, 'restoreVersion']);
});

// Approval authority is admin-only — a requester must not approve their own PR.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('purchase')->group(function () {

    // ── Settings writes — module configuration is an admin concern ─────────
    Route::put('/settings', [PurchaseSettingController::class, 'update']);
    Route::post('/vendor-categories',                    [PurchaseVendorCategoryController::class, 'store']);
    Route::put('/vendor-categories/{vendorCategory}',    [PurchaseVendorCategoryController::class, 'update'])->whereNumber('vendorCategory');
    Route::delete('/vendor-categories/{vendorCategory}', [PurchaseVendorCategoryController::class, 'destroy'])->whereNumber('vendorCategory');

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

    // Workforce step 5 — activating a worker admits a person to the site, so it
    // is an admin decision, not a staff one and never the vendor's.
    Route::post('/workforce/workers/{worker}/activate',   [PurchaseWorkforceAdminController::class, 'activate']);
    // Worker lifecycle — suspend/reinstate/terminate withhold or restore site access.
    Route::post('/workforce/workers/{worker}/suspend',    [PurchaseWorkforceAdminController::class, 'suspend']);
    Route::post('/workforce/workers/{worker}/reinstate',  [PurchaseWorkforceAdminController::class, 'reinstate']);
    Route::post('/workforce/workers/{worker}/terminate',  [PurchaseWorkforceAdminController::class, 'terminate']);
    Route::post('/workforce/ppe/issues/{issue}/return',   [PurchaseWorkforceAdminController::class, 'returnPpe']);

    // Vendor onboarding decisions — a requester must not approve their own vendor.
    Route::post('/onboarding/{onboarding}/approve',  [PurchaseOnboardingController::class, 'approve']);
    Route::post('/onboarding/{onboarding}/reject',   [PurchaseOnboardingController::class, 'reject']);
    Route::post('/onboarding/{onboarding}/hold',     [PurchaseOnboardingController::class, 'hold']);
    Route::post('/onboarding/{onboarding}/release',  [PurchaseOnboardingController::class, 'release']);
    Route::post('/onboarding/{onboarding}/resubmit', [PurchaseOnboardingController::class, 'requestResubmit']);
    // Per-stage approval decisions (Purchase-owned approval chain).
    Route::post('/onboarding/{onboarding}/approvals/{stage}/approve', [PurchaseApprovalController::class, 'approve']);
    Route::post('/onboarding/{onboarding}/approvals/{stage}/reject',  [PurchaseApprovalController::class, 'reject']);

    // Purchase Vendor activation (admin authority).
    Route::post('/vendors/{purchaseVendor}/approve', [PurchaseVendorController::class, 'approve'])->whereNumber('purchaseVendor');
    // Manual resend of the activation e-mail (admin authority, like activation).
    Route::post('/vendors/{purchaseVendor}/resend-activation', [PurchaseVendorController::class, 'resendActivation'])->whereNumber('purchaseVendor');

    // Admin approve/reject a purchase vendor's statutory document.
    Route::post('/documents/{document}/review',      [PurchaseVendorDocumentController::class, 'review']);
});
