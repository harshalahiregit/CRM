<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-62, step 1 — one vehicle master, one driver master.
 *
 * Two systems have been live at once: Operations' `transport_vehicles` /
 * `transport_drivers` (P1's placeholder, recorded in TEAM-CONTRACTS §1a as
 * "Fleet, owned by P2… a temporary P1 implementation with an agreed end") and
 * Fleet's `vehicles` / `driver_profiles`.
 *
 * Fleet's tables survive, because nine tables key off `vehicles` — live status,
 * telemetry, fuel, urea, tolls, workshop, tyres, gensets and driver profiles.
 *
 * But the placeholder was NOT the poorer record. It carries identity columns
 * Fleet never had — fleet number, make/model, year, fuel type, branch, purchase
 * date, and the payload capacity the eligibility engine matches an order
 * against. Throwing those away to "win" the merge would lose real data and
 * break `VehicleEligibilityService`'s capacity check.
 *
 * So this is a UNION, not a replacement: Fleet's table gains every column
 * Operations had. The next migration moves the rows; nothing is dropped here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // ── Identity, from transport_vehicles ────────────────────
            // Stored normalised alongside the display plate. Fleet already
            // normalises on write; Operations kept a separate column and its
            // queries use it, so both spellings stay available.
            $table->string('registration_normalized', 40)->nullable()->after('registration_number');
            $table->string('fleet_number', 40)->nullable()->after('registration_normalized');

            $table->string('manufacturer', 100)->nullable()->after('vehicle_type');
            $table->string('model', 100)->nullable()->after('manufacturer');
            $table->string('variant', 100)->nullable()->after('model');
            $table->unsignedSmallInteger('manufacturing_year')->nullable()->after('variant');
            $table->date('purchase_date')->nullable()->after('manufacturing_year');

            // diesel | petrol | cng | lng | electric | hybrid
            $table->string('fuel_type', 20)->nullable()->after('purchase_date');
            $table->string('branch', 100)->nullable()->after('fuel_type');

            // The payload the eligibility engine matches an order's required
            // capacity against (PLN-001). Fleet had no capacity column at all —
            // this closes that gap and preserves Operations' data in one move.
            $table->decimal('capacity_tonnes', 8, 2)->nullable()->after('branch');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            // Where a migrated row came from, so the move is auditable and a
            // second run cannot duplicate it.
            $table->unsignedBigInteger('legacy_transport_vehicle_id')->nullable()->index();

            $table->index(['company_id', 'registration_normalized'], 'vehicles_company_normalized_index');
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            // Operations' licence record is richer than Fleet's: it keeps the
            // issue date and a normalised number as well as the expiry.
            $table->string('licence_normalized', 40)->nullable()->after('licence_number');
            $table->date('licence_valid_from')->nullable()->after('licence_class');

            // Operations linked a driver to HR or to a supplier. Fleet resolves
            // people through DriverDirectory instead, but these are kept so the
            // original link survives the move and can be reconciled later.
            $table->unsignedBigInteger('hr_employee_id')->nullable()->after('source_id');
            $table->unsignedBigInteger('supplier_id')->nullable()->after('hr_employee_id');
            $table->string('driver_code', 40)->nullable()->after('supplier_id');

            $table->unsignedBigInteger('legacy_transport_driver_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('vehicles_company_normalized_index');
            $table->dropColumn([
                'registration_normalized', 'fleet_number', 'manufacturer', 'model', 'variant',
                'manufacturing_year', 'purchase_date', 'fuel_type', 'branch', 'capacity_tonnes',
                'created_by', 'updated_by', 'legacy_transport_vehicle_id',
            ]);
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'licence_normalized', 'licence_valid_from', 'hr_employee_id',
                'supplier_id', 'driver_code', 'legacy_transport_driver_id',
            ]);
        });
    }
};
