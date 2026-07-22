<?php

use App\Http\Controllers\Api\Accounts\AccountGroupController;
use App\Http\Controllers\Api\Accounts\BankAccountController;
use App\Http\Controllers\Api\Accounts\BudgetController;
use App\Http\Controllers\Api\Accounts\ChequeController;
use App\Http\Controllers\Api\Accounts\LedgerController;
use App\Http\Controllers\Api\Accounts\ReconciliationController;
use App\Http\Controllers\Api\Accounts\ReportController;
use App\Http\Controllers\Api\Accounts\SettingsController;
use App\Http\Controllers\Api\Accounts\SetupController;
use App\Http\Controllers\Api\Accounts\StatementImportController;
use App\Http\Controllers\Api\Accounts\TaxInvoiceController;
use App\Http\Controllers\Api\Accounts\TaxMasterController;
use App\Http\Controllers\Api\Accounts\VoucherController;
use Illuminate\Support\Facades\Route;

// ── Accounts Module (Sanctum) ───────────────────────────────────────────
// Double-entry ledger core. Internal-only: admin/staff. Client/vendor roles
// get 403. Tenant isolation is enforced again in every service (assertTenant).
// Static paths are declared BEFORE any {wildcard} route.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('accounts')->group(function () {

    // First-run setup + status
    Route::get('/setup',  [SetupController::class, 'status']);
    Route::post('/setup', [SetupController::class, 'store']);

    // Reports (all derived from the ledger)
    Route::get('/reports/trial-balance',    [ReportController::class, 'trialBalance']);
    Route::get('/reports/profit-loss',      [ReportController::class, 'profitLoss']);
    Route::get('/reports/balance-sheet',    [ReportController::class, 'balanceSheet']);
    Route::get('/reports/ledger-statement', [ReportController::class, 'ledgerStatement']);
    Route::get('/reports/general-ledger',   [ReportController::class, 'generalLedger']);
    Route::get('/reports/day-book',         [ReportController::class, 'dayBook']);
    Route::get('/reports/cash-flow',        [ReportController::class, 'cashFlow']);
    Route::get('/reports/ageing',           [ReportController::class, 'ageing']);
    Route::get('/reports/gstr-1',           [ReportController::class, 'gstr1']);
    Route::get('/reports/gstr-3b',          [ReportController::class, 'gstr3b']);
    Route::get('/reports/tds',              [ReportController::class, 'tds']);

    // Settings — company profile
    Route::get('/company-profile',  [SettingsController::class, 'companyProfile']);
    Route::post('/company-profile', [SettingsController::class, 'saveCompanyProfile']);

    // Settings — financial years
    Route::get('/financial-years',  [SettingsController::class, 'financialYears']);
    Route::post('/financial-years', [SettingsController::class, 'storeFinancialYear']);
    Route::patch('/financial-years/{financialYear}/lock', [SettingsController::class, 'toggleFinancialYear']);

    // Settings — voucher types & numbering
    Route::get('/voucher-types',              [SettingsController::class, 'voucherTypes']);
    Route::get('/numbering-series',           [SettingsController::class, 'numberingSeries']);
    Route::put('/numbering-series/{series}',  [SettingsController::class, 'updateNumberingSeries']);

    // Audit trail (read-only — no update/delete by design)
    Route::get('/audit-logs', [SettingsController::class, 'auditLogs']);

    // Chart of accounts — groups
    Route::get('/account-groups',            [AccountGroupController::class, 'index']);
    Route::post('/account-groups',           [AccountGroupController::class, 'store']);
    Route::put('/account-groups/{group}',    [AccountGroupController::class, 'update']);
    Route::delete('/account-groups/{group}', [AccountGroupController::class, 'destroy']);

    // Chart of accounts — ledgers
    Route::get('/ledgers/options',    [LedgerController::class, 'options']);   // before {ledger}
    Route::get('/ledgers',            [LedgerController::class, 'index']);
    Route::post('/ledgers',           [LedgerController::class, 'store']);
    Route::put('/ledgers/{ledger}',   [LedgerController::class, 'update']);
    Route::delete('/ledgers/{ledger}', [LedgerController::class, 'destroy']);

    // Vouchers (the transaction engine)
    Route::get('/vouchers',                    [VoucherController::class, 'index']);
    Route::post('/vouchers',                   [VoucherController::class, 'store']);
    Route::get('/vouchers/{voucher}',          [VoucherController::class, 'show']);
    Route::post('/vouchers/{voucher}/cancel',  [VoucherController::class, 'cancel']);

    /* ── Phase 2: Banking ──────────────────────────────────────── */

    // Bank accounts
    Route::get('/bank-accounts',                 [BankAccountController::class, 'index']);
    Route::post('/bank-accounts',                [BankAccountController::class, 'store']);
    Route::put('/bank-accounts/{bankAccount}',   [BankAccountController::class, 'update']);
    Route::delete('/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy']);

    // Statement import (per bank account)
    Route::get('/bank-accounts/{bankAccount}/imports',         [StatementImportController::class, 'index']);
    Route::post('/bank-accounts/{bankAccount}/imports',        [StatementImportController::class, 'store']);
    Route::get('/bank-accounts/{bankAccount}/statement-lines', [StatementImportController::class, 'lines']);
    Route::delete('/statement-imports/{import}',               [StatementImportController::class, 'destroy']);

    // Reconciliation
    Route::get('/bank-accounts/{bankAccount}/reconciliations', [ReconciliationController::class, 'index']);
    Route::post('/reconciliations/ignore-line',               [ReconciliationController::class, 'ignoreLine']); // static before wildcard
    Route::post('/reconciliations',                           [ReconciliationController::class, 'store']);
    Route::get('/reconciliations/{reconciliation}',           [ReconciliationController::class, 'show']);
    Route::post('/reconciliations/{reconciliation}/toggle',   [ReconciliationController::class, 'toggle']);
    Route::post('/reconciliations/{reconciliation}/auto-suggest', [ReconciliationController::class, 'autoSuggest']);
    Route::post('/reconciliations/{reconciliation}/complete', [ReconciliationController::class, 'complete']);
    Route::delete('/reconciliations/{reconciliation}',        [ReconciliationController::class, 'destroy']);

    /* ── Phase 3: Tax masters & GST invoicing ──────────────────── */

    // Tax masters
    Route::get('/tax/gst-rates',              [TaxMasterController::class, 'gstRates']);
    Route::post('/tax/gst-rates',             [TaxMasterController::class, 'storeGstRate']);
    Route::put('/tax/gst-rates/{gstRate}',    [TaxMasterController::class, 'updateGstRate']);
    Route::delete('/tax/gst-rates/{gstRate}', [TaxMasterController::class, 'destroyGstRate']);

    Route::get('/tax/hsn-sac',              [TaxMasterController::class, 'hsnSac']);
    Route::post('/tax/hsn-sac',             [TaxMasterController::class, 'storeHsnSac']);
    Route::put('/tax/hsn-sac/{hsnSac}',     [TaxMasterController::class, 'updateHsnSac']);
    Route::delete('/tax/hsn-sac/{hsnSac}',  [TaxMasterController::class, 'destroyHsnSac']);

    Route::get('/tax/tds-sections',                 [TaxMasterController::class, 'tdsSections']);
    Route::post('/tax/tds-sections',                [TaxMasterController::class, 'storeTdsSection']);
    Route::put('/tax/tds-sections/{tdsSection}',    [TaxMasterController::class, 'updateTdsSection']);
    Route::delete('/tax/tds-sections/{tdsSection}', [TaxMasterController::class, 'destroyTdsSection']);

    // Book a GST tax invoice / purchase bill
    Route::post('/tax-invoices', [TaxInvoiceController::class, 'store']);

    /* ── Phase 4: Budgets ──────────────────────────────────────── */
    Route::get('/budgets',                          [BudgetController::class, 'index']);
    Route::post('/budgets',                         [BudgetController::class, 'store']);
    Route::get('/budgets/{budget}',                 [BudgetController::class, 'show']);
    Route::put('/budgets/{budget}/lines',           [BudgetController::class, 'saveLines']);
    Route::get('/budgets/{budget}/vs-actual',       [BudgetController::class, 'budgetVsActual']);
    Route::delete('/budgets/{budget}',              [BudgetController::class, 'destroy']);

    // Cheques (static paths before {cheque})
    Route::get('/cheques/summary',            [ChequeController::class, 'summary']);
    Route::get('/cheques/due',                [ChequeController::class, 'due']);
    Route::get('/cheques',                    [ChequeController::class, 'index']);
    Route::post('/cheques',                   [ChequeController::class, 'store']);
    Route::put('/cheques/{cheque}',           [ChequeController::class, 'update']);
    Route::patch('/cheques/{cheque}/status',  [ChequeController::class, 'changeStatus']);
    Route::delete('/cheques/{cheque}',        [ChequeController::class, 'destroy']);
});
