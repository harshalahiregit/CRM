<?php

use App\Http\Controllers\Api\Customer\ClientAddressController;
use App\Http\Controllers\Api\Customer\ClientAttachmentController;
use App\Http\Controllers\Api\Customer\ClientContactController;
use App\Http\Controllers\Api\Customer\ClientActivityController;
use App\Http\Controllers\Api\Customer\ClientComplaintController;
use App\Http\Controllers\Api\Customer\ClientContractController;
use App\Http\Controllers\Api\Customer\ClientDomainController;
use App\Http\Controllers\Api\Customer\ClientFeedbackController;
use App\Http\Controllers\Api\Customer\ClientPurchaseOrderController;
use App\Http\Controllers\Api\Customer\CustomerLinkedRecordsController;
use App\Http\Controllers\Api\Customer\CustomerTimelineController;
use App\Http\Controllers\Api\Customer\ClientController;
use App\Http\Controllers\Api\Customer\ExpenseCategoryController;
use App\Http\Controllers\Api\Customer\ClientExpenseController;
use App\Http\Controllers\Api\Customer\ClientGroupController;
use App\Http\Controllers\Api\Customer\ClientGroupReportController;
use App\Http\Controllers\Api\Customer\ClientNoteController;
use App\Http\Controllers\Api\Customer\ClientPackageController;
use App\Http\Controllers\Api\Customer\ClientPreAlertController;
use App\Http\Controllers\Api\Customer\ClientRecipientController;
use App\Http\Controllers\Api\Customer\ClientReminderController;
use App\Http\Controllers\Api\Customer\ClientShipmentController;
use App\Http\Controllers\Api\Customer\ClientSubscriptionController;
use App\Http\Controllers\Api\Customer\ClientVaultController;
use App\Http\Controllers\Api\Customer\CustomFieldController;
use Illuminate\Support\Facades\Route;

