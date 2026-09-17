<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-INT — tier 1 of the two-tier telemetry pattern (golden rule 3).
 *
 * Exactly ONE row per vehicle, overwritten in place on every ping. This is what
 * the live map and the control-tower screens read, so it must stay a primary-key
 * lookup no matter how many million rows `telemetry_records` has grown to.
 *
 * `vehicle_id` IS the primary key — there is no surrogate id and no unique index
 * to enforce afterwards, so a second live row for one vehicle is impossible by
 * construction rather than by discipline.
 *
 * No `timestamps()`: `last_ping_at` is the only time that means anything here,
 * and an updated_at that changes every thirty seconds is just write amplification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_live_status', function (Blueprint $table) {
            $table->unsignedBigInteger('vehicle_id')->primary();
            $table->unsignedBigInteger('company_id')->index();

            // Geospatial precision is fixed by golden rule 5 and is not a
            // judgement call: DECIMAL(10,8) / DECIMAL(11,8), never a float.
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            $table->decimal('speed', 6, 2)->nullable();          // km/h
            $table->boolean('ignition')->nullable();             // null = the device never said

            // off | on | standby | fault — the reefer genset, not the engine.
            $table->string('generator_status', 20)->nullable();
            // Reefer body temperature. Signed: a frozen load runs at -18 C.
            $table->decimal('temperature', 6, 2)->nullable();

            $table->timestamp('last_ping_at')->nullable();

            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();

            // "Which of my vehicles has gone quiet?" — the stale-device sweep.
            $table->index(['company_id', 'last_ping_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_live_status');
    }
};
