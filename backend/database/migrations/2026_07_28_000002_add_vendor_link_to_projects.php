<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project can be raised for a Customer (existing customer_id) OR for a
 * Vendor / Third-Party Vendor. Vendors & TPVs sign in as portal Users (roles
 * `vendor` / `third_party_vendor`) — that User is who sees the project on the
 * vendor portal dashboard — so the link is stored as `vendor_user_id` (the
 * portal login), and `link_type` records which kind of party it is.
 *
 * No FK (loose-link convention, same as customer_id) so Projects never hard-
 * binds to the users table across the module barrier; existence is checked in
 * ProjectService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_user_id')->nullable()->after('customer_id');
            $table->string('link_type', 12)->nullable()->after('vendor_user_id'); // customer | vendor | tpv
            $table->index('vendor_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['vendor_user_id']);
            $table->dropColumn(['vendor_user_id', 'link_type']);
        });
    }
};
