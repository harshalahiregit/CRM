<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts module — Phase 2: Banking.
 *
 * Bank accounts (each backed by an is_bank ledger from Phase 1), CSV/XLSX
 * statement import, a reconciliation match/clear/complete workflow, and cheque +
 * PDC management. Everything still scopes by tenant_id and money is decimal(15,2).
 *
 * Reconciliation clears book lines against the imported statement; the mechanic
 * mirrors legacy zignls (book-side vs statement-side, complete when difference=0)
 * but on the clean voucher_lines ledger rather than the mirror-pair history table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Bank accounts (each backed by a Phase-1 ledger) ─────────────────
        Schema::create('acc_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('acc_ledgers')->cascadeOnDelete();
            $table->string('account_no', 40)->nullable();
            $table->string('ifsc', 20)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('branch')->nullable();
            $table->enum('account_type', ['savings', 'current', 'od', 'cc', 'other'])->default('current');
            $table->decimal('current_balance', 15, 2)->default(0);  // cached; recomputed from ledger
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'ledger_id']);
        });

        // ── Statement import batches ────────────────────────────────────────
        Schema::create('acc_bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->string('source', 10)->default('csv');   // csv | xlsx
            $table->string('file_name')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->unsignedInteger('lines_count')->default(0);
            $table->enum('status', ['imported', 'reverted'])->default('imported');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'bank_account_id']);
        });

        // ── Imported statement lines ────────────────────────────────────────
        Schema::create('acc_bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('import_id')->constrained('acc_bank_statement_imports')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->date('txn_date');
            $table->date('value_date')->nullable();
            $table->string('description')->nullable();
            $table->string('ref_no', 100)->nullable();
            $table->decimal('debit', 15, 2)->default(0);    // withdrawal (money out of bank)
            $table->decimal('credit', 15, 2)->default(0);   // deposit (money into bank)
            $table->decimal('running_balance', 15, 2)->nullable();
            $table->enum('match_status', ['unmatched', 'suggested', 'matched', 'ignored'])->default('unmatched');
            $table->unsignedBigInteger('matched_voucher_line_id')->nullable(); // no hard FK — soft link
            $table->unsignedBigInteger('reconciliation_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'bank_account_id', 'txn_date']);
            $table->index(['tenant_id', 'match_status']);
        });

        // ── Reconciliations ─────────────────────────────────────────────────
        Schema::create('acc_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('opening_balance', 15, 2)->default(0);    // carried from last completed rec
            $table->decimal('statement_balance', 15, 2)->default(0);  // bank ending balance (entered)
            $table->decimal('book_balance', 15, 2)->default(0);       // cleared book balance at completion
            $table->decimal('difference', 15, 2)->default(0);
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'bank_account_id', 'status']);
        });

        // ── Cleared book lines per reconciliation (avoids altering voucher_lines) ──
        Schema::create('acc_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('reconciliation_id')->constrained('acc_reconciliations')->cascadeOnDelete();
            $table->foreignId('voucher_line_id')->constrained('acc_voucher_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('statement_line_id')->nullable(); // set when matched to a statement line
            $table->timestamps();

            $table->unique(['reconciliation_id', 'voucher_line_id']);
            $table->index(['tenant_id', 'voucher_line_id']);
        });

        // ── Cheques (issued & received, incl. PDC) ──────────────────────────
        Schema::create('acc_cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('acc_bank_accounts')->nullOnDelete();
            $table->unsignedBigInteger('voucher_id')->nullable();  // links the payment/receipt voucher (soft)
            $table->enum('direction', ['issued', 'received']);
            $table->string('cheque_no', 40)->nullable();
            $table->date('cheque_date');
            $table->string('party_name')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->enum('status', ['issued', 'deposited', 'presented', 'cleared', 'bounced', 'cancelled', 'post_dated'])->default('issued');
            $table->boolean('is_pdc')->default(false);
            $table->date('pdc_due_date')->nullable();
            $table->date('cleared_date')->nullable();
            $table->string('memo')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'direction']);
            $table->index(['tenant_id', 'is_pdc', 'pdc_due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_cheques');
        Schema::dropIfExists('acc_reconciliation_lines');
        Schema::dropIfExists('acc_reconciliations');
        Schema::dropIfExists('acc_bank_statement_lines');
        Schema::dropIfExists('acc_bank_statement_imports');
        Schema::dropIfExists('acc_bank_accounts');
    }
};
