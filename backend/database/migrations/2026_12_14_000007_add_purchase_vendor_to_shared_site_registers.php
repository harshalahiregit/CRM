<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the shared site registers name a PURCHASE vendor too.
 *
 * safety_observations, toolbox_talks and site_vehicles are site-wide registers
 * (no tpv_ prefix, scoped by tenant), and Purchase now writes to them. But their
 * `vendor_id` points at the shared `vendors` table — TPV's vendors. Purchase
 * vendors live in `purchase_vendors` with entirely unrelated ids.
 *
 * That left two problems:
 *  1. a Purchase user could file an observation about a vendor's crew and had no
 *     way to say WHICH vendor — the register recorded the event and lost the
 *     accountable party;
 *  2. worse, `vendor_id` is validated only as `nullable|integer` with no exists
 *     rule, so posting a purchase_vendors id would not fail — it would silently
 *     attach the record to whichever TPV vendor happened to hold that number.
 *     A safety observation filed against the wrong company is worse than one
 *     filed against nobody.
 *
 * A second nullable column is the honest fix: the two vendor tables are separate
 * by design, so one id column cannot address both. Additive and nullable, so
 * every existing TPV row is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['safety_observations', 'toolbox_talks', 'site_vehicles'] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'purchase_vendor_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('purchase_vendor_id')->nullable()->after('vendor_id')->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['safety_observations', 'toolbox_talks', 'site_vehicles'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'purchase_vendor_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('purchase_vendor_id');
            });
        }
    }
};
