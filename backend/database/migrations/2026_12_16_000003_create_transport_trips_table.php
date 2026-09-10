<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNG-TRN-007 — Trip (DB-002), the central operational and economic object.
 *
 * "As dispatch, I can convert an approved order into a trip."
 * Acceptance: "Canonical trip ID and state initialized correctly."
 *
 * Columns trace to:
 *   tenant_id, order_id, trip_number,
 *   status, approved_freight, currency   Step 11 FLD-004 … FLD-009
 *   unique(tenant, trip_number)          Step 11 IDX-002 + BR-P0-001
 *   index(tenant, status)                Step 11 IDX-003
 *   customer/vehicle/driver/route links  STOS-OPS §37
 *
 * order_id IS NOT NULL — the one place Step 11 contradicts itself. FLD-005 marks
 * it nullable while CTR-004 marks it required with the note "Cannot create
 * orphan trip". Ruled in favour of CTR-004 in the scope agreement: a stated
 * intent beats an unexplained nullable flag, and relaxing a NOT NULL later is a
 * one-line migration whereas tightening one is not.
 *
 * vehicle_id / driver_id are nullable with NO foreign key, following the team
 * convention for a shared entity that does not exist yet: "use the agreed column
 * name now (nullable FK, no constraint yet) so wiring it later is a one-line
 * migration, not a rename across modules." Vehicle and Driver masters are
 * SNG-TRN-003 and 004; allocation is SNG-TRN-009. NOTHING in this ticket writes
 * to them — they exist so the later ticket does not have to rename anything.
 *
 * DELIBERATELY ABSENT — flagged: planned_departure_at. Step 11's IDX-004 indexes
 * it, but it appears in NO field registry entry. Creating the column would be
 * inventing a field; creating the index without it is impossible. Raised as a
 * registry gap rather than resolved here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_trips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // CTR-004 — required. "Cannot create orphan trip."
            $table->unsignedBigInteger('order_id')->index();

            // FLD-006 — VARCHAR(40), UNIQUE(tenant, trip_number), IMMUTABLE.
            // BR-P0-001 narrows it further: unique within company/YEAR.
            $table->string('trip_number', 40);

            // FLD-007 — default draft, indexed. Step 9's locked machine.
            $table->string('status', 40)->default('draft');

            // FLD-008 / FLD-009.
            $table->decimal('approved_freight', 18, 2)->nullable();
            $table->char('currency', 3)->default('INR');

            // OPS §37 — a trip links customer, vehicle, driver and route.
            // Carried from the order on creation so a trip is readable alone.
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('route', 190)->nullable();

            // Set by SNG-TRN-009, never by this ticket. See the class docblock.
            $table->unsignedBigInteger('vehicle_id')->nullable()->index();
            $table->unsignedBigInteger('driver_id')->nullable()->index();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'trip_number'], 'transport_trips_tenant_number_uniq');  // 34  (IDX-002)
            $table->index(['tenant_id', 'status'], 'transport_trips_tenant_status_idx');         // 35  (IDX-003)
            $table->index(['tenant_id', 'order_id'], 'transport_trips_tenant_order_idx');        // 34
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_trips');
    }
};
