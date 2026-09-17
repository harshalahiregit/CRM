<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — the vehicle master.
 *
 * The root of the Fleet domain: every other table in this sprint points back
 * here. Deliberately thin. It holds what a vehicle IS and never what it is
 * DOING — no latitude, no speed, no last ping. Live state lives in
 * `vehicle_live_status` and history in `telemetry_records` (golden rule 3), so a
 * device reporting every thirty seconds never writes to the master row.
 *
 * `company_id` is indexed on every STOS table and no query may omit it
 * (golden rule 1). It carries no DB-level foreign key because this platform has
 * no `companies` table — the workspace is `tenants` — and pointing a constraint
 * at a table that doesn't exist would fail the migration. See
 * App\Domains\Shared\Concerns\BelongsToCompany for the one place the two names
 * are reconciled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            // The number plate is how everyone refers to a vehicle, so it is the
            // one required identifier.
            $table->string('registration_number', 40);

            // truck | trailer | tipper | tanker | reefer | lcv | other
            $table->string('vehicle_type', 30)->default('truck');
            // owned | leased | attached | market
            $table->string('ownership_type', 20)->default('owned');

            $table->string('chassis_number', 50)->nullable();
            $table->string('engine_number', 50)->nullable();

            // The telemetry link: ingestion arrives knowing a device, not a
            // vehicle, and resolves it through this column.
            $table->string('gps_device_id', 64)->nullable();

            // active | in_maintenance | idle | retired
            $table->string('status', 20)->default('active');
            // compliant | expiring | expired | blocked — the rolled-up verdict on
            // the vehicle's papers, written by the compliance check, not by hand.
            $table->string('compliance_status', 20)->default('compliant');

            $table->timestamps();
            $table->softDeletes();

            // One plate per workspace; two companies on one install may each
            // hold the same number.
            $table->unique(['company_id', 'registration_number'], 'vehicles_company_registration_unique');
            // A device reports for exactly one vehicle. Without this, two rows
            // can claim one device and ingestion silently picks the wrong one.
            $table->unique(['company_id', 'gps_device_id'], 'vehicles_company_device_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'compliance_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
