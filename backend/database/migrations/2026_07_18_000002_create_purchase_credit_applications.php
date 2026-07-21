<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit netting: a debit note's open balance (money the vendor owes us) applied
 * against a payable invoice's balance (money we owe them), settling both sides
 * without cash moving.
 *
 * One row per application — the ledger of netting events. Reversible by deleting
 * the row (mirrors how payments/refunds reverse), which restores both balances.
 *
 * Denormalised running totals live on each side (invoices.amount_credited,
 * debit_notes.amount_applied) so a list can show "₹X credited" without summing
 * this table per row; the sums here remain the source of truth on recalc.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_credit_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_debit_note_id')->index();
            $table->unsignedBigInteger('purchase_invoice_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->decimal('amount', 15, 2);
            $table->date('applied_date')->nullable();
            $table->string('reference')->nullable();
            $table->string('notes')->nullable();

            $table->timestamps();
            $table->index(['tenant_id', 'purchase_debit_note_id']);
            $table->index(['tenant_id', 'purchase_invoice_id']);
        });

        // Non-cash credit settled against the invoice — sits alongside amount_paid.
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('amount_credited', 15, 2)->default(0)->after('amount_paid');
        });

        // How much of the debit-note claim has been netted against invoices —
        // alongside amount_refunded (cash back).
        Schema::table('purchase_debit_notes', function (Blueprint $table) {
            $table->decimal('amount_applied', 15, 2)->default(0)->after('amount_refunded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_credit_applications');
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('amount_credited');
        });
        Schema::table('purchase_debit_notes', function (Blueprint $table) {
            $table->dropColumn('amount_applied');
        });
    }
};
