<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen projects.link_type so 'purchase_vendor' fits.
 *
 * The column was declared string(12) in 2026_07_28_000002 when the only values
 * were 'customer' (8) and 'vendor' (6); 'tpv_vendor' (10) later still fitted.
 * 'purchase_vendor' is 15 characters and does NOT.
 *
 * This has never bitten because SQLite — the development database — ignores
 * VARCHAR length entirely. On MySQL in non-strict mode the value truncates
 * silently to 'purchase_ven', which matches no filter, so the Project, Expenses
 * and Ticket tabs would simply read as empty rather than as broken. On strict
 * MySQL or Postgres the insert throws.
 *
 * 32 leaves room for the next party type without revisiting this.
 *
 * Widening only: no existing value changes, and nothing that reads the column
 * needs to know. doctrine/dbal is not required for a string length change on
 * Laravel 11+.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('link_type', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing back would truncate any 'purchase_vendor' row written since,
        // so the down path clears those first rather than corrupting them.
        \Illuminate\Support\Facades\DB::table('projects')
            ->where('link_type', 'purchase_vendor')
            ->update(['link_type' => null, 'vendor_id' => null]);

        Schema::table('projects', function (Blueprint $table) {
            $table->string('link_type', 12)->nullable()->change();
        });
    }
};
