<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-COST — diesel and AdBlue/urea spend.
 *
 * Money is DECIMAL(18,2) without exception (golden rule 5). Litres are NOT
 * money: they get their own scale, because 3 decimal places is what a pump
 * prints and rounding them to 2 loses reconciliation against the bill.
 *
 * `trip_id` is a plain indexed column with NO foreign key. Trips belong to
 * Dispatch (Developer 1) and this domain must not create or constrain that table
 * (golden rule 2) — the column is agreed now so wiring it later is a one-line
 * migration rather than a rename across modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedBigInteger('trip_id')->nullable()->index();

            $table->decimal('litres', 12, 3);
            $table->decimal('rate_per_litre', 18, 2);
            // Stored, not derived: the bill is the record. litres x rate can
            // disagree with it by a rounding paisa, and the bill wins.
            $table->decimal('amount', 18, 2);

            // Reading at the pump — what makes mileage calculable.
            $table->decimal('odometer', 12, 1)->nullable();
            $table->string('station_vendor', 150)->nullable();

            // A driver fuelling off-network, out of pocket or off-card.
            $table->boolean('is_emergency')->default(false);
            // not_applicable | pending | recovered | waived — only meaningful
            // when the fill has to be recovered from someone.
            $table->string('recovery_status', 20)->default('not_applicable');

            $table->timestamps();

            $table->index(['company_id', 'vehicle_id']);
            $table->index(['company_id', 'recovery_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_transactions');
    }
};
