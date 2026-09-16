<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `transport_consignment_containers` — STOS-CTD §8's "controlled relationship".
 *
 * §8, verbatim: "A consignment may contain one container; contain multiple
 * containers; have other cargo references. The architecture must therefore
 * support Consignment ↔ Container as a controlled relationship."
 *
 * Many-to-many OVER TIME, which is why this is a table and not a foreign key.
 * One consignment carries several containers; one container carries a different
 * consignment every few weeks, and §7 requires that history to survive.
 *
 * ── THE RULE THIS TABLE EXISTS TO ENFORCE ────────────────────────────────
 * STOS-CTD §7: "Container reuse across different trips is allowed HISTORICALLY
 * but NOT SIMULTANEOUSLY where business rules prohibit it."
 *
 * A service-level check cannot enforce that. Two concurrent requests both read
 * "no active attachment", both pass, and both insert. It has to be the database.
 *
 * ── HOW, AND WHY NOT THE OBVIOUS WAY ─────────────────────────────────────
 * The obvious mechanism is a partial unique index — UNIQUE(...) WHERE
 * detached_at IS NULL. **MySQL does not support partial indexes.** The suite
 * runs on sqlite (phpunit.xml: DB_CONNECTION=sqlite, :memory:), which DOES
 * support them — so a partial index would pass every test and enforce nothing
 * in production. That is the trap, and it is the same shape as D-51.
 *
 * Instead: a STORED generated column holding container_id only while the row is
 * attached, and NULL once it is detached, with a unique index over it. Both
 * engines ignore NULLs in a unique index, so any number of detached rows
 * coexist and only one attached row can exist per container per tenant.
 *
 * ── WHICH ENGINE ENFORCES WHAT ───────────────────────────────────────────
 * BOTH. This was not assumed — the mechanism was probed directly against
 * MySQL 8.0.46 and sqlite 3.45.1 before this migration was written, and all
 * five behaviours matched on both:
 *
 *   first attach                          accepted      accepted
 *   second attach while attached          REFUSED       REFUSED
 *   re-attach after detach                accepted      accepted
 *   many detached rows coexist            yes           yes
 *   another tenant may attach the same    yes           yes
 *
 * ContainerAttachmentGuaranteeTest asserts the refusal on whichever engine the
 * suite is running, and additionally skips-unless-MySQL for a run against the
 * engine production actually uses.
 *
 * ── NO seal_number ───────────────────────────────────────────────────────
 * It was in an earlier schema proposal of mine and has been removed. STOS-CMP
 * §76/§77 specify seal control and make a mismatch a Security/Quality Incident;
 * that is Person 3's under TM-001 §3. Requested from them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_consignment_containers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->index();

            $table->unsignedBigInteger('consignment_id');
            $table->unsignedBigInteger('container_id');

            // §7 — "maintain historical associations". A detachment is recorded,
            // never deleted: the row is the history.
            $table->dateTime('attached_at');
            $table->dateTime('detached_at')->nullable();

            $table->unsignedBigInteger('attached_by')->nullable();
            $table->unsignedBigInteger('detached_by')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // No soft deletes: an attachment that happened is a fact, and
            // detached_at already expresses "no longer current".

            $table->index(['tenant_id', 'consignment_id'], 'transport_cc_consignment_idx');
            $table->index(['tenant_id', 'container_id'], 'transport_cc_container_idx');
        });

        // The generated column and its unique index, written in raw SQL because
        // the expression differs by engine in type only, and because Laravel's
        // storedAs() would hide which engine is doing the work.
        $driver = Schema::getConnection()->getDriverName();

        $type = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';

        DB::statement(
            "ALTER TABLE transport_consignment_containers
             ADD COLUMN active_container_key {$type}
             GENERATED ALWAYS AS (CASE WHEN detached_at IS NULL THEN container_id ELSE NULL END) STORED"
        );

        DB::statement(
            'CREATE UNIQUE INDEX transport_cc_active_uniq
             ON transport_consignment_containers (tenant_id, active_container_key)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_consignment_containers');
    }
};
