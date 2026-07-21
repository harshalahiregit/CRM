<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line discount for the delivery/receiving vouchers (blueprint §3 shows a
 * "Total discount" on the document). Kept on the LINE rather than the header so
 * the header total stays derived — every money figure on a voucher is computed
 * from its lines, never typed in twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_voucher_items', function (Blueprint $table) {
            $table->decimal('discount', 15, 2)->default(0)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_voucher_items', function (Blueprint $table) {
            $table->dropColumn('discount');
        });
    }
};
