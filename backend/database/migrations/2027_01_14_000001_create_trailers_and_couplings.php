<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-FLEET — trailers as their own master, and the coupling between (T-54).
 *
 * ── A TRAILER IS NOT A `vehicles` ROW ─────────────────────────────────────
 * It is tempting to add `trailer` to `vehicle_type` and be done — the column
 * already has the value — and it is wrong, because almost nothing a `vehicles`
 * row carries is true of a trailer:
 *
 *   no engine  → no fuel, no odometer, no efficiency, and NO PUC CERTIFICATE
 *   no cab     → no driver, no licence, no assignment
 *   no device  → no telemetry, no live status, no position of its own
 *
 * A trailer sharing that table would need every one of those columns nulled and
 * every query that reads them taught to skip it. The PUC one is the clearest
 * test: a compliance sweep that demands an emissions certificate from a box on
 * wheels is not a small nuisance, it is a truck grounded for a document that
 * cannot exist.
 *
 * What a trailer DOES have is its own registration, its own fitness, insurance
 * and permit, its own tyres, and its own maintenance history — which is exactly
 * why it is a master and not an attribute.
 *
 * ── THE COUPLING IS THE INTERESTING PART ──────────────────────────────────
 * A tractor and a trailer are paired and re-paired daily. The pairing is not a
 * property of either one; it is an event with a start and an end, and the
 * HISTORY is the point. "Which trailer was under that truck on the 14th" is
 * asked when a load spoils, when a claim is filed, and when a tyre fails — and
 * a `vehicles.trailer_id` column answers only "which one is under it now",
 * which is the least useful version of the question.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trailers')) {
            Schema::create('trailers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();

                $table->string('trailer_number', 40);
                // Normalised the same way a plate is — "MH 12 AB 1234" and
                // "MH12AB1234" are one trailer.
                $table->string('registration_normalized', 40);
                $table->string('fleet_number', 40)->nullable();

                // flatbed | skeletal | tipper | tanker | reefer | curtain | lowbed | other
                $table->string('trailer_type', 30);
                $table->string('ownership_type', 20)->default('OWNED');

                $table->decimal('capacity_tonnes', 8, 2)->nullable();
                $table->unsignedTinyInteger('axles')->nullable();
                $table->decimal('length_feet', 5, 1)->nullable();

                $table->string('manufacturer', 100)->nullable();
                $table->string('model', 100)->nullable();
                $table->unsignedSmallInteger('manufacturing_year')->nullable();
                $table->date('purchase_date')->nullable();
                $table->string('chassis_number', 100)->nullable();

                // AVAILABLE | COUPLED | UNDER_MAINTENANCE | COMPLIANCE_BLOCKED | RETIRED
                $table->string('status', 24)->default('AVAILABLE');
                $table->string('compliance_status', 20)->default('compliant');

                // Three, not five. A trailer has no engine, so no PUC; and its
                // registration certificate is its own.
                $table->date('registration_expiry')->nullable();
                $table->date('fitness_expiry')->nullable();
                $table->date('insurance_expiry')->nullable();
                $table->date('permit_expiry')->nullable();

                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'registration_normalized'], 'trailers_company_reg_uniq');
                $table->index(['company_id', 'status'], 'trailers_company_status_idx');
            });
        }

        if (Schema::hasTable('vehicle_trailer_assignments')) {
            return;
        }

        Schema::create('vehicle_trailer_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('trailer_id');

            $table->dateTime('coupled_at');
            $table->dateTime('uncoupled_at')->nullable();

            $table->unsignedBigInteger('coupled_by')->nullable();
            $table->unsignedBigInteger('uncoupled_by')->nullable();
            $table->string('reason', 500)->nullable();

            $table->timestamps();

            /*
             | ONE ACTIVE COUPLING PER TRAILER, AND PER TRACTOR.
             |
             | Same device as `trip_assignments` uses, and for the same reason:
             | MySQL 8 has no partial unique index, so the portable equivalent
             | is a STORED generated column that is NULL unless the row is live.
             | Both engines allow unlimited NULLs in a unique index, so released
             | couplings accumulate freely while at most one open row can exist.
             |
             | Declared inside CREATE TABLE because SQLite cannot ALTER in a
             | STORED column, and the expression is ANSI CASE rather than
             | MySQL's IF() because the suite runs on SQLite.
             |
             | This is the backstop, not the rule — the service refuses first
             | and with a sentence. The index is what holds when two people
             | couple the same trailer from two screens in the same second.
            */
            $active = 'CASE WHEN uncoupled_at IS NULL THEN %s ELSE NULL END';

            $table->unsignedBigInteger('active_trailer_id')->nullable()
                ->storedAs(sprintf($active, 'trailer_id'));
            $table->unsignedBigInteger('active_vehicle_id')->nullable()
                ->storedAs(sprintf($active, 'vehicle_id'));

            $table->unique(['company_id', 'active_trailer_id'], 'vta_active_trailer_uniq');
            $table->unique(['company_id', 'active_vehicle_id'], 'vta_active_vehicle_uniq');

            $table->index(['company_id', 'vehicle_id', 'coupled_at'], 'vta_vehicle_history_idx');
            $table->index(['company_id', 'trailer_id', 'coupled_at'], 'vta_trailer_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_trailer_assignments');
        Schema::dropIfExists('trailers');
    }
};
