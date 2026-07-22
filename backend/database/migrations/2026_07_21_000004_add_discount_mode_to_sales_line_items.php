<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line discount mode. A line discount may now be a flat amount or a
 * percentage of that line's value; the document-level discount it replaces is
 * no longer used (its columns stay for legacy documents).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_line_items', function (Blueprint $table) {
            $table->string('discount_mode', 10)->default('fixed')->after('discount'); // fixed | percent
        });
    }

    public function down(): void
    {
        Schema::table('sales_line_items', function (Blueprint $table) {
            $table->dropColumn('discount_mode');
        });
    }
};
