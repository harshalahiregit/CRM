<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the registration type a Third-Party Vendor was created with, so it
 * never has to be inferred from vendor_type / is_temporary later.
 *
 * Backfill rule (per spec): every existing TPV becomes 'long_term_tpv', EXCEPT
 * rows already flagged temporary (vendor_type='temporary' or is_temporary=1),
 * which demonstrably registered as temporary. TPV-owned; touches no Purchase
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vendors', 'registration_type')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('registration_type', 32)->default('long_term_tpv')->after('vendor_type')->index();
            });
        }

        DB::table('vendors')->where('vendor_type', 'temporary')->update(['registration_type' => 'temporary_tpv']);

        if (Schema::hasColumn('vendors', 'is_temporary')) {
            DB::table('vendors')->where('is_temporary', true)->update(['registration_type' => 'temporary_tpv']);
        }

        DB::table('vendors')->whereNull('registration_type')->update(['registration_type' => 'long_term_tpv']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('vendors', 'registration_type')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('registration_type');
            });
        }
    }
};
