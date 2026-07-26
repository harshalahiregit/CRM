<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['proposals', 'estimates', 'sales_invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                // intra (CGST+SGST) | inter (IGST) | null = unknown/legacy →
                // UI renders the single legacy Tax row.
                $t->string('supply_type', 10)->nullable();
                // Document-level discount (meeting 3.2). Line discounts stay.
                // NOT backfilled from discount_total — that column is the sum
                // of line discounts and copying it would double-apply.
                $t->string('discount_mode', 10)->default('fixed'); // fixed|percent
                $t->decimal('discount_value', 12, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['supply_type', 'discount_mode', 'discount_value']);
            });
        }
    }
};
