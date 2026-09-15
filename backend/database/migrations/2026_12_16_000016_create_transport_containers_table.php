<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `transport_containers` — the container MASTER.
 *
 * One row per physical transport unit. `STOS-REQ-MDM-008` ("Maintain container
 * master/reference", P0) is the requirement; D-39 is the architecture approval
 * that let Container exist as an entity at all, and D-40 ruled the master +
 * association shape (Option B1).
 *
 * ── UNIQUENESS LIVES HERE, AND ONLY HERE ─────────────────────────────────
 * STOS-CTD §7: the container number is "unique **where applicable**", and
 * "container reuse across different trips is allowed historically but not
 * simultaneously". Those two clauses only reconcile if uniqueness is a property
 * of the PHYSICAL UNIT, not of an attachment. So the master carries
 * UNIQUE(tenant_id, container_number_normalized) and the association table
 * carries the one-at-a-time rule.
 *
 * ── TWO COLUMNS FOR ONE NUMBER, BECAUSE §7 ASKS FOR BOTH ─────────────────
 * §7 wants the number "normalized for search" AND the "original entered value
 * retained". A single column cannot do both: normalising in place loses what
 * the operator typed, and searching the raw value misses `ABCD 123456 7`
 * against `ABCD1234567`.
 *
 *   container_number             exactly as entered, shown on screen
 *   container_number_normalized  upper-cased, non-alphanumerics stripped;
 *                                the search key and the unique key
 *
 * ── NO FORMAT VALIDATION, AND THAT IS DELIBERATE ─────────────────────────
 * §7 also asks for "configurable format validation". No format is specified in
 * any document in the package. ISO 6346 is the industry standard and the
 * package never names it; its check digit would reject legitimate non-ISO
 * numbers, and refusing a customer's real container number is a worse failure
 * than accepting a malformed one. Deferred to Product — see the coverage note.
 *
 * ── NO container_type ENUM ───────────────────────────────────────────────
 * §6 requires the field. NO DOCUMENT ANYWHERE DEFINES ITS VALUES — all thirty
 * package documents were searched for 20ft, 40ft, HC, high cube and ISO 6346,
 * and Step 11 has no container enum and no container DB row at all. A free-text
 * column, therefore, not an enum. Inventing the vocabulary would repeat D-9
 * (`allocation_type`: a field with no defined values). Logged as D-50.
 *
 * ── NO STATUS ────────────────────────────────────────────────────────────
 * Same reasoning as the consignment (D-44): STOS-CTD §11 puts status in a
 * lifecycle engine that does not exist, and its values span five lifecycles
 * across two owners. Derived at read time, never stored.
 *
 * ── NO SEAL FIELDS ───────────────────────────────────────────────────────
 * STOS-CMP §76 requires seal number, issued by, issued date, container,
 * verified at delivery and mismatch — and §77 turns a mismatch into a
 * Security/Quality Incident. Compliance and QC are Person 3's under TM-001 §3.
 * Requested from them, not built here. (An earlier schema proposal of mine
 * listed seal_number on the association; it was removed once the source was
 * checked.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_containers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id')->index();

            // STOS-CTD §7 — "retain original entered value where required".
            $table->string('container_number', 20);

            // STOS-CTD §7 — "be normalized for search". Also the unique key:
            // ABCD1234567 and "abcd 123456 7" are the same physical box.
            $table->string('container_number_normalized', 20);

            // STOS-CTD §6 "Container Type". Free text — no vocabulary exists (D-50).
            $table->string('container_type', 40)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // §7's "unique where applicable", applied where it IS applicable:
            // the physical unit within one workspace.
            $table->unique(['tenant_id', 'container_number_normalized'], 'transport_containers_number_uniq');

            // CTD-001 — "search complete lifecycle using container number".
            $table->index(['tenant_id', 'container_number_normalized'], 'transport_containers_search_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_containers');
    }
};