// ── Customer / Clients Module (Sanctum) ─────────────────────────────────
// Internal staff surface. Role gating: any staff/admin may manage customers;
// client/vendor roles must not reach these — enforced with role middleware.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('customers')->group(function () {

    // Summary + import/export (static paths BEFORE the {client} wildcard)
    Route::get('/summary',        [ClientController::class, 'summary']);
    Route::post('/import',        [ClientController::class, 'import']);
    Route::get('/import/sample',  [ClientController::class, 'sample']);
    Route::get('/export',         [ClientController::class, 'export']);

    // Groups
    Route::get('/groups',            [ClientGroupController::class, 'index']);
    Route::post('/groups',           [ClientGroupController::class, 'store']);
    Route::put('/groups/{group}',    [ClientGroupController::class, 'update']);
    Route::delete('/groups/{group}', [ClientGroupController::class, 'destroy']);

    // Group-wise reports (static paths — must stay above /{client}).
    Route::get('/group-reports',            [ClientGroupReportController::class, 'show']);
    Route::get('/group-reports/comparison', [ClientGroupReportController::class, 'comparison']);
    Route::get('/group-reports/export',     [ClientGroupReportController::class, 'export']);
    Route::get('/group-reports/pdf',        [ClientGroupReportController::class, 'pdf']);

    // Custom field definitions
    Route::get('/custom-fields',                  [CustomFieldController::class, 'index']);
    Route::post('/custom-fields',                 [CustomFieldController::class, 'store']);
    Route::post('/custom-fields/reorder',         [CustomFieldController::class, 'reorder']);
    Route::put('/custom-fields/{customField}',    [CustomFieldController::class, 'update']);
    Route::delete('/custom-fields/{customField}', [CustomFieldController::class, 'destroy']);

    // Assignable staff (create-stepper needs it before a client exists;
    // must stay ABOVE the /{client} wildcard routes)
    Route::get('/assignable-staff', [ClientController::class, 'assignableStaff']);
    Route::get('/options',          [ClientController::class, 'options']);

    // Customer 360 overview — live counts from the owning modules, nothing stored.
    Route::get('/{client}/overview', [\App\Http\Controllers\Api\Customer\Customer360Controller::class, 'show']);

    // Parent Company picker options (static — must stay above /{client}).
    Route::get('/parent-companies', [ClientController::class, 'parentCompanyOptions']);

    // Clients CRUD
    Route::get('/',            [ClientController::class, 'index']);
    Route::post('/',           [ClientController::class, 'store']);

    // ── Expense categories master + Projects stub ──────────────────────
    // Static paths — MUST stay above the /{client} wildcard routes.
    Route::get('/expense-categories',                        [ExpenseCategoryController::class, 'index']);
    Route::post('/expense-categories',                       [ExpenseCategoryController::class, 'store']);
    Route::put('/expense-categories/{expenseCategory}',      [ExpenseCategoryController::class, 'update']);
    Route::delete('/expense-categories/{expenseCategory}',   [ExpenseCategoryController::class, 'destroy']);
    // Billable → Project picker on client expenses. Backed by the real Projects
    // module via ProjectDirectoryContract (this was an empty stub while that
    // module didn't exist). Pass ?customer_id= to scope to one client's projects.
    Route::get('/projects-stub', function (\Illuminate\Http\Request $request, \App\Contracts\ProjectDirectoryContract $projects) {
        return response()->json($projects->listProjects(
            $request->user()->tenant_id,
            $request->filled('customer_id') ? (int) $request->query('customer_id') : null,
        ));
    });

    // Map picker: address search (static — must stay above /{client})
    Route::get('/geocode', [ClientController::class, 'geocode']);

    Route::get('/{client}',    [ClientController::class, 'show']);
    Route::put('/{client}',    [ClientController::class, 'update']);
    Route::put('/{client}/location', [ClientController::class, 'updateLocation']);
    Route::patch('/{client}/active', [ClientController::class, 'toggleActive']);
    Route::delete('/{client}', [ClientController::class, 'destroy']);

    // Profile read tabs (loop-ins + rollups)
    Route::get('/{client}/tax',          [ClientController::class, 'taxSummary']);
    Route::get('/{client}/tickets',      [ClientController::class, 'tickets']);
    Route::get('/{client}/invoices',     [ClientController::class, 'invoices']);
    Route::get('/{client}/estimates',    [ClientController::class, 'estimates']);
    Route::get('/{client}/proposals',    [ClientController::class, 'proposals']);
    Route::get('/{client}/credit-notes', [ClientController::class, 'creditNotes']);
    Route::get('/{client}/payments',     [ClientController::class, 'payments']);
    Route::get('/{client}/statement',    [ClientController::class, 'statement']);

    // Customer admins (account managers)
    Route::get('/{client}/admins',  [ClientController::class, 'admins']);
    Route::put('/{client}/admins',  [ClientController::class, 'syncAdmins']);

    // Contacts
    Route::get('/{client}/contacts',                       [ClientContactController::class, 'index']);
    Route::post('/{client}/contacts',                      [ClientContactController::class, 'store']);
    Route::put('/{client}/contacts/{contact}',             [ClientContactController::class, 'update']);
    Route::patch('/{client}/contacts/{contact}/active',    [ClientContactController::class, 'toggleActive']);
    // Portal invitation — mints a set-password token and mails it.
    Route::post('/{client}/contacts/{contact}/invite',     [ClientContactController::class, 'invite']);
    Route::delete('/{client}/contacts/{contact}',          [ClientContactController::class, 'destroy']);

    // Notes
    Route::get('/{client}/notes',          [ClientNoteController::class, 'index']);
    Route::post('/{client}/notes',         [ClientNoteController::class, 'store']);
    Route::put('/{client}/notes/{note}',   [ClientNoteController::class, 'update']);
    Route::delete('/{client}/notes/{note}', [ClientNoteController::class, 'destroy']);

    // Reminders
    Route::get('/{client}/reminders',                  [ClientReminderController::class, 'index']);
    Route::post('/{client}/reminders',                 [ClientReminderController::class, 'store']);
    Route::delete('/{client}/reminders/{reminder}',    [ClientReminderController::class, 'destroy']);

    // §5 Timeline — read-only aggregation across every connected module.
    Route::get('/{client}/timeline', [CustomerTimelineController::class, 'index']);
    // §4 Activities as a register of everything attached to this customer.
    Route::get('/{client}/activity-feed', [CustomerTimelineController::class, 'feed']);

    // §6 — other modules' records, shown here and edited there.
    Route::get('/{client}/linked/projects',       [CustomerLinkedRecordsController::class, 'projects']);
    Route::get('/{client}/linked/tasks',          [CustomerLinkedRecordsController::class, 'tasks']);
    Route::get('/{client}/linked/delivery-notes', [CustomerLinkedRecordsController::class, 'deliveryNotes']);
    Route::get('/{client}/linked/meetings',       [CustomerLinkedRecordsController::class, 'meetings']);

    // Vault (credentials)
    Route::get('/{client}/vault',                          [ClientVaultController::class, 'index']);
    Route::post('/{client}/vault',                         [ClientVaultController::class, 'store']);
    Route::put('/{client}/vault/{vaultEntry}',             [ClientVaultController::class, 'update']);
    Route::post('/{client}/vault/{vaultEntry}/reveal',     [ClientVaultController::class, 'reveal']);
    Route::delete('/{client}/vault/{vaultEntry}',          [ClientVaultController::class, 'destroy']);
    // §15 — who revealed which credential, and when. Administrators only.
    Route::get('/{client}/vault/{vaultEntry}/access-log',  [ClientVaultController::class, 'accessLog']);

    // Attachments
    Route::get('/{client}/attachments',                     [ClientAttachmentController::class, 'index']);
    Route::post('/{client}/attachments',                    [ClientAttachmentController::class, 'store']);
    // Private disk — the only way to read an attachment, and it is checked.
    Route::get('/{client}/attachments/{attachment}/download', [ClientAttachmentController::class, 'download']);
    Route::delete('/{client}/attachments/{attachment}',     [ClientAttachmentController::class, 'destroy']);

    // Address book
    Route::get('/{client}/addresses',              [ClientAddressController::class, 'index']);
    Route::post('/{client}/addresses',             [ClientAddressController::class, 'store']);
    Route::put('/{client}/addresses/{address}',    [ClientAddressController::class, 'update']);
    Route::delete('/{client}/addresses/{address}', [ClientAddressController::class, 'destroy']);

    // Recipients
    Route::get('/{client}/recipients',                [ClientRecipientController::class, 'index']);
    Route::post('/{client}/recipients',               [ClientRecipientController::class, 'store']);
    Route::put('/{client}/recipients/{recipient}',    [ClientRecipientController::class, 'update']);
    Route::delete('/{client}/recipients/{recipient}', [ClientRecipientController::class, 'destroy']);

    // Simple per-customer record tabs (contracts / expenses / subscriptions /
    // pre-alerts / packages / shipments) — all share AbstractClientRecordController.
    foreach ([
        'contracts'     => ClientContractController::class,
        'expenses'      => ClientExpenseController::class,
        'subscriptions' => ClientSubscriptionController::class,
        'pre-alerts'    => ClientPreAlertController::class,
        'packages'      => ClientPackageController::class,
        'shipments'     => ClientShipmentController::class,
        // Customer 360 — Activities (§4), Complaints/Escalations (§17 SERVICE),
        // Customer Experience (§10), Domain Manager and customer POs.
        'activities'      => ClientActivityController::class,
        'complaints'      => ClientComplaintController::class,
        'feedback'        => ClientFeedbackController::class,
        'domains'         => ClientDomainController::class,
        'purchase-orders' => ClientPurchaseOrderController::class,
    ] as $slug => $controller) {
        Route::get("/{client}/{$slug}",              [$controller, 'index']);
        Route::post("/{client}/{$slug}",             [$controller, 'store']);
        Route::put("/{client}/{$slug}/{record}",     [$controller, 'update']);
        Route::delete("/{client}/{$slug}/{record}",  [$controller, 'destroy']);
    }
});
