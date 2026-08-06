<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a project to a vendor RECORD, not to a portal user.
 *
 * `vendor_user_id` (added in 2026_07_28_000002) stores a users.id and assumes every
 * vendor signs in as a User. That holds for TPV, but not for Purchase: a
 * PurchaseVendor is a separate identity with its own token and no User row — 17 of
 * 21 have user_id NULL — so a Purchase Vendor simply cannot be represented in a
 * users.id column. Reusing it would mean storing a purchase_vendors.id inside a
 * column named vendor_user_id, which is how audit_logs.actor_id ended up holding
 * ids that match no user.
 *
 * `vendor_id` holds the id in the table named by `link_type`
 * ('tpv_vendor' -> vendors, 'purchase_vendor' -> purchase_vendors). No FK, matching
 * the loose-link convention `customer_id` and `vendor_user_id` already use, so
 * Projects never hard-binds across a module barrier.
 *
 * Strictly additive: `vendor_user_id` is untouched and still populated for TPV, so
 * VendorWorkController's portal queries keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (! Schema::hasColumn('projects', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable();
            }
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index(['tenant_id', 'link_type', 'vendor_id'], 'projects_vendor_link_idx');
        });
    }

    /**
     * Index only. The column is left in place: dropping it on SQLite requires a
     * table rebuild, and a half-rolled-back migration would lose vendor links.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('projects_vendor_link_idx');
        });
    }
};
