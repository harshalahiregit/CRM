<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Vendor Contacts — richer contact detail. Adds the profile/address
 * fields the vendor-scoped Contacts tab captures onto the Purchase-owned
 * purchase_contacts table. Purely additive: every column is hasColumn-guarded and
 * nullable, no existing column is touched, and nothing references the shared
 * vendors table or vendor_id — this stays keyed to purchase_vendor_id only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_contacts', 'phone')) {
                $table->string('phone', 30)->nullable()->after('email');
            }
            if (! Schema::hasColumn('purchase_contacts', 'address')) {
                $table->string('address', 255)->nullable()->after('alternate_mobile');
            }
            if (! Schema::hasColumn('purchase_contacts', 'city')) {
                $table->string('city', 120)->nullable()->after('address');
            }
            if (! Schema::hasColumn('purchase_contacts', 'state')) {
                $table->string('state', 120)->nullable()->after('city');
            }
            if (! Schema::hasColumn('purchase_contacts', 'country')) {
                $table->string('country', 120)->nullable()->after('state');
            }
            if (! Schema::hasColumn('purchase_contacts', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('country');
            }
            if (! Schema::hasColumn('purchase_contacts', 'notes')) {
                $table->text('notes')->nullable()->after('pincode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_contacts', function (Blueprint $table) {
            foreach (['phone', 'address', 'city', 'state', 'country', 'pincode', 'notes'] as $col) {
                if (Schema::hasColumn('purchase_contacts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
