<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E-3 — the procure-to-pay code now writes purchase_vendor_id and no longer
 * sets the legacy vendor_id, so its NOT NULL constraint (where present) must relax.
 * vendor_id is dropped entirely in Phase E-6. Touches only the eight procure-to-pay
 * Purchase tables.
 */
return new class extends Migration {
    private function tables(): array
    {
        return [
            'purchase_requests', 'purchase_orders', 'purchase_invoices', 'purchase_debit_notes',
            'purchase_rfq_vendors', 'purchase_quotations', 'purchase_contracts', 'goods_receipts',
        ];
    }

    public function up(): void
    {
        foreach ($this->tables() as $t) {
            if (Schema::hasTable($t) && Schema::hasColumn($t, 'vendor_id')) {
                Schema::table($t, function (Blueprint $table) {
                    $table->unsignedBigInteger('vendor_id')->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: rows may legitimately have a null vendor_id.
    }
};
