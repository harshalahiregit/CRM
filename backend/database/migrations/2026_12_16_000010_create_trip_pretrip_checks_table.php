<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trip_pretrip_checks` — the pre-dispatch readiness checklist. SNG-TRN-010 step 2.
 *
 * ── THIS TABLE HAS NO REGISTRY ENTRY. THAT IS THE HEADLINE ────────────────
 * Step 11's DB registry is a LOCKED BASELINE of twenty entities. None of them is
 * a checklist. Ticket 010 cites DB-009, which is `trip_documents`
 * ("LR/POD/EWB/attachments index") — a different thing entirely, and the ticket's
 * other two refs are just as wrong: API-006 is expense submission and EV-006 is
 * AdvanceRequested. Those references are not merely mistaken, they are not
 * references at all: the ticket pack cites up to EV-021 when the event registry
 * defines twelve events, and API-020 when it defines fifteen. They are a
 * sequential counter over tickets. Recorded as D-15.
 *
 * STOS-DB, the database specification, runs to more than a hundred entity
 * sections — including tyre_inspections at §56 — and describes no pre-trip
 * checklist either.
 *
 * The ONLY document in all 42 that names a table for this is Step 5, the
 * Technical Implementation Pack: `trip_checklists | checklist_id | PK | UUID |
 * Trip | "Pre-trip / reefer / safety checks"`. It specifies zero columns, exactly
 * as it does for DB-003/004/005 (D-3), and Step 5 sits BELOW Step 11 in the
 * authority order, so it cannot create an entity Step 11 omits.
 *
 * So this table is built under Step 9's Change Control Class D ("new entity,
 * relationship, state transition"), which requires an architecture decision.
 * FLAGGED for Architect ratification along with D-15.
 *
 * ── NAME ──────────────────────────────────────────────────────────────────
 * `trip_pretrip_checks`, not Step 5's `trip_checklists`. Step 5's name describes
 * a checklist; each row here is one CHECK. Naming the table for the row it holds
 * is the same choice `trip_assignments` made over §46's `vehicle_driver_
 * allocations`, and it keeps the `trip_` prefix every trip-owned table uses.
 *
 * ── ONE ROW PER CHECK, ONE CHECK PER TRIP ─────────────────────────────────
 * No header table and no JSON blob. A header would be a second table where the
 * registry authorises none, and its status would be a stored copy of something
 * derivable — wrong the morning after a document lapses. A JSON blob would make
 * "which trips are blocked on driver documents" unanswerable without scanning.
 *
 * The readiness status of the whole checklist is therefore DERIVED from these
 * rows by PretripReadiness, never stored. Same reasoning as
 * DriverComplianceStatus, and the reason there is no readiness column on
 * transport_trips.
 *
 * UNIQUE(tenant_id, trip_id, check_key) is what makes that safe: a trip cannot
 * hold two rows for the same check, so regeneration is an idempotent upsert
 * rather than an accumulating pile, and a derived status can never be computed
 * from duplicates.
 *
 * ── WHERE THE COLUMNS COME FROM ───────────────────────────────────────────
 *   check_key       PretripCheckKey — OPS §28's items, extended by BRW-047,
 *                   OPS §35, CMP §157 and FRS TRP-P0-005.
 *   is_critical     BRW-052. A POLICY SNAPSHOT, not a live read — see below.
 *   result          PretripResult — FLEET §88's four values plus `pending`.
 *   detail          BRW-048: "If dispatch fails, Sangoe must display exact
 *                   reason." The machine-written, actionable half.
 *   remarks         OPS §41 "remarks"; the human-written half.
 *   evaluated_at    STOS-DB §19, "transaction/event records may additionally
 *                   include occurred_at; processed_at; completed_at."
 *   completed_by    THE ACCEPTANCE CRITERION. The ticket's entire stated AC is
 *   completed_at    "Checklist completion is time/user stamped." These two
 *                   columns are that sentence.
 *   created_by      STOS-DB §22: "Critical records must retain created by;
 *   updated_by      updated by; approved by; changed at."
 *
 * ── WHY is_critical IS STORED RATHER THAN READ FROM POLICY ────────────────
 * BRW-052 makes blocking depend on criticality, and criticality is configurable
 * (S6-004, CMP §20). If the flag were read live, relaxing a policy would silently
 * rewrite the meaning of every checklist already completed under the old one, and
 * a trip blocked on Monday would read passed on Tuesday with no record of why.
 *
 * Storing the flag the row was generated under makes the verdict reproducible.
 * It is the same discipline the eligibility verdict already uses when it records
 * the `required` flag it applied rather than the flag in force at read time.
 *
 * ── OVERRIDE COLUMNS: RESERVED, NOT ENFORCED ──────────────────────────────
 * OPS §31 states what an override must record: "reason; authority; time; risk;
 * audit." Three of those five become columns here; `risk` does not, because no
 * risk model exists (DB-011 trip_risks is CONTROLLED and unbuilt), and `audit` is
 * already the transport audit log.
 *
 * NOTHING IN THIS TICKET WRITES THEM. Override is P1 in all three places the
 * package raises it — RTM CMP-007, BRW-049 and PLN-007 — and ticket 009 deferred
 * PLN-007 on exactly this reasoning. The columns exist now so the table needs no
 * ALTER when that ticket lands, which is the same convention trip_assignments
 * used for allocation_override/override_reason and transport_trips used for
 * vehicle_id/driver_id. A test asserts they stay null.
 *
 * ── NO SOFT DELETES ───────────────────────────────────────────────────────
 * Step 5's mandatory conventions: "Soft delete — only for masters where
 * legal/business safe. Transactional records are reversed/cancelled, not
 * destroyed." A check is a transactional fact about a trip. When an assignment is
 * released the run is INVALIDATED — results reset to pending — not deleted, and
 * the audit log carries what it said before. Same choice as trip_assignments.
 *
 * ── WHAT IS DELIBERATELY ABSENT ───────────────────────────────────────────
 * category        Derivable from check_key via PretripCheckKey::CATEGORY_OF.
 *                 A stored copy could drift from the key it describes.
 * photo/evidence  FRS TRP-P0-005 requires photo evidence and UAT-004 lists
 *                 "Checklist + photos". Transport has NO file-upload capability
 *                 at all — transport_documents.file_path exists and has never
 *                 been populated by anything. Ruled out of scope (Q4); recorded
 *                 as D-22 rather than added as a column nothing can fill.
 * gps/location    OPS §41 lists GPS on milestone capture. Needs telematics —
 *                 SNG-TRN-020, P1. No location input exists.
 * signed_off_by   FRS TRP-P0-005's "Supervisor sign-off for critical failures"
 *                 IS the override: OPS §30 blocks on critical failure and §31
 *                 says only authorized roles may release it. It is therefore
 *                 covered by the reserved override columns rather than given a
 *                 second, competing mechanism.
 * run/attempt id  Regeneration is an upsert onto the unique key, so there is no
 *                 second run to identify. History lives in the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_pretrip_checks', function (Blueprint $table) {
            $table->id();

            // TEN-001 and STOS-DB §11. First column after the key, on every
            // transport table, and every query in the module scopes by it
            // explicitly — BelongsToTenant is opt-in, with no global scope.
            $table->unsignedBigInteger('tenant_id')->index();

            // A check with no trip is meaningless. STOS-DB §156/§157 names
            // exactly this class of orphan as a referential-integrity failure.
            $table->unsignedBigInteger('trip_id');

            // PretripCheckKey::ALL. 60 is comfortably above the longest declared
            // key (commercial.customer_requirement, 32).
            $table->string('check_key', 60);

            // BRW-052 — the policy snapshot. See the docblock.
            $table->boolean('is_critical')->default(false);

            // PretripResult. Generated rows start life pending.
            $table->string('result', 20)->default('pending');

            // BRW-048 — the exact reason, written by the evaluator.
            $table->string('detail', 500)->nullable();

            // OPS §41 — the human's note. TEXT, so no default (MySQL forbids one).
            $table->text('remarks')->nullable();

            // STOS-DB §19.
            $table->dateTime('evaluated_at')->nullable();

            // ── The acceptance criterion, in two columns ────────────────
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->dateTime('completed_at')->nullable();

            // ── RESERVED for CMP-007 / BRW-049 / OPS §31. Never written here.
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->dateTime('overridden_at')->nullable();
            $table->text('override_reason')->nullable();

            // STOS-DB §22.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            // No softDeletes — see the class docblock.

            // One row per check per trip, per tenant. This is what makes
            // regeneration idempotent and a derived status trustworthy.
            $table->unique(['tenant_id', 'trip_id', 'check_key'], 'trip_pretrip_trip_key_uniq');   // 26

            // The checklist lookup: every readiness computation starts here.
            $table->index(['tenant_id', 'trip_id'], 'trip_pretrip_tenant_trip_idx');               // 28

            // "Which trips are blocked, and on what" — IDX conventions in Step 11
            // are consistently company_id + status.
            $table->index(['tenant_id', 'result'], 'trip_pretrip_tenant_result_idx');              // 30
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_pretrip_checks');
    }
};
