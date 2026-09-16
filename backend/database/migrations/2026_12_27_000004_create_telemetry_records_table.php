<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-INT — tier 2 of the two-tier telemetry pattern (golden rule 3).
 *
 * Append-only history: one row per ping, never updated, never edited. This is
 * what a trip replay, a temperature audit and any dispute about "where was the
 * truck at 14:20" are answered from. It is the table that grows without bound,
 * which is exactly why nothing on a live screen may query it.
 *
 * TWO clocks, deliberately:
 *   • `recorded_at`  — when the DEVICE says the reading was taken.
 *   • `created_at`   — when WE received it.
 * They differ whenever a unit buffers through a tunnel and replays later, and
 * every drift or backfill question needs both. `created_at` is the one addition
 * to the specified column list, kept to a single column (UPDATED_AT is disabled
 * on the model) because an append-only log has no update to stamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // What the hardware called itself. Kept alongside vehicle_id on
            // purpose: a device can be swapped between vehicles, and the history
            // must still say which box produced the reading.
            $table->string('device_id', 64);

            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('speed', 6, 2)->nullable();
            $table->boolean('ignition')->nullable();
            $table->string('generator_status', 20)->nullable();
            $table->decimal('temperature', 6, 2)->nullable();

            // dateTime, not timestamp: this is an external clock. It sidesteps
            // MySQL's legacy auto-init on the first NOT NULL TIMESTAMP column,
            // the UTC round-trip, and the 2038 cliff.
            $table->dateTime('recorded_at');
            $table->timestamp('created_at')->nullable();

            // The replay query: one vehicle, one window, in order.
            $table->index(['company_id', 'vehicle_id', 'recorded_at'], 'telemetry_company_vehicle_time_index');
            // Ingestion arrives knowing only the device.
            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_records');
    }
};
