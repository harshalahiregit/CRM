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
    /*
     * ORDER MATTERS, and only on MySQL.
     *
     * `tenant_id` carries a foreign key, and unique(tenant_id, rate) is the only
     * index whose leftmost column is tenant_id — so MySQL uses it to satisfy
     * that constraint and refuses to drop it:
     *
     *     SQLSTATE[HY000] 1553: Cannot drop index
     *     'tax_rates_tenant_id_rate_unique': needed in a foreign key constraint
     *
     * Creating the replacement FIRST gives the foreign key another index with
     * tenant_id leading, and the old one then drops cleanly. SQLite enforces
     * none of this, which is why the original order passed locally and failed
     * on the first MySQL deploy.
     */
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'rate']);
        });
    }

    public function down(): void
    {
        // Same reasoning, reversed.
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->unique(['tenant_id', 'rate']);
        });

        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'name']);
        });
    }
};
