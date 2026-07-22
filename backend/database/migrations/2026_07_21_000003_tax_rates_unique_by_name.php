<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax rates are uniquely identified by NAME, not by percentage.
 *
 * The original unique(tenant_id, rate) made the standard Indian GST setup
 * impossible: CGST and SGST are both 9% and must coexist. Uniqueness moves to
 * (tenant_id, name) so any number of differently-named taxes can share a rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'rate']);
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'rate']);
        });
    }
};
