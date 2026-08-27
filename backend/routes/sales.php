<?php

use App\Http\Controllers\Api\Sales\SalesDashboardController;
use App\Http\Controllers\Api\Sales\ItemController;
use App\Http\Controllers\Api\Sales\ProposalController;
use App\Http\Controllers\Api\Sales\TaxRateController;
use App\Http\Controllers\Api\Sales\EstimateController;
use App\Http\Controllers\Api\Sales\InvoiceController;
use App\Http\Controllers\Api\Sales\CreditNoteController;
use App\Http\Controllers\Api\Sales\DeliveryNoteController;
use App\Http\Controllers\Api\Sales\LeadController;
use App\Http\Controllers\Api\Sales\LeadSettingController;
use App\Http\Controllers\Api\Sales\PaymentLinkController;
use App\Http\Controllers\Api\Sales\RetainerInvoiceController;
use App\Http\Controllers\Api\Sales\HsnSacController;
use App\Http\Controllers\Api\Sales\ProposalTemplateController;
use App\Http\Controllers\Api\Sales\SalesDocumentTemplateController;
use App\Http\Controllers\Api\Sales\SalesExportController;
use App\Http\Controllers\Api\Sales\LeadEngagementController;
use App\Http\Controllers\Api\Sales\AppointmentController;
use App\Http\Controllers\Api\Sales\SalesActivityController;
use App\Http\Controllers\Api\Sales\SalesInsightController;
use App\Http\Controllers\Api\Sales\ReminderController;
use App\Http\Controllers\Api\Sales\ContractController;
use App\Http\Controllers\Api\Sales\WebToLeadFormController;
use App\Http\Controllers\Api\Sales\ForecastController;
use App\Http\Controllers\Api\Sales\CommissionController;
use Illuminate\Support\Facades\Route;

