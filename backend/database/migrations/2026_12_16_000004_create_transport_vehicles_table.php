<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SNG-TRN-003 — Vehicle master.
 *
 * Ticket: "As an operator, I can maintain vehicle, trailer and compliance details."
 * Acceptance: "Unique vehicle identity, document dates, audit."
 *
 * ── TABLE NAME: A DELIBERATE DEVIATION, NOT AN OVERSIGHT ──────────────────
 * Step 11 DB-004 names this table `vehicles`. This migration creates
 * `transport_vehicles`. Ruled by the owner on 2026-09-07, for the same reason
 * `tenant_id` was ruled over Step 11's `company_id`: the registry was written
 * against a standalone product, and this is a module inside a 489-table platform
 * where every table is module-prefixed (hr_, purchase_, tpv_, acc_, inventory_).
 * A bare `vehicles` would be the only unprefixed generic noun in the schema, and
 * it already collides conceptually with TPV's `site_vehicles` gate register.
 * `trip_assignments` (DB-003) keeps its registry name unchanged — it is already
 * specific enough not to collide.
 *
 * ── REGISTRY DEFECTS THIS TABLE SITS ON TOP OF ────────────────────────────
 * D-2: ticket 003 cites DB-003, which is `trip_assignments`, not vehicles. The
 *      correct entity is DB-004. Off-by-one across tickets 003/004.
 * D-3: Step 11's DB_Fields specifies ZERO columns for DB-004. All 20 FLD-* rows
 *      describe other entities. Every column below therefore comes from
 *      reference tier — STOS-FLEET §6/§9/§10 and STOS-DB §39 — which is the
 *      Container gap (Blocker 3) repeating. Flagged, not silently filled.
 *
 * ── WHAT IS DELIBERATELY ABSENT ───────────────────────────────────────────
 * trailer            Ticket 003's text names it, but no trailer entity exists
 *                    anywhere in Step 11, and STOS-DB §40 forbids folding it into
 *                    this table. Raised as a registry gap; owner ruled out of scope.
 * current_driver     FLEET §6 lists it. Derived from trip_assignments (SNG-TRN-009)
 * current_trip       rather than stored — a denormalised pointer is wrong the
 *                    moment an assignment ends and nobody clears it.
 * current_location   Needs GPS telemetry. SNG-TRN-020, P1. No source exists.
 * compliance_status  Derived from transport_documents at the moment it is asked.
 *                    A stored flag is stale the day after a certificate lapses.
 * tyres/fuel/urea/genset/maintenance   STOS-FLEET domains. No P0 ticket owns them.
 * renewal tasks      FLEET §12: "Expiry should automatically create renewal
 *                    tasks", and BRWM: "Expiring compliance document creates
 *                    renewal task." NOT BUILT. Task creation is not this
 *                    ticket's to own — no P0 ticket owns a transport task
 *                    engine, and the platform's task module is another module
 *                    this ticket may not touch. transport_documents carries the
 *                    expiry dates and an index on them, so whichever ticket
 *                    gains the task engine has everything it needs to sweep.
 *                    Recorded here because it was the only deferral with no
 *                    home in code (audit gap G-3, 2026-09-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // FLEET §9 — "unique within organization; searchable; normalized".
            // Stored twice on purpose: the number AS ENTERED for display and for
            // matching paperwork, and a normalized form (uppercase, alphanumeric
            // only) that carries the uniqueness constraint and the search index.
            // "RJ 14 XX 1234", "RJ-14-XX-1234" and "rj14xx1234" are one vehicle;
            // uniqueness on the raw string would let all three coexist.
            $table->string('registration_number', 32);
            $table->string('registration_normalized', 32);

            // FLEET §9 — other identifiers. Not unique: a fleet number is an
            // internal label, and chassis/engine are transcribed from paperwork
            // often enough that a unique constraint would block legitimate saves
            // on a typo rather than surface it.
            $table->string('fleet_number', 40)->nullable();
            $table->string('chassis_number', 60)->nullable();
            $table->string('engine_number', 60)->nullable();
            $table->string('gps_device_id', 80)->nullable();

            // FLEET §6 — descriptive master data.
            $table->string('vehicle_type', 60)->nullable();
            $table->string('manufacturer', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('variant', 80)->nullable();
            $table->unsignedSmallInteger('manufacturing_year')->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('fuel_type', 30)->nullable();
            $table->string('branch', 120)->nullable();

            // FLEET §6 "Capacity". The unit is in the column name rather than in a
            // separate units column: eligibility has to compare capacity against a
            // required payload numerically (FRS TRP-P0-003 names "payload" as an
            // input), and a units column nothing consistently sets makes that
            // comparison unsafe. Metric tonnes is the Indian haulage convention.
            $table->decimal('capacity_tonnes', 10, 3)->nullable();

            // FLEET §10 — drives asset cost, EMI, profitability, utilization.
            $table->string('ownership_type', 20)->default('owned');

            // FLEET §7. Defaults to 'new': §8 forbids a user typing a vehicle
            // straight to Available without satisfying commissioning conditions.
            $table->string('status', 30)->default('new');

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Explicit, short index names. MySQL caps identifiers at 64 chars and
            // this repo has a test asserting every migration stays under it with
            // no grandfathered exceptions.
            $table->unique(['tenant_id', 'registration_normalized'], 'transport_vehicles_tenant_reg_uniq');  // 34
            $table->index(['tenant_id', 'status'], 'transport_vehicles_tenant_status_idx');                  // 36
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_vehicles');
    }
};
