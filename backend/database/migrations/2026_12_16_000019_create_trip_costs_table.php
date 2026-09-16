<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-006 `trip_costs` — "Canonical trip cost facts", LOCKED.  SNG-TRN-012.
 *
 * Step 11 gives the table its name, its tenancy, two columns and one index:
 *
 *   DB-006   trip_costs · Tenant-scoped · pk id · tenant key company_id
 *            Owner: Finance Control · FRS-CST
 *   FLD-010  cost_type  VARCHAR(40)   INDEX          -> CST-001
 *   FLD-011  amount     DECIMAL(18,2)                -> MON-002
 *   IDX-005  INDEX (company_id, trip_id, cost_type) — "profitability calculations"
 *
 * ── WHAT THE TICKET CITES IS WRONG, AND IT IS RECORDED ───────────────────
 * SNG-TRN-012's ref column reads `DB-011;API-007;EV-008`. Resolved by NAME
 * against Step 11, all three belong to the EXCEPTION domain: DB-011 is
 * `trip_risks`, API-007 is `POST .../exceptions`, EVT-008 is
 * `TripExceptionRaised`. The correct table is DB-006, and Step 11 has NO api
 * row and NO event for recording a cost at all. See D-58.
 *
 * ── CST-001 AND MON-002 ARE DANGLING POINTERS ────────────────────────────
 * Both appear exactly once across all fourteen sheets — in their own citation.
 * Neither is defined. The Enums sheet holds ENUM-001..008 and none is a cost
 * type. So `cost_type` is stored as the VARCHAR(40) the registry specifies and
 * NOT as an invented enum: inventing the vocabulary would be FORBID-001.
 *
 * Free text alone would wreck SNG-TRN-018, whose acceptance is "revenue, cost
 * and margin reconcile to source transactions" and which groups on this very
 * column via IDX-005. `Fuel`, `FUEL` and ` fuel ` would be three categories.
 * TripCostService::normaliseType() therefore folds case and whitespace on
 * write — the same treatment TransportContainer::normalise() gives a free-text
 * identity column. Nothing is rejected; the vocabulary stays open.
 *
 * ── `source`, AND WHY IT IS NOT AN INVENTED FIELD ────────────────────────
 * SNG-TRN-012's acceptance criterion is "Every cost is linked to trip and
 * SOURCE", and SNG-TRN-018's is "reconcile to SOURCE TRANSACTIONS". Neither
 * has a field in the registry, so `source` and `source_ref` are named against
 * those two sentences rather than guessed: source is which system produced the
 * fact, source_ref is the transaction inside it.
 *
 * ── DUPLICATES — QA-005 ──────────────────────────────────────────────────
 * QA-005 says "Duplicate expense submission · Duplicate detected/handled per
 * rule" and no rule is given anywhere. The rule this ticket sets:
 *
 *   A source transaction may produce at most ONE cost row per cost type.
 *
 * enforced by the unique index below. Both SQLite and MySQL treat NULLs as
 * distinct in a unique index, so hand-entered rows (source_ref NULL) are
 * unaffected and may legitimately repeat — those are caught in the service by
 * a same-trip/type/amount/date look-alike check that the caller must confirm
 * past. A double-counted cost is a wrong margin, and the margin is the point.
 *
 * ── NO APPROVAL COLUMNS, ON PURPOSE ──────────────────────────────────────
 * There is no approval_status here. That column is FLD-013 and it belongs to
 * DB-008 `trip_expenses` — a SEPARATE LOCKED table with its own permissions
 * (PERM-008 submit, PERM-009 approve) and its own enum (ENUM-005). A cost is
 * the canonical fact; an expense is a claim someone submits. Collapsing the
 * two into one table is the duplicate-canonical-entity failure FORBID-005
 * names. The boundary is un-ruled — D-58, ARCHITECTURE_REVIEW_REQUIRED — so
 * this migration builds only the table its own ticket names.
 *
 * ── MONEY ────────────────────────────────────────────────────────────────
 * DECIMAL(18,2) per FLD-011, never a float, and every sum goes through bcmath.
 * Step 13's FIN-06 blocks release on float drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_costs')) {
            return;
        }

        Schema::create('trip_costs', function (Blueprint $table) {
            $table->id();

            // DB-006's tenant key is written `company_id`; this module has
            // called it `tenant_id` since SNG-TRN-001. The name differs, the
            // rule does not — BelongsToTenant plus an explicit forTenant().
            $table->unsignedBigInteger('tenant_id')->index();

            $table->unsignedBigInteger('trip_id');

            // FLD-010, verbatim. Normalised on write, not constrained.
            $table->string('cost_type', 40);

            // FLD-011, verbatim.
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('INR');

            // Named against the acceptance criteria — see the header.
            $table->string('source', 40);
            $table->string('source_ref', 100)->nullable();

            // "Boundary dates" is a listed edge case for this ticket, and a
            // cost belongs to the day it was incurred rather than the day
            // somebody got round to typing it in.
            $table->date('incurred_on')->nullable();

            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // IDX-005, with tenant_id leading so SNG-TRN-018's grouping query
            // hits it.
            $table->index(['tenant_id', 'trip_id', 'cost_type'], 'trip_costs_profitability_idx');

            // The QA-005 rule. NULL source_ref rows are exempt by design.
            $table->unique(['tenant_id', 'source', 'source_ref', 'cost_type'], 'trip_costs_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_costs');
    }
};
