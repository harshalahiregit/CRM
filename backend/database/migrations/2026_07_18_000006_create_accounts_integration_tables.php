<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts module — module-integration layer (spec v2 §7).
 *
 * account_mappings bind a semantic role (accounts_receivable, sales_income,
 * output_cgst, bank_default, …) to a ledger, so the posting-rule resolver posts
 * the same way across companies with different charts. source_type/source_id on a
 * voucher back-link it to the originating document (invoice/payment/…) for
 * idempotent posting and reversal-on-cancel — without touching the source tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('role_key', 60);   // e.g. sales_income, output_cgst, bank_default
            $table->unsignedBigInteger('ledger_id')->nullable();  // FK added below (nullOnDelete)
            $table->timestamps();

            $table->unique(['tenant_id', 'role_key']);
            $table->foreign('ledger_id')->references('id')->on('acc_ledgers')->nullOnDelete();
        });

        Schema::table('acc_vouchers', function (Blueprint $table) {
            $table->string('source_type', 40)->nullable()->after('reference_no');   // sales_invoice, sales_payment, ...
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['tenant_id', 'source_type', 'source_id'], 'acc_vouchers_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('acc_vouchers', function (Blueprint $table) {
            $table->dropIndex('acc_vouchers_source_idx');
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::dropIfExists('acc_account_mappings');
    }
};
