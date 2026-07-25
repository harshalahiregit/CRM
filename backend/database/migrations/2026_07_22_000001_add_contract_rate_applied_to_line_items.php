<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a procurement line whose rate was pulled from an Active rate contract
 * (rather than typed or taken from the catalog default). Lets the UI badge the
 * line and the audit trail prove the pre-negotiated rate was honoured.
 *
 * PO and PR only — RFQ/quotation lines are pre-award pricing discovery, and
 * contract lines are the source, not a consumer.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['purchase_order_items', 'purchase_request_items'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->boolean('contract_rate_applied')->default(false)->after('tax');
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_order_items', 'purchase_request_items'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('contract_rate_applied');
            });
        }
    }
};
