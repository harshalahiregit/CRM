<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts module — Phase 1: double-entry ledger core.
 *
 * The single source of truth is the journal (`acc_voucher_lines`). Every report
 * (Trial Balance, P&L, Balance Sheet, ledger statements) is DERIVED at query time
 * from posted lines — nothing computable from the ledger is stored separately.
 *
 * Tally-aligned model: account_groups (classification tree, carries `nature`) →
 * ledgers (postable accounts) → vouchers (transaction headers) → voucher_lines
 * (the debit/credit legs). Per voucher: Σ(debit) − Σ(credit) = 0, enforced in the
 * PostingService in integer paise.
 *
 * Multi-tenancy: tenant = one company (one acc_company_profiles row per tenant).
 * Every table carries tenant_id and is scoped via ->forTenant() (opt-in, not global).
 * Money = decimal(15,2) per TEAM-CONVENTIONS §4.
 *
 * Cross-module note: `acc_ledgers.party_id` / `acc_vouchers.party_id` intentionally
 * have NO foreign key — a "party" is optionally a Customer/Vendor owned elsewhere;
 * in Phase 1 parties are represented as ledgers under Sundry Debtors/Creditors.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Company statutory profile (one per tenant) ──────────────────────
        Schema::create('acc_company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('pan', 10)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('tan', 10)->nullable();
            $table->string('state_code', 2)->nullable();          // GST place-of-supply
            $table->enum('entity_type', ['company', 'llp', 'partnership', 'proprietorship', 'trust'])
                ->default('proprietorship');
            $table->date('books_begin_date')->nullable();
            $table->string('base_currency', 3)->default('INR');
            $table->timestamps();
        });

        // ── Financial years (India FY: Apr 1 – Mar 31) ──────────────────────
        Schema::create('acc_financial_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);   // period lock: blocks postings
            $table->timestamps();

            $table->index(['tenant_id', 'start_date']);
        });

        // ── Chart of accounts: classification tree ──────────────────────────
        Schema::create('acc_account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();       // self-tree; FK added below
            $table->string('name');
            $table->enum('nature', ['asset', 'liability', 'equity', 'income', 'expense']);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_system')->default(false);   // protects the seeded standard groups
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('acc_account_groups')->nullOnDelete();
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'nature']);
        });

        // ── Ledgers: the postable accounts ──────────────────────────────────
        Schema::create('acc_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('acc_account_groups')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();                  // optional own numbering
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->enum('opening_balance_type', ['dr', 'cr'])->default('dr');
            $table->boolean('is_bank')->default(false);
            $table->boolean('is_cash')->default(false);
            $table->boolean('is_party')->default(false);             // control account for a customer/vendor
            $table->unsignedBigInteger('party_id')->nullable();      // no FK — party owned by another module
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'group_id']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'name']);
        });

        // ── Numbering series (gap-less, per type per FY) ─────────────────────
        // Created before voucher_types so the FK can be declared inline (SQLite
        // cannot add a foreign key via a later ALTER TABLE).
        Schema::create('acc_numbering_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('voucher_type_code', 20);
            $table->string('prefix', 20)->default('');
            $table->string('suffix', 20)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->enum('reset_frequency', ['never', 'yearly'])->default('yearly');
            $table->unsignedSmallInteger('width')->default(4);   // zero-pad width
            $table->timestamps();

            $table->unique(['tenant_id', 'voucher_type_code']);
        });

        // ── Voucher types (templates) ───────────────────────────────────────
        Schema::create('acc_voucher_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('code', [
                'sales', 'purchase', 'payment', 'receipt', 'contra',
                'journal', 'debit_note', 'credit_note', 'stock_journal',
            ]);
            $table->string('name');
            $table->unsignedBigInteger('numbering_series_id')->nullable();
            $table->boolean('affects_stock')->default(false);
            $table->boolean('affects_gst')->default(false);
            $table->timestamps();

            $table->foreign('numbering_series_id')->references('id')->on('acc_numbering_series')->nullOnDelete();
            $table->unique(['tenant_id', 'code']);
        });

        // ── Vouchers (transaction headers) ──────────────────────────────────
        Schema::create('acc_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('voucher_type_id')->constrained('acc_voucher_types')->cascadeOnDelete();
            $table->foreignId('financial_year_id')->constrained('acc_financial_years')->cascadeOnDelete();
            $table->string('number', 60);
            $table->date('date');
            $table->text('narration')->nullable();
            $table->unsignedBigInteger('party_id')->nullable();   // no FK — party owned elsewhere
            $table->string('reference_no', 100)->nullable();
            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('posted');
            $table->decimal('total_amount', 15, 2)->default(0);   // Σ debits (== Σ credits)
            $table->boolean('is_reversal')->default(false);
            $table->unsignedBigInteger('reversed_voucher_id')->nullable(); // self-FK added below
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('reversed_voucher_id')->references('id')->on('acc_vouchers')->nullOnDelete();
            $table->unique(['tenant_id', 'voucher_type_id', 'financial_year_id', 'number'], 'acc_vouchers_number_unique');
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'status']);
        });

        // ── Voucher lines: THE journal / ledger of record ───────────────────
        Schema::create('acc_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained('acc_vouchers')->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('acc_ledgers')->cascadeOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('line_narration')->nullable();
            $table->timestamps();

            // Heavy indexes: ledger statements, trial balance, per-voucher fetch.
            $table->index(['tenant_id', 'ledger_id', 'voucher_id'], 'acc_lines_ledger_idx');
            $table->index(['tenant_id', 'voucher_id']);
        });

        // ── Audit trail: append-only (statutory) ────────────────────────────
        // No softDeletes, no updated_at. Immutability enforced at the app layer
        // (no update/delete routes); DB-trigger/privilege hardening is a follow-up.
        Schema::create('acc_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->enum('action', ['create', 'update', 'cancel', 'reverse']);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('ip', 45)->nullable();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_audit_logs');
        Schema::dropIfExists('acc_voucher_lines');
        Schema::dropIfExists('acc_vouchers');
        Schema::dropIfExists('acc_voucher_types');
        Schema::dropIfExists('acc_numbering_series');
        Schema::dropIfExists('acc_ledgers');
        Schema::dropIfExists('acc_account_groups');
        Schema::dropIfExists('acc_financial_years');
        Schema::dropIfExists('acc_company_profiles');
    }
};