// ── Sales & Revenue Module (Sanctum) ────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('sales')->group(function () {

    // Dashboard
    Route::get('/dashboard', [SalesDashboardController::class, 'index']);

    // Unified activity timeline (polymorphic subject)
    Route::get('/activities', [SalesActivityController::class, 'index']);

    // ── Tasks ───────────────────────────────────────────────────

    // ── Follow-ups / Reminders ──────────────────────────────────
    Route::get('/reminders/upcoming',            [ReminderController::class, 'upcoming']);
    Route::get('/reminders',                     [ReminderController::class, 'index']);
    Route::post('/reminders',                    [ReminderController::class, 'store']);
    Route::put('/reminders/{reminder}',          [ReminderController::class, 'update']);
    Route::delete('/reminders/{reminder}',       [ReminderController::class, 'destroy']);
    Route::patch('/reminders/{reminder}/complete', [ReminderController::class, 'complete']);

    // ── Contracts ───────────────────────────────────────────────
    Route::get('/contract-types',                   [ContractController::class, 'types']);
    Route::post('/contract-types',                  [ContractController::class, 'storeType']);
    Route::delete('/contract-types/{contractType}', [ContractController::class, 'destroyType']);

    Route::get('/contracts/expiring',               [ContractController::class, 'expiring']);
    Route::get('/contracts',                        [ContractController::class, 'index']);
    Route::post('/contracts',                       [ContractController::class, 'store']);
    Route::get('/contracts/{contract}',             [ContractController::class, 'show']);
    Route::put('/contracts/{contract}',             [ContractController::class, 'update']);
    Route::delete('/contracts/{contract}',          [ContractController::class, 'destroy']);
    Route::patch('/contracts/{contract}/status',    [ContractController::class, 'updateStatus']);
    Route::post('/contracts/{contract}/renew',      [ContractController::class, 'renew']);
    Route::post('/contracts/{contract}/sign',       [ContractController::class, 'sign']);
    Route::get('/contracts/{contract}/renewals',    [ContractController::class, 'renewals']);
    Route::get('/contracts/{contract}/pdf',         [ContractController::class, 'exportPDF']);
    Route::post('/contracts/{contract}/send',       [ContractController::class, 'send']);
    Route::post('/contracts/{contract}/comments',   [ContractController::class, 'addComment']);
    Route::delete('/contracts/{contract}/comments/{comment}', [ContractController::class, 'deleteComment']);

    // ── Web-to-Lead forms (admin) ───────────────────────────────
    Route::get('/web-to-lead-forms',                    [WebToLeadFormController::class, 'index']);
    Route::post('/web-to-lead-forms',                   [WebToLeadFormController::class, 'store']);
    Route::get('/web-to-lead-forms/{webToLeadForm}',    [WebToLeadFormController::class, 'show']);
    Route::put('/web-to-lead-forms/{webToLeadForm}',    [WebToLeadFormController::class, 'update']);
    Route::delete('/web-to-lead-forms/{webToLeadForm}', [WebToLeadFormController::class, 'destroy']);

    // ── Forecasting (computed) ──────────────────────────────────
    Route::get('/forecast/revenue',  [ForecastController::class, 'revenue']);
    Route::get('/forecast/pipeline', [ForecastController::class, 'pipeline']);
    Route::get('/forecast/funnel',   [ForecastController::class, 'funnel']);

    // ── Commission ──────────────────────────────────────────────
    Route::get('/commission-rules',                    [CommissionController::class, 'rules']);
    Route::post('/commission-rules',                   [CommissionController::class, 'storeRule']);
    Route::put('/commission-rules/{commissionRule}',   [CommissionController::class, 'updateRule']);
    Route::delete('/commission-rules/{commissionRule}',[CommissionController::class, 'deleteRule']);
    Route::get('/commissions',                         [CommissionController::class, 'entries']);
    Route::get('/commissions/summary',                 [CommissionController::class, 'summary']);
    Route::patch('/commissions/{commission}/approve',  [CommissionController::class, 'approve']);
    Route::patch('/commissions/{commission}/reject',   [CommissionController::class, 'reject']);
    Route::patch('/commissions/{commission}/mark-paid',[CommissionController::class, 'markPaid']);

    // CSV / XLSX export for every Sales list (columns declared in SalesExportService)
    Route::get('/export/{type}', SalesExportController::class);

    // Items catalog
    Route::get('/items',              [ItemController::class, 'index']);
    Route::post('/items',             [ItemController::class, 'store']);
    Route::get('/items/{item}',       [ItemController::class, 'show']);
    Route::put('/items/{item}',       [ItemController::class, 'update']);
    Route::delete('/items/{item}',    [ItemController::class, 'destroy']);

    // Proposals
    Route::get('/proposals',                              [ProposalController::class, 'index']);
    Route::post('/proposals',                             [ProposalController::class, 'store']);
    Route::get('/proposals/{proposal}',                   [ProposalController::class, 'show']);
    Route::put('/proposals/{proposal}',                   [ProposalController::class, 'update']);
    Route::delete('/proposals/{proposal}',                [ProposalController::class, 'destroy']);
    Route::patch('/proposals/{proposal}/send',            [ProposalController::class, 'send']);
    Route::post('/proposals/{proposal}/submit',           [ProposalController::class, 'submit']);
    Route::patch('/proposals/{proposal}/status',          [ProposalController::class, 'updateStatus']);
    Route::post('/proposals/{proposal}/generate-qr',      [ProposalController::class, 'generateQR']);
    Route::post('/proposals/{proposal}/convert-to-estimate', [ProposalController::class, 'convertToEstimate']);
    Route::post('/proposals/{proposal}/convert-to-invoice',  [ProposalController::class, 'convertToInvoice']);
    Route::get('/proposals/{proposal}/pdf',               [ProposalController::class, 'exportPDF']);
    Route::post('/proposals/{proposal}/save-as-template', [ProposalController::class, 'saveAsTemplate']);

    // Proposal Templates
    // GST tax slabs (Settings → Tax Rates; consumed by line-item pickers)
    Route::get('/tax-rates',              [TaxRateController::class, 'index']);
    Route::post('/tax-rates',             [TaxRateController::class, 'store']);
    Route::put('/tax-rates/{taxRate}',    [TaxRateController::class, 'update']);
    Route::delete('/tax-rates/{taxRate}', [TaxRateController::class, 'destroy']);

    Route::get('/proposal-templates',                          [ProposalTemplateController::class, 'index']);
    Route::get('/proposal-templates/categories',               [ProposalTemplateController::class, 'categories']);
    Route::post('/proposal-templates',                         [ProposalTemplateController::class, 'store']);
    Route::put('/proposal-templates/{proposalTemplate}',       [ProposalTemplateController::class, 'update']);
    Route::delete('/proposal-templates/{proposalTemplate}',    [ProposalTemplateController::class, 'destroy']);
    Route::post('/proposal-templates/{proposalTemplate}/clone',[ProposalTemplateController::class, 'clone']);
    Route::post('/proposal-templates/{proposalTemplate}/duplicate', [ProposalTemplateController::class, 'duplicate']);
    // Declared last so it can't shadow /categories above.
    Route::get('/proposal-templates/{proposalTemplate}',        [ProposalTemplateController::class, 'show']);

    // Invoice / estimate / credit-note templates (line items + document defaults)
    Route::get('/document-templates',                          [SalesDocumentTemplateController::class, 'index']);
    Route::post('/document-templates',                         [SalesDocumentTemplateController::class, 'store']);
    Route::post('/document-templates/from-document',           [SalesDocumentTemplateController::class, 'saveFromDocument']);
    Route::put('/document-templates/{salesDocumentTemplate}',  [SalesDocumentTemplateController::class, 'update']);
    Route::delete('/document-templates/{salesDocumentTemplate}', [SalesDocumentTemplateController::class, 'destroy']);
    Route::post('/document-templates/{salesDocumentTemplate}/duplicate', [SalesDocumentTemplateController::class, 'duplicate']);
    // Last, so it can't shadow /from-document above.
    Route::get('/document-templates/{salesDocumentTemplate}',  [SalesDocumentTemplateController::class, 'show']);

    // Estimates
    Route::get('/estimates',                                   [EstimateController::class, 'index']);
    Route::post('/estimates',                                  [EstimateController::class, 'store']);
    Route::get('/estimates/{estimate}',                        [EstimateController::class, 'show']);
    Route::put('/estimates/{estimate}',                        [EstimateController::class, 'update']);
    Route::delete('/estimates/{estimate}',                     [EstimateController::class, 'destroy']);
    Route::patch('/estimates/{estimate}/send',                 [EstimateController::class, 'send']);
    Route::post('/estimates/{estimate}/convert-to-invoice',    [EstimateController::class, 'convertToInvoice']);
    Route::post('/estimates/{estimate}/convert-to-proforma', [EstimateController::class, 'convertToProforma']);
    Route::post('/estimates/{estimate}/convert-to-tasks',    [EstimateController::class, 'convertToTasks']);
    Route::post('/estimates/{estimate}/payments',              [EstimateController::class, 'recordPayment']);

    // Invoices
    Route::get('/invoices',                                    [InvoiceController::class, 'index']);
    Route::post('/invoices',                                   [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}',                          [InvoiceController::class, 'show']);
    Route::put('/invoices/{invoice}',                          [InvoiceController::class, 'update']);
    Route::delete('/invoices/{invoice}',                       [InvoiceController::class, 'destroy']);
    Route::patch('/invoices/{invoice}/send',                   [InvoiceController::class, 'send']);
    Route::post('/invoices/{invoice}/payments',                [InvoiceController::class, 'recordPayment']);
    Route::post('/invoices/{invoice}/public-link',              [InvoiceController::class, 'generatePublicLink']);
    Route::post('/invoices/{invoice}/send-reminder',            [InvoiceController::class, 'sendPaymentReminder']);
    Route::post('/invoices/{invoice}/send-feedback-request',    [InvoiceController::class, 'sendFeedbackRequest']);

    // Payment Links
    Route::get('/payment-links',                               [PaymentLinkController::class, 'index']);
    Route::post('/payment-links',                               [PaymentLinkController::class, 'store']);
    Route::patch('/payment-links/{paymentLink}/mark-paid',      [PaymentLinkController::class, 'markPaid']);
    Route::patch('/payment-links/{paymentLink}/cancel',         [PaymentLinkController::class, 'cancel']);
    Route::delete('/payment-links/{paymentLink}',               [PaymentLinkController::class, 'destroy']);

    // Retainer Invoices
    Route::get('/retainer-invoices',                            [RetainerInvoiceController::class, 'index']);
    Route::post('/retainer-invoices',                           [RetainerInvoiceController::class, 'store']);
    Route::get('/retainer-invoices/{retainerInvoice}',          [RetainerInvoiceController::class, 'show']);
    Route::put('/retainer-invoices/{retainerInvoice}',          [RetainerInvoiceController::class, 'update']);
    Route::delete('/retainer-invoices/{retainerInvoice}',       [RetainerInvoiceController::class, 'destroy']);

    // HSN/SAC lookup
    Route::get('/hsn-sac',                                      [HsnSacController::class, 'search']);

    // Credit Notes
    Route::get('/credit-notes',                                [CreditNoteController::class, 'index']);
    Route::post('/credit-notes',                               [CreditNoteController::class, 'store']);
    Route::get('/credit-notes/{creditNote}',                   [CreditNoteController::class, 'show']);
    Route::delete('/credit-notes/{creditNote}',                [CreditNoteController::class, 'destroy']);
    Route::post('/credit-notes/{creditNote}/apply',            [CreditNoteController::class, 'applyToInvoice']);
    Route::post('/credit-notes/{creditNote}/refund',           [CreditNoteController::class, 'refund']);

    // Delivery Notes
    Route::get('/delivery-notes',                              [DeliveryNoteController::class, 'index']);
    Route::post('/delivery-notes',                             [DeliveryNoteController::class, 'store']);
    Route::get('/delivery-notes/{deliveryNote}',               [DeliveryNoteController::class, 'show']);
    Route::put('/delivery-notes/{deliveryNote}',               [DeliveryNoteController::class, 'update']);
    Route::patch('/delivery-notes/{deliveryNote}/deliver',     [DeliveryNoteController::class, 'markDelivered']);
    Route::delete('/delivery-notes/{deliveryNote}',            [DeliveryNoteController::class, 'destroy']);

    // ── Leads Module ────────────────────────────────────────────

    // Lead Statuses & Sources (settings)
    Route::get('/lead-statuses',                               [LeadSettingController::class, 'statuses']);
    Route::post('/lead-statuses',                              [LeadSettingController::class, 'createStatus']);
    Route::put('/lead-statuses/{status}',                      [LeadSettingController::class, 'updateStatus']);
    Route::delete('/lead-statuses/{status}',                   [LeadSettingController::class, 'deleteStatus']);

    Route::get('/lead-sources',                                [LeadSettingController::class, 'sources']);
    Route::post('/lead-sources',                               [LeadSettingController::class, 'createSource']);
    Route::put('/lead-sources/{source}',                       [LeadSettingController::class, 'updateSource']);
    Route::delete('/lead-sources/{source}',                    [LeadSettingController::class, 'deleteSource']);

    // Lead Goals / Targets
    Route::get('/lead-goals',                                  [LeadSettingController::class, 'goals']);
    Route::post('/lead-goals',                                 [LeadSettingController::class, 'storeGoal']);
    Route::put('/lead-goals/{goal}',                           [LeadSettingController::class, 'updateGoal']);
    Route::delete('/lead-goals/{goal}',                        [LeadSettingController::class, 'deleteGoal']);

    // Questionnaires
    Route::get('/lead-questionnaires',                         [LeadSettingController::class, 'questionnaires']);
    Route::post('/lead-questionnaires',                        [LeadSettingController::class, 'storeQuestionnaire']);
    Route::put('/lead-questionnaires/{questionnaire}',         [LeadSettingController::class, 'updateQuestionnaire']);
    Route::delete('/lead-questionnaires/{questionnaire}',      [LeadSettingController::class, 'deleteQuestionnaire']);

    // Leads CRUD + Actions
    Route::get('/leads/summary',                               [LeadController::class, 'summary']);
    Route::get('/leads/kanban',                                [LeadController::class, 'kanban']);
    Route::post('/leads/bulk',                                 [LeadController::class, 'bulkAction']);
    Route::get('/leads',                                       [LeadController::class, 'index']);
    Route::post('/leads',                                      [LeadController::class, 'store']);
    Route::get('/leads/{lead}',                                [LeadController::class, 'show']);
    Route::put('/leads/{lead}',                                [LeadController::class, 'update']);
    Route::delete('/leads/{lead}',                             [LeadController::class, 'destroy']);
    Route::patch('/leads/{lead}/status',                       [LeadController::class, 'updateStatus']);
    Route::patch('/leads/{lead}/assign',                       [LeadController::class, 'assign']);
    Route::patch('/leads/{lead}/lost',                         [LeadController::class, 'markLost']);
    Route::patch('/leads/{lead}/junk',                         [LeadController::class, 'markJunk']);
    Route::patch('/leads/{lead}/restore',                      [LeadController::class, 'restore']);
    Route::post('/leads/{lead}/convert',                       [LeadController::class, 'convert']);
    Route::post('/leads/{lead}/notes',                         [LeadController::class, 'addNote']);
    Route::post('/leads/{lead}/questionnaire-response',        [LeadController::class, 'submitQuestionnaireResponse']);

    // ── Lead profile: attachments, email activity, custom fields ─
    Route::get('/leads/{lead}/attachments',                    [LeadEngagementController::class, 'attachments']);
    Route::post('/leads/{lead}/attachments',                   [LeadEngagementController::class, 'storeAttachment']);
    Route::delete('/leads/{lead}/attachments/{attachment}',    [LeadEngagementController::class, 'destroyAttachment']);
    Route::get('/leads/{lead}/emails',                         [LeadEngagementController::class, 'emails']);
    Route::post('/leads/{lead}/emails',                        [LeadEngagementController::class, 'sendEmail']);
    Route::get('/leads/{lead}/custom-fields',                  [LeadEngagementController::class, 'customFields']);
    Route::put('/leads/{lead}/custom-fields',                  [LeadEngagementController::class, 'saveCustomFields']);

    // ── Appointments (polymorphic subject; leads today) ─────────
    Route::get('/appointments/upcoming',                       [AppointmentController::class, 'upcoming']);
    Route::get('/appointments',                                [AppointmentController::class, 'index']);
    Route::post('/appointments',                               [AppointmentController::class, 'store']);
    Route::put('/appointments/{appointment}',                  [AppointmentController::class, 'update']);
    Route::patch('/appointments/{appointment}/complete',       [AppointmentController::class, 'complete']);
    Route::delete('/appointments/{appointment}',               [AppointmentController::class, 'destroy']);

    // ── Rule-based Sales Insights (no LLM/ML) ───────────────────
    Route::get('/leads/{lead}/win-probability',                [SalesInsightController::class, 'winProbability']);
    Route::get('/leads/{lead}/next-best-action',               [SalesInsightController::class, 'nextBestAction']);
    Route::get('/insights/task-priorities',                    [SalesInsightController::class, 'taskPriorities']);
});
