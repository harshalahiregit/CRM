<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS — the last two of Developer 2's nine tables (STOS-DB).
 *
 * `urea_transactions` — AdBlue is a consumable with its own price, its own
 * tank and its own per-km rate; it is NOT diesel and folding it into
 * fuel_transactions would corrupt every L/KM figure.
 *
 * `tyre_fitments` — a tyre is an asset that outlives the axle it is on. The
 * row tracks ONE tyre's life on ONE position: fitted at an odometer reading,
 * inspected for tread, then rotated, retreaded or scrapped. Moving a tyre
 * closes the current row and opens the next, so the history is a chain rather
 * than a single mutable record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('urea_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            // Dispatch owns trips — indexed column, no constraint.
            $table->unsignedBigInteger('trip_id')->nullable()->index();

            $table->decimal('litres', 12, 3);
            $table->decimal('rate_per_litre', 18, 2)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('odometer', 12, 1)->nullable();
            // Litres of AdBlue per 100 km — the number that exposes a driver
            // topping up too often, or a dosing system that has been bypassed.
            $table->decimal('litres_per_100km', 8, 2)->nullable();
            $table->string('station_vendor', 150)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'vehicle_id']);
        });

        Schema::create('tyre_fitments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            // The tyre's own identity — stamped on the casing, and the thing
            // that follows it from vehicle to vehicle and through a retread.
            $table->string('tyre_id', 60);

            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            // front_left | front_right | rear_inner_left | rear_outer_left |
            // rear_inner_right | rear_outer_right | trailer_1 … | spare
            $table->string('position', 30)->nullable();

            // in_stock | fitted | removed | retreaded | scrapped
            $table->string('status', 20)->default('fitted');

            $table->decimal('tread_depth', 4, 2)->nullable();       // mm
            $table->decimal('odometer_at_fitment', 12, 1)->nullable();
            $table->decimal('odometer_at_removal', 12, 1)->nullable();
            $table->date('fitted_on')->nullable();
            $table->date('removed_on')->nullable();
            $table->date('inspected_on')->nullable();
            $table->string('note', 255)->nullable();

            $table->timestamps();

            // One tyre can only be fitted in one place at a time. Enforced in
            // the service rather than the schema, because the SAME tyre_id
            // legitimately appears many times across its life.
            $table->index(['company_id', 'tyre_id']);
            $table->index(['company_id', 'vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tyre_fitments');
        Schema::dropIfExists('urea_transactions');
    }
};
