<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV contacts get the same columns Purchase contacts already have.
 *
 * The two vendor workspaces are meant to capture the same contact. Purchase's
 * table gained these seven in 2026_08_30_000016 and TPV's never did, so the two
 * screens drifted: Purchase asks for sixteen fields and stores all sixteen,
 * while TPV asked for twenty-six and stored nine — the address, the landline
 * and the notes among the seventeen that went nowhere.
 *
 * A deliberate mirror of that migration, column for column and type for type,
 * so the two tables answer the same question the same way. Purely additive:
 * every column is hasColumn-guarded and nullable, and no existing column is
 * touched.
 */
return new class extends Migration
{
    private const COLUMNS = ['phone', 'address', 'city', 'state', 'country', 'pincode', 'notes'];

    public function up(): void
    {
        Schema::table('tpv_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('tpv_contacts', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }
            if (! Schema::hasColumn('tpv_contacts', 'address')) {
                $table->string('address', 255)->nullable()->after('alternate_mobile');
            }
            if (! Schema::hasColumn('tpv_contacts', 'city')) {
                $table->string('city', 120)->nullable()->after('address');
            }
            if (! Schema::hasColumn('tpv_contacts', 'state')) {
                $table->string('state', 120)->nullable()->after('city');
            }
            if (! Schema::hasColumn('tpv_contacts', 'country')) {
                $table->string('country', 120)->nullable()->after('state');
            }
            if (! Schema::hasColumn('tpv_contacts', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('country');
            }
            if (! Schema::hasColumn('tpv_contacts', 'notes')) {
                $table->text('notes')->nullable()->after('pincode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tpv_contacts', function (Blueprint $table) {
            foreach (self::COLUMNS as $col) {
                if (Schema::hasColumn('tpv_contacts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
