<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `transport_consignments` — the commercial shipment.
 *
 * ── THIS ENTITY EXISTS BY EXPLICIT ARCHITECTURE APPROVAL (D-39) ───────────
 * Step 9's canonical Domain_Model lists twenty objects and Consignment is not
 * among them; its header states that "duplicate business objects are prohibited
 * without architecture approval". That approval was granted in writing on
 * 2026-09-12, so Step 9 is AMENDED rather than contradicted. The grounds are
 * recorded with the ruling in docs/transport/registry-defects.md.
 *
 * ── WHY IT IS NOT THE SAME THING AS A CONTAINER ───────────────────────────
 * STOS-CTD §8, verbatim: "These must not be treated as identical concepts.
 * Container — the physical transport unit. Consignment — the commercial/
 * operational shipment being transported. A consignment may contain one
 * container; contain multiple containers; have other cargo references."
 *
 * Hence three tables rather than two: this one, the container master, and the
 * association between them (D-40, ruled Option B1). A consignment with no
 * container at all is valid — "other cargo references" is break-bulk.
 *
 * ── REQUIREMENTS THIS TABLE CARRIES ───────────────────────────────────────
 *   STOS-REQ-ORD-004  Link container to order          P0  (via order_id)
 *   STOS-REQ-CTD-002  Link container to customer       P0  (via customer_id)
 *   STOS-REQ-CTD-003  Link container to order          P0  (stated twice in the RTM)
 *   STOS-CTD §4       "Internal Consignment ID" as a search key
 *   STOS-CTD §6       Passport identity: Consignment ID, Customer ID,
 *                     Transport Order ID, Customer Reference
 *   FRS TRP-P0-001    service type, special handling
 *
 * ── THERE IS DELIBERATELY NO `status` COLUMN ──────────────────────────────
 * STOS-CTD §11 opens: "Status must come from the lifecycle engine." Its twenty
 * example values — Created, Allocated, Dispatched, In Transit, At Port,
 * Delivered, POD Pending, Billing Blocked, Invoiced, Collection Pending,
 * Closed — span the order, the trip, POD, billing and collection. Most of those
 * belong to Person 3 or to tickets nobody has built.
 *
 * A stored consignment status would therefore be a roll-up of five lifecycles
 * this table cannot see, and would be wrong the moment any of them moved. It is
 * derived at read time instead, the same decision PretripReadiness and
 * ExceptionSlaState already carry.
 *
 * ── TENANCY ───────────────────────────────────────────────────────────────
 * tenant_id is the first column, not nullable, and every index leads with it.
 * The model carries BelongsToTenant and every read chains forTenant() — scoping
 * is opt-in in this codebase, never automatic (ARCHITECTURE-PRIMER §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_consignments', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->index();

            // STOS-CTD §4 "Internal Consignment ID", §6 "Consignment ID".
            // Allocated through the Document Numbering Engine, like every other
            // reference in this module.
            $table->string('consignment_number', 40);

            // ORD-004 / CTD-003. Required: a consignment without an order is not
            // a commercial shipment, and the link direction is fixed as
            // order -> consignment -> container.
            $table->unsignedBigInteger('order_id');

            // CTD-002. Denormalised from the order so that a search by customer
            // does not have to join through it — CTD §4 makes this a search path
            // in its own right. Nullable because the order owns the truth.
            $table->unsignedBigInteger('customer_id')->nullable();

            // STOS-CTD §4 and §6 — the customer's own reference, searchable.
            $table->string('customer_reference', 120)->nullable();

            // STOS-CTD §8's "other cargo references": what is actually being
            // moved when there is no container.
            $table->text('cargo_description')->nullable();

            // FRS TRP-P0-001's field list.
            $table->string('service_type', 60)->nullable();
            $table->text('special_handling')->nullable();

            // Cargo measures. decimal, never float — and 3 decimal places
            // because part-tonne and part-cbm are ordinary in this trade.
            $table->unsignedInteger('package_count')->nullable();
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->decimal('volume_cbm', 12, 3)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // BR-P0-001's shape: unique within the tenant.
            $table->unique(['tenant_id', 'consignment_number'], 'transport_consignments_number_uniq');

            $table->index(['tenant_id', 'order_id'], 'transport_consignments_order_idx');
            $table->index(['tenant_id', 'customer_id'], 'transport_consignments_customer_idx');

            // CTD §4 lists Customer Reference as a search path of its own.
            $table->index(['tenant_id', 'customer_reference'], 'transport_consignments_custref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_consignments');
    }
};
