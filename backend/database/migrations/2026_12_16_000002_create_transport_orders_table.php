<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNG-TRN-006 — Transport Order (DB-001).
 *
 * "As sales/ops, I can create an executable transport order."
 * Acceptance: "Order has customer, lane, rate, service requirements."
 *
 * Every column below traces to an approved source; nothing is here "to be safe".
 *   tenant_id, customer_id, order_status   Step 11 FLD-001/002/003
 *   pickup/delivery_location               Step 11 CTR-002/003 (OBJECT, address schema)
 *   order_number                           STOS-DB §29, unique per org §161
 *   customer_reference, service_type,
 *   required_at, route, special_*,
 *   billing_requirements, rate_reference   STOS-OPS §5, BRW-016, STOS-LSM §6.2
 *   priority                               STOS-OPS §9, BRW-018 (exactly 4 values)
 *   source                                 STOS-OPS §6 "Every creation must identify source"
 *
 * DELIBERATELY ABSENT — flagged, not forgotten:
 *   container_id / consignment_id — BRW-016 makes them mandatory "where
 *     applicable" and STOS-LSM §7 gives the consignment 26 states, but NEITHER
 *     entity exists in the Step 11 DB registry. Adding a column for a table
 *     nobody has specified would be inventing the relationship.
 *   transport_order_items — STOS-DB §31 asks for it so one order can carry many
 *     containers. Left out because it depends on the container entity above and
 *     was not in the confirmed field set.
 *
 * Locations are JSON. CTR-002/003 specify an OBJECT with an "address schema";
 * no such schema is defined in any document, so the shape is carried as JSON
 * rather than guessed into columns that would later need renaming.
 *
 * Index names are explicit and short — MySQL rejects identifiers over 64 chars
 * and SQLite does not, so a derived name passes the suite and breaks production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // STOS-DB §161 — unique per organization, per numbering rules.
            $table->string('order_number', 40);

            // CTR-001: "exists in tenant… No cross-tenant IDs". No FK constraint:
            // clients is another module's table and the conventions fix the
            // column name rather than the constraint.
            $table->unsignedBigInteger('customer_id')->index();
            $table->string('customer_reference', 120)->nullable();

            // CTR-002 / CTR-003 — OBJECT, address schema. Geocoding optional.
            $table->json('pickup_location');
            $table->json('delivery_location');

            // BRW-016 / OPS §7 — required date/time must be defined.
            $table->dateTime('required_at');

            $table->string('service_type', 80);

            // FLD-003 — VARCHAR(40), default draft, indexed.
            $table->string('order_status', 40)->default('draft');

            // OPS §9 / BRW-018 — Normal | Priority | Urgent | Critical.
            $table->string('priority', 20)->default('Normal');

            // OPS §6 — every creation identifies its source.
            $table->string('source', 30)->default('manual');

            // OPS §7 — "applicable commercial reference exists". Free reference
            // until rate cards land in SNG-TRN-005; not an FK to nothing.
            $table->string('rate_reference', 120)->nullable();

            $table->string('route', 190)->nullable();
            $table->text('special_requirements')->nullable();
            $table->text('billing_requirements')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'order_number'], 'transport_orders_tenant_number_uniq');  // 35
            $table->index(['tenant_id', 'customer_id'], 'transport_orders_tenant_customer_idx');   // 38  (IDX-001)
            $table->index(['tenant_id', 'order_status'], 'transport_orders_tenant_status_idx');    // 36
            $table->index(['tenant_id', 'required_at'], 'transport_orders_tenant_required_idx');   // 38
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_orders');
    }
};
