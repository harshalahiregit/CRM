<?php

use App\Http\Controllers\Api\Sales\SalesDashboardController;
use App\Http\Controllers\Api\Sales\ItemController;
use App\Http\Controllers\Api\Sales\ProposalController;
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
use Illuminate\Support\Facades\Route;

// ── Sales & Revenue Module (Sanctum) ────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('sales')->group(function () {

    // Dashboard
    Route::get('/dashboard', [SalesDashboardController::class, 'index']);

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
    Route::patch('/proposals/{proposal}/status',          [ProposalController::class, 'updateStatus']);
    Route::post('/proposals/{proposal}/generate-qr',      [ProposalController::class, 'generateQR']);
    Route::get('/proposals/{proposal}/pdf',               [ProposalController::class, 'exportPDF']);

    // Proposal Templates
    Route::get('/proposal-templates',                          [ProposalTemplateController::class, 'index']);
    Route::post('/proposal-templates',                         [ProposalTemplateController::class, 'store']);
    Route::put('/proposal-templates/{proposalTemplate}',       [ProposalTemplateController::class, 'update']);
    Route::delete('/proposal-templates/{proposalTemplate}',    [ProposalTemplateController::class, 'destroy']);
    Route::post('/proposal-templates/{proposalTemplate}/clone',[ProposalTemplateController::class, 'clone']);

    // Estimates
    Route::get('/estimates',                                   [EstimateController::class, 'index']);
    Route::post('/estimates',                                  [EstimateController::class, 'store']);
    Route::get('/estimates/{estimate}',                        [EstimateController::class, 'show']);
    Route::put('/estimates/{estimate}',                        [EstimateController::class, 'update']);
    Route::delete('/estimates/{estimate}',                     [EstimateController::class, 'destroy']);
    Route::patch('/estimates/{estimate}/send',                 [EstimateController::class, 'send']);
    Route::post('/estimates/{estimate}/convert-to-invoice',    [EstimateController::class, 'convertToInvoice']);
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
});
