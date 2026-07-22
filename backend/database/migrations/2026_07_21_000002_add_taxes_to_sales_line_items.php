<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple named taxes per line (old-CRM `tblitem_tax` model): one line can
 * carry CGST 9% + SGST 9% rather than a single flat 18%, so the document
 * totals can break the tax out by name.
 *
 * The existing `tax` column is kept as the SUM of the selected rates, so all
 * current totals math, reports and PDFs continue to work untouched; `taxes`
 * only adds the breakdown detail. Legacy rows have taxes = null and keep
 * rendering as a single "Tax" row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_line_items', function (Blueprint $table) {
            $table->json('taxes')->nullable()->after('tax');
        });
    }

    public function down(): void
    {
        Schema::table('sales_line_items', function (Blueprint $table) {
            $table->dropColumn('taxes');
        });
    }
};
