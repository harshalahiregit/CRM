<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complete the polymorphic-owner change: a Purchase-owned Award/Referral/Shipment
 * sets the purchase column and leaves the shared-Vendor column null. Make the
 * shared-Vendor owner columns nullable so those rows can exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_awards', function (Blueprint $t) {
            $t->unsignedBigInteger('vendor_id')->nullable()->change();
        });
        Schema::table('vendor_shipments', function (Blueprint $t) {
            $t->unsignedBigInteger('vendor_id')->nullable()->change();
        });
        Schema::table('vendor_referrals', function (Blueprint $t) {
            $t->unsignedBigInteger('referred_by_vendor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Left nullable — reverting would fail on any Purchase-owned rows.
    }
};
