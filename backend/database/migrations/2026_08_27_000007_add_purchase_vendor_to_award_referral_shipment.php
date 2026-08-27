<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parity: the Award / Referral / Shipment tables were TPV-only (owner → the
 * shared Vendor). Add a nullable Purchase-vendor owner column to each so a
 * PurchaseVendor can own the same records. Exactly one owner column is set per
 * row (enforced in the services, not a DB constraint — SQLite friendly).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Awards + Shipments own via vendor_id → add the purchase twin.
        foreach (['vendor_awards', 'vendor_shipments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('purchase_vendor_id')->nullable()->index()->after('tenant_id');
            });
        }
        // Referrals own via referred_by_vendor_id → add the purchase twin.
        Schema::table('vendor_referrals', function (Blueprint $t) {
            $t->unsignedBigInteger('referred_by_purchase_vendor_id')->nullable()->index()->after('referred_by_vendor_id');
        });
    }

    public function down(): void
    {
        foreach (['vendor_awards', 'vendor_shipments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('purchase_vendor_id');
            });
        }
        Schema::table('vendor_referrals', function (Blueprint $t) {
            $t->dropColumn('referred_by_purchase_vendor_id');
        });
    }
};
