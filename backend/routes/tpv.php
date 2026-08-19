<?php

use App\Http\Controllers\Api\Tpv\GateScanController;
use App\Http\Controllers\Api\Tpv\OnboardingApprovalController;
use App\Http\Controllers\Api\Tpv\TpvAccessController;
use App\Http\Controllers\Api\Tpv\TpvContactController;
use App\Http\Controllers\Api\Tpv\TpvDashboardController;
use App\Http\Controllers\Api\Tpv\TpvGateLogController;
use App\Http\Controllers\Api\Tpv\TpvOnboardingController;
use App\Http\Controllers\Api\Tpv\TpvSafetyStrikeController;
use App\Http\Controllers\Api\Tpv\TpvWorkerController;
use App\Http\Controllers\Api\Tpv\VendorDocumentController;
use Illuminate\Support\Facades\Route;

// ── PUBLIC — the site gate ──────────────────────────────────────────────
// No auth by design: a guard scans a badge with a phone. The 48-char QR token
// is the bearer credential — possession of the card authorises seeing the pass.
// Rate-limited per IP, and every scan is written to the gate log.
Route::prefix('scan')->middleware('throttle:60,1')->group(function () {
    // Read-only: shows the pass card, never mutates attendance.
    Route::get('/{token}',            [GateScanController::class, 'scan']);
    // Explicit actions, so a verification scan can't accidentally sign someone out.
    Route::post('/{token}/check-in',  [GateScanController::class, 'checkIn']);
    Route::post('/{token}/check-out', [GateScanController::class, 'checkOut']);
})->where(['token' => '[A-Za-z0-9]{20,64}']);

// Public Standalone QR Scan Verification (Matching reference scan_access)
Route::get('/scan-access/{token}', [TpvWorkerController::class, 'scanAccess']);

