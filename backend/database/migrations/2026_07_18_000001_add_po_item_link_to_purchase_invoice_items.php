<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an invoice line back to the PO line it bills for, enabling line-level
 * 3-way match (billed vs ordered vs GRN-accepted).
 *
 * Nullable and additive: invoices raised free-hand (not from a PO) simply have
 * no link and match as "Unmatched" — a warning, not a block. Nothing that reads
 * the table today changes.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_order_item_id')->nullable()->after('purchase_invoice_id')->index();
        });

        // The match verdict at the moment of approval, kept on the invoice so the
        // ledger can show why a payable was approved (e.g. under-billed) without
        // re-running the engine over historic receipts that may have since moved.
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('match_verdict')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            $table->dropColumn('purchase_order_item_id');
        });
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('match_verdict');
        });
    }
};
