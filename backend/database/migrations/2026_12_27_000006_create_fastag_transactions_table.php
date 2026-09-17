<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-COST — FASTag toll crossings.
 *
 * These rows are IMPORTED from a tag issuer's statement, not typed, and an
 * import gets re-run: a partial file, a retried job, a second download of an
 * overlapping date range. So the natural key of a crossing — this tag, at this
 * instant — is unique. Without it, re-running yesterday's file silently doubles
 * the toll cost of every trip it touches.
 *
 * `trip_id` carries no foreign key: Dispatch owns trips (golden rule 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fastag_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->unsignedBigInteger('trip_id')->nullable()->index();

            $table->string('tag_id', 64);
            $table->string('plaza_name', 150)->nullable();
            $table->decimal('amount', 18, 2);
            // dateTime, not timestamp. On a MySQL with the legacy
            // explicit_defaults_for_timestamp=OFF, the first NOT NULL TIMESTAMP
            // column silently gains ON UPDATE CURRENT_TIMESTAMP -- which would
            // rewrite the crossing time every time somebody reconciles the row.
            $table->dateTime('transaction_timestamp');

            // unreconciled | matched | disputed | settled
            $table->string('reconciliation_status', 20)->default('unreconciled');

            $table->timestamps();

            // Idempotency for re-imported statements. One tag cannot cross one
            // plaza twice in the same second.
            $table->unique(['company_id', 'tag_id', 'transaction_timestamp'], 'fastag_company_tag_time_unique');
            $table->index(['company_id', 'vehicle_id', 'transaction_timestamp'], 'fastag_company_vehicle_time_index');
            $table->index(['company_id', 'reconciliation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fastag_transactions');
    }
};