// ── TPV Module (Sanctum + role:admin,staff) ─────────────────────────────
// Vendor/workforce data must never cross tenants or reach a client login, so
// this group is role-gated from day one (ARCHITECTURE-PRIMER §1).
//
// The vendor-facing side of the wizard IS built: it lives in routes/portal.php
// behind `vendor.portal`, where every handler resolves the vendor from the token
// and calls assertOwned(). That is the ONLY door a third_party_vendor uses.
//
// This group must stay admin,staff. It once read
// `role:admin,staff,third_party_vendor,vendor` — added so the portal could reach
// two worker endpoints — but the gate covers the whole module, and the handlers
// below check only the TENANT, never the vendor. A vendor login could therefore
// list, read, overwrite and delete every OTHER vendor's onboarding (bank, GST,
// PAN, documents) through /tpv/onboarding, which defeats the portal's ownership
// checks entirely. Those two endpoints now have portal equivalents:
// POST /portal/workers/{worker}/mark-punch and .../mark-card-status.
//
// A vendor's own temporary-access countdown has its own narrow group at the
// bottom of this file; nothing else here is vendor-reachable.
//
// NOTE: both middleware must go in ONE ->middleware([...]) call — chaining a
// second ->middleware() replaces the first and silently drops auth:sanctum.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('tpv')->group(function () {

    // Dashboard — read-only aggregation across the whole module.
    Route::get('/dashboard',                          [TpvDashboardController::class, 'index']);

    // Advanced approval workflow — staff can decide their own level (e.g. L1).
    Route::get('/approvals',                           [OnboardingApprovalController::class, 'index']);
    Route::get('/onboarding/{onboarding}/approvals',   [OnboardingApprovalController::class, 'history']);
    Route::post('/onboarding/{onboarding}/approvals/decide', [OnboardingApprovalController::class, 'decide']);

    // Temporary TPV access — creation & extension are staff-authority.
    Route::get('/vendors/temporary',                  [TpvAccessController::class, 'index']);
    Route::post('/vendors/temporary',                 [TpvAccessController::class, 'createTemporary']);
    Route::post('/vendors/{vendor}/access/extend',    [TpvAccessController::class, 'extend']);

    // Onboarding — 6-step wizard over the shared vendor master
    Route::get('/onboarding/stats',                   [TpvOnboardingController::class, 'stats']);
    Route::get('/onboarding',                         [TpvOnboardingController::class, 'index']);
    Route::post('/onboarding',                        [TpvOnboardingController::class, 'store']);
    Route::get('/onboarding/{onboarding}',            [TpvOnboardingController::class, 'show']);
    Route::get('/onboarding/{onboarding}/progress',   [TpvOnboardingController::class, 'progress']);
    // Step 1 — Kickoff PDF: stream, acknowledge, and log view/download/print.
    Route::get('/onboarding/{onboarding}/kickoff',         [TpvOnboardingController::class, 'kickoffPdf']);
    Route::get('/onboarding/{onboarding}/work-start-letter', [TpvOnboardingController::class, 'workStartLetter']);
    Route::post('/onboarding/{onboarding}/kickoff/accept', [TpvOnboardingController::class, 'acceptKickoff']);
    Route::post('/onboarding/{onboarding}/kickoff/log',    [TpvOnboardingController::class, 'logKickoffEvent']);
    Route::post('/onboarding/{onboarding}/profile',   [TpvOnboardingController::class, 'saveProfile']);
    Route::patch('/onboarding/{onboarding}/step',     [TpvOnboardingController::class, 'setStep']);
    Route::post('/onboarding/{onboarding}/submit',    [TpvOnboardingController::class, 'submit']);
    Route::delete('/onboarding/{onboarding}',         [TpvOnboardingController::class, 'destroy']);

    // Vendor contacts — the master contact list per vendor (reused across the
    // module). No hard delete: deactivation is a status change. Static segments
    // stay ahead of the {contact} wildcard.
    Route::get('/vendors/{vendor}/tasks',                     [\App\Http\Controllers\Api\Vendor\VendorController::class, 'tasks']);
    // Workspace Overview dashboard (live per-vendor counts) + directly-linked customers.
    Route::get('/vendors/{vendor}/overview',                  [\App\Http\Controllers\Api\Vendor\VendorController::class, 'overview']);
    // Vendor Rating System scorecard (Doc 5) — live score + persisted history.
    Route::get('/vendors/{vendor}/scorecard',                 [\App\Http\Controllers\Api\Vendor\VendorController::class, 'scorecard']);
    Route::get('/vendors/{vendor}/customers',                 [\App\Http\Controllers\Api\Vendor\VendorController::class, 'customers']);
    Route::post('/vendors/{vendor}/customers',                [\App\Http\Controllers\Api\Vendor\VendorController::class, 'storeCustomer']);
    // Employees (enhancement #2/#9/#10) — the vendor's assignable people. index()
    // feeds the assignee cascade; grant-access provisions a login so an employee
    // can be assigned work and see it. Static segments stay ahead of wildcards.
    Route::get('/vendors/{vendor}/employees',                 [\App\Http\Controllers\Api\Vendor\VendorEmployeeController::class, 'index']);
    Route::post('/vendors/{vendor}/employees',                [\App\Http\Controllers\Api\Vendor\VendorEmployeeController::class, 'store']);
    Route::post('/vendors/{vendor}/employees/{contact}/grant-access', [\App\Http\Controllers\Api\Vendor\VendorEmployeeController::class, 'grantAccess']);
    // Follow-ups on the shared polymorphic `reminders` table. Mounted here rather
    // than under /sales/reminders, which has no role gate — see the controller.
    Route::get('/vendors/{vendor}/reminders',                 [\App\Http\Controllers\Api\Vendor\VendorController::class, 'reminders']);
    Route::post('/vendors/{vendor}/reminders',                [\App\Http\Controllers\Api\Vendor\VendorController::class, 'storeReminder']);
    Route::post('/vendors/{vendor}/reminders/{reminder}/complete', [\App\Http\Controllers\Api\Vendor\VendorController::class, 'completeReminder']);
    Route::delete('/vendors/{vendor}/reminders/{reminder}',   [\App\Http\Controllers\Api\Vendor\VendorController::class, 'destroyReminder']);
    // Commercial: the optional link to this company's Purchase record, plus the
    // two views Purchase has no vendor-level endpoint for. Orders/invoices/debit
    // notes/contracts/quotations are read straight off /purchase/* by the client.
    Route::get('/vendors/{vendor}/purchase-matches',          [\App\Http\Controllers\Api\Vendor\VendorController::class, 'purchaseMatches']);
    Route::patch('/vendors/{vendor}/purchase-link',           [\App\Http\Controllers\Api\Vendor\VendorController::class, 'linkPurchaseVendor']);
    Route::post('/vendors/{vendor}/purchase-record',          [\App\Http\Controllers\Api\Vendor\VendorController::class, 'createPurchaseRecord']);
    Route::get('/vendors/{vendor}/purchase-payments',         [\App\Http\Controllers\Api\Vendor\VendorController::class, 'purchasePayments']);
    Route::get('/vendors/{vendor}/purchase-statement',        [\App\Http\Controllers\Api\Vendor\VendorController::class, 'purchaseStatement']);
    // Attachments — the folder-tree file area on the shared polymorphic
    // attachment_folders/attachments tables. Static segments before wildcards.
    Route::get('/vendors/{vendor}/attachments',                    [\App\Http\Controllers\Api\Vendor\VendorController::class, 'attachments']);
    Route::post('/vendors/{vendor}/attachments',                   [\App\Http\Controllers\Api\Vendor\VendorController::class, 'storeAttachment']);
    Route::post('/vendors/{vendor}/attachment-folders',            [\App\Http\Controllers\Api\Vendor\VendorController::class, 'storeAttachmentFolder']);
    Route::put('/vendors/{vendor}/attachment-folders/{folder}',    [\App\Http\Controllers\Api\Vendor\VendorController::class, 'updateAttachmentFolder']);
    Route::delete('/vendors/{vendor}/attachment-folders/{folder}', [\App\Http\Controllers\Api\Vendor\VendorController::class, 'destroyAttachmentFolder']);
    Route::get('/vendors/{vendor}/attachments/{attachment}/download', [\App\Http\Controllers\Api\Vendor\VendorController::class, 'downloadAttachment']);
    Route::put('/vendors/{vendor}/attachments/{attachment}',        [\App\Http\Controllers\Api\Vendor\VendorController::class, 'updateAttachment']);
    Route::delete('/vendors/{vendor}/attachments/{attachment}',     [\App\Http\Controllers\Api\Vendor\VendorController::class, 'destroyAttachment']);
    // Notes on the shared polymorphic `notes` table.
    Route::get('/vendors/{vendor}/notes',                     [\App\Http\Controllers\Api\Vendor\VendorController::class, 'notes']);
    Route::post('/vendors/{vendor}/notes',                    [\App\Http\Controllers\Api\Vendor\VendorController::class, 'storeNote']);
    Route::put('/vendors/{vendor}/notes/{note}',              [\App\Http\Controllers\Api\Vendor\VendorController::class, 'updateNote']);
    Route::delete('/vendors/{vendor}/notes/{note}',           [\App\Http\Controllers\Api\Vendor\VendorController::class, 'destroyNote']);
    Route::get('/vendors/{vendor}/contacts',                  [TpvContactController::class, 'index']);
    Route::post('/vendors/{vendor}/contacts',                 [TpvContactController::class, 'store']);
    Route::get('/vendors/{vendor}/contacts/{contact}',        [TpvContactController::class, 'show']);
    Route::put('/vendors/{vendor}/contacts/{contact}',        [TpvContactController::class, 'update']);
    Route::patch('/vendors/{vendor}/contacts/{contact}/status', [TpvContactController::class, 'setStatus']);

    // Statutory-document validation — upload against the required matrix, resubmit
    // on rejection, download. Reviewing (approve/reject) is admin-only, below.
    Route::get('/vendors/{vendor}/documents',         [VendorDocumentController::class, 'checklist']);
    Route::post('/vendors/{vendor}/documents',        [VendorDocumentController::class, 'upload']);
    Route::get('/documents/{document}/download',      [VendorDocumentController::class, 'download']);
    Route::post('/documents/{document}/resubmit',     [VendorDocumentController::class, 'resubmit']);
    Route::delete('/documents/{document}',            [VendorDocumentController::class, 'destroy']);
    // Document versioning (Phase 3) — history, download a version, restore a version.
    Route::get('/documents/{document}/versions',                        [VendorDocumentController::class, 'versions']);
    Route::get('/documents/{document}/versions/{version}/download',     [VendorDocumentController::class, 'downloadVersion']);
    Route::post('/documents/{document}/versions/{version}/restore',     [VendorDocumentController::class, 'restoreVersion']);

    // Workforce — 5-step registration (profile → medical → induction → PPE → badge).
    // PPE — read straight from Inventory; issue/return write through it.
    Route::get('/ppe',                                    [\App\Http\Controllers\Api\Tpv\PpeController::class, 'catalogue']);
    Route::get('/ppe/summary',                            [\App\Http\Controllers\Api\Tpv\PpeController::class, 'summary']);
    // PPE requirement matrix — role → required PPE. Admin-configurable.
    Route::get('/ppe/requirements',                       [\App\Http\Controllers\Api\Tpv\PpeRequirementController::class, 'index']);
    Route::post('/ppe/requirements',                      [\App\Http\Controllers\Api\Tpv\PpeRequirementController::class, 'store']);
    Route::put('/ppe/requirements/{requirement}',         [\App\Http\Controllers\Api\Tpv\PpeRequirementController::class, 'update']);
    Route::delete('/ppe/requirements/{requirement}',      [\App\Http\Controllers\Api\Tpv\PpeRequirementController::class, 'destroy']);
    Route::get('/ppe/compliance',                         [\App\Http\Controllers\Api\Tpv\PpeRequirementController::class, 'summary']);
    Route::get('/ppe/compliance/workers/{worker}',        [\App\Http\Controllers\Api\Tpv\PpeRequirementController::class, 'worker']);
    Route::get('/ppe/item/{product}/holders',             [\App\Http\Controllers\Api\Tpv\PpeController::class, 'holders']);
    Route::get('/ppe/item/{product}/image',               [\App\Http\Controllers\Api\Tpv\PpeController::class, 'image']);
    Route::get('/ppe/workers/{worker}',                     [\App\Http\Controllers\Api\Tpv\PpeController::class, 'worker']);
    Route::post('/ppe/workers/{worker}/issue',              [\App\Http\Controllers\Api\Tpv\PpeController::class, 'issue']);
    Route::post('/ppe/issues/{issue}/return',             [\App\Http\Controllers\Api\Tpv\PpeController::class, 'returnIssue']);
    Route::get('/workers/stats',                          [TpvWorkerController::class, 'stats']);
    Route::get('/workers',                                [TpvWorkerController::class, 'index']);
    Route::post('/workers',                               [TpvWorkerController::class, 'store']);
    Route::post('/workers/upload',                        [TpvWorkerController::class, 'uploadWorkers']);
    Route::get('/workers/{worker}',                       [TpvWorkerController::class, 'show']);
    Route::get('/workers/{worker}/progress',              [TpvWorkerController::class, 'progress']);
    Route::put('/workers/{worker}',                       [TpvWorkerController::class, 'update']);
    Route::patch('/workers/{worker}/toggle-status',       [TpvWorkerController::class, 'toggleStatus']);
    Route::delete('/workers/{worker}',                    [TpvWorkerController::class, 'destroy']); // Draft only
    // Step records
    Route::post('/workers/{worker}/medical',              [TpvWorkerController::class, 'saveMedical']);
    Route::post('/workers/{worker}/mark-medical',         [TpvWorkerController::class, 'markMedical']);
    Route::post('/workers/{worker}/induction',            [TpvWorkerController::class, 'saveInduction']);
    Route::post('/workers/{worker}/mark-induction',       [TpvWorkerController::class, 'markInduction']);
    // Issuing and returning PPE lives on the /ppe routes above, which move Inventory
    // stock. This one only records a deliberate skip of the step.
    Route::post('/workers/{worker}/mark-ppe',             [TpvWorkerController::class, 'markPpe']);
    Route::post('/workers/{worker}/mark-card-status',     [TpvWorkerController::class, 'markCardStatus']);
    Route::post('/workers/{worker}/mark-punch',           [TpvWorkerController::class, 'markPunch']);
    Route::post('/workers/{worker}/decide',               [TpvWorkerController::class, 'decideWorker']);

    // Gate reads — attendance roster, per-worker history, and the gate log.
    Route::get('/gate/stats',                             [TpvGateLogController::class, 'stats']);
    Route::get('/gate-log',                               [TpvGateLogController::class, 'log']);
    Route::get('/attendance',                             [TpvGateLogController::class, 'roster']);
    Route::get('/workers/{worker}/attendance',            [TpvGateLogController::class, 'workerAttendance']);

    // Strike ledger — reading is operational; issuing/voiding is admin, below.
    Route::get('/strikes/stats',                          [TpvSafetyStrikeController::class, 'stats']);
    Route::get('/strikes',                                [TpvSafetyStrikeController::class, 'index']);
    Route::get('/workers/{worker}/strikes',               [TpvSafetyStrikeController::class, 'forWorker']);

    // HSSE governance dashboard (Doc 6) — one command view over incidents,
    // ratings, compliance and workforce.
    Route::get('/governance/dashboard',                   [\App\Http\Controllers\Api\Tpv\GovernanceController::class, 'dashboard']);

    // HSSE incidents → RCA → CAPA (Doc_4 Phase 5). A Serious/Fatal or stop-work
    // incident auto-suspends the vendor; an incident closes only once its root
    // cause is recorded and every CAPA is verified (enforced in the service).
    Route::get('/incidents',                              [\App\Http\Controllers\Api\Tpv\IncidentController::class, 'index']);
    Route::post('/incidents',                             [\App\Http\Controllers\Api\Tpv\IncidentController::class, 'store']);
    Route::get('/incidents/{incident}',                   [\App\Http\Controllers\Api\Tpv\IncidentController::class, 'show']);
    Route::post('/incidents/{incident}/rca',              [\App\Http\Controllers\Api\Tpv\IncidentController::class, 'recordRca']);
    Route::post('/incidents/{incident}/close',            [\App\Http\Controllers\Api\Tpv\IncidentController::class, 'close']);
    Route::post('/incidents/{incident}/capas',            [\App\Http\Controllers\Api\Tpv\IncidentController::class, 'addCapa']);
    Route::patch('/incidents/{incident}/capas/{capa}',    [\App\Http\Controllers\Api\Tpv\IncidentController::class, 'updateCapa']);

    // Permit-to-Work + JSA (Doc_4 Phase 5). Approval requires a JSA and refuses a
    // suspended vendor; a permit expires at its validity window.
    Route::get('/permits',                                [\App\Http\Controllers\Api\Tpv\PermitController::class, 'index']);
    Route::post('/permits',                               [\App\Http\Controllers\Api\Tpv\PermitController::class, 'store']);
    Route::get('/permits/{permit}',                       [\App\Http\Controllers\Api\Tpv\PermitController::class, 'show']);
    Route::post('/permits/{permit}/steps',                [\App\Http\Controllers\Api\Tpv\PermitController::class, 'addStep']);
    Route::post('/permits/{permit}/approve',              [\App\Http\Controllers\Api\Tpv\PermitController::class, 'approve']);
    Route::post('/permits/{permit}/reject',               [\App\Http\Controllers\Api\Tpv\PermitController::class, 'reject']);
    Route::post('/permits/{permit}/activate',             [\App\Http\Controllers\Api\Tpv\PermitController::class, 'activate']);
    Route::post('/permits/{permit}/close',                [\App\Http\Controllers\Api\Tpv\PermitController::class, 'close']);

    // Proactive safety engagement (Doc_4 Phase 5/6) — observations + toolbox talks.
    Route::get('/observations',                           [\App\Http\Controllers\Api\Tpv\SafetyEngagementController::class, 'observations']);
    Route::post('/observations',                          [\App\Http\Controllers\Api\Tpv\SafetyEngagementController::class, 'storeObservation']);
    Route::post('/observations/{observation}/close',      [\App\Http\Controllers\Api\Tpv\SafetyEngagementController::class, 'closeObservation']);
    Route::get('/toolbox-talks',                          [\App\Http\Controllers\Api\Tpv\SafetyEngagementController::class, 'talks']);
    Route::post('/toolbox-talks',                         [\App\Http\Controllers\Api\Tpv\SafetyEngagementController::class, 'storeTalk']);
});

