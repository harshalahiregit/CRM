<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — T-04, built as two different kinds of thing.
 *
 * The task asked for `MAINTENANCE_DUE` and `BREAKDOWN` as vehicle *states*.
 * Only one of them is a state, and putting the other in `status` would repeat a
 * mistake this module has already made once.
 *
 * ── BREAKDOWN IS A STATE ──────────────────────────────────────────────────
 * A truck stopped on the hard shoulder is genuinely not available, and it is
 * not the same as a truck in a workshop bay: one is a planned job with a slot,
 * the other is a recovery. Operations needs to tell them apart — the second
 * means a load is stranded somewhere. It is mutually exclusive with active,
 * in_operation and in_maintenance, which is what makes it a state.
 *
 * ── MAINTENANCE DUE IS NOT ────────────────────────────────────────────────
 * A truck 1,000 km past its service interval is still a roadworthy truck. If
 * "service due" goes in `status`, the vehicle stops being `active` and drops
 * out of allocation entirely — so a missed oil change silently takes a truck
 * off the road.
 *
 * That is the same category error Person 1 caught in the driver licence: a
 * CONDITION written into a STATE field, blocking the wrong thing. So service
 * due is derived from the columns below, exactly like `compliance_status` is
 * derived from the five expiry dates, and it warns rather than blocks.
 *
 * Nothing here has a default. A fleet that has never recorded a service
 * interval gets "unknown", not "overdue" — inventing a schedule nobody set
 * would flag every truck on the day this ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Either, neither or both. Trucks are serviced on distance; trailers
            // and gensets are often serviced on time, and some fleets use both
            // and take whichever comes first.
            $table->unsignedInteger('service_interval_km')->nullable()->after('capacity_tonnes');
            $table->unsignedSmallInteger('service_interval_days')->nullable()->after('service_interval_km');

            // Where the last service left it. Without these an interval is just
            // a number — there is nothing to measure from.
            $table->decimal('last_service_odometer', 12, 1)->nullable()->after('service_interval_days');
            $table->date('last_service_on')->nullable()->after('last_service_odometer');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'service_interval_km', 'service_interval_days',
                'last_service_odometer', 'last_service_on',
            ]);
        });
    }
};
