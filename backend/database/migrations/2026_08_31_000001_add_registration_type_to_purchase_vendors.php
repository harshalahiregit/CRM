<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the registration type a Purchase Vendor was created with, so it never
 * has to be inferred from vendor_type later.
 *
 * Backfill rule (per spec): every existing Purchase Vendor becomes
 * 'standard_vendor', EXCEPT rows already flagged vendor_type='temporary', which
 * are the ones that demonstrably registered as temporary — reading those as
 * Standard would be a visible misstatement on records that already carry the
 * fact. Purchase-owned; touches no TPV table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_vendors', 'registration_type')) {
            Schema::table('purchase_vendors', function (Blueprint $table) {
                $table->string('registration_type', 32)->default('standard_vendor')->after('vendor_type')->index();
            });
        }

        DB::table('purchase_vendors')->where('vendor_type', 'temporary')->update(['registration_type' => 'temporary_vendor']);
        DB::table('purchase_vendors')->whereNull('registration_type')->update(['registration_type' => 'standard_vendor']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_vendors', 'registration_type')) {
            Schema::table('purchase_vendors', function (Blueprint $table) {
                $table->dropColumn('registration_type');
            });
        }
    }
};