// Admin approval — activates the vendor for site access.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('tpv')->group(function () {

    Route::post('/onboarding/{onboarding}/approve',   [TpvOnboardingController::class, 'approve']);
    Route::post('/onboarding/{onboarding}/reject',    [TpvOnboardingController::class, 'reject']);
    Route::post('/onboarding/{onboarding}/hold',      [TpvOnboardingController::class, 'hold']);
    Route::post('/onboarding/{onboarding}/release',   [TpvOnboardingController::class, 'release']);
    Route::post('/onboarding/{onboarding}/resubmit',  [TpvOnboardingController::class, 'requestResubmit']);

    // Advanced approval — delegation management and manual escalation (admin).
    Route::get('/approval-delegations',                [OnboardingApprovalController::class, 'delegationsIndex']);
    Route::post('/approval-delegations',               [OnboardingApprovalController::class, 'delegationsStore']);
    Route::post('/approvals/escalate',                 [OnboardingApprovalController::class, 'escalate']);

    // Temporary TPV access — force-expire, convert and status are admin-authority.
    Route::post('/vendors/{vendor}/access/expire',    [TpvAccessController::class, 'expire']);
    Route::post('/vendors/{vendor}/access/convert',   [TpvAccessController::class, 'convert']);
    Route::get('/vendors/{vendor}/access/status',     [TpvAccessController::class, 'status']);

    // Approving/rejecting a statutory document is an admin gate.
    Route::post('/documents/{document}/review',       [VendorDocumentController::class, 'review']);

    // Granting or revoking site access is admin authority.
    Route::post('/workers/{worker}/activate',         [TpvWorkerController::class, 'activate']);
    // Revealing the badge QR for (re)printing — audited on every disclosure.
    Route::get('/workers/{worker}/badge',             [TpvWorkerController::class, 'badge']);
    Route::post('/workers/{worker}/suspend',          [TpvWorkerController::class, 'suspend']);
    Route::post('/workers/{worker}/reinstate',        [TpvWorkerController::class, 'reinstate']);
    Route::post('/workers/{worker}/terminate',        [TpvWorkerController::class, 'terminate']);

    // Safety strikes are admin authority: the third strike (or one Critical)
    // terminates site access, so issuing one can revoke access. Letting staff
    // issue them would route around the admin-only terminate gate.
    Route::post('/workers/{worker}/strikes',          [TpvSafetyStrikeController::class, 'store']);
    Route::post('/strikes/{strike}/void',             [TpvSafetyStrikeController::class, 'void']);
});

// ── Temporary TPV — the vendor's own countdown ──────────────────────────
// A third_party_vendor login reads its own server-authoritative countdown. Not
// behind temp.access: the countdown must still resolve when the window has run
// out (so the UI can show the expired state), the middleware guards work APIs.
Route::middleware(['auth:sanctum', 'role:third_party_vendor'])->prefix('tpv')->group(function () {
    Route::get('/access/countdown', [TpvAccessController::class, 'countdown']);
});
