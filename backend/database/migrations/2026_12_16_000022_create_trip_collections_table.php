<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-013 `trip_collections` — "Collection tracking", CONTROLLED.  SNG-TRN-016.
 *
 * ── TRACKING, NOT CASH ───────────────────────────────────────────────────
 * SNG-TRN-016's acceptance criterion is four nouns — **"Due dates, blockers,
 * follow-up and audit trail"** — and not one of them is a ledger entry. This
 * table records what a customer owes against a trip, when it is due, why it is
 * stuck and who chased it. The money itself is posted by Accounts:
 *
 *   EVT-011  CollectionRecorded  Producer: Accounts/Collections
 *   CTR-014  API-011 amount_received ... note: "Posting event generated"
 *
 * That note is the handover. Transport records the receipt and emits the event;
 * Accounts turns it into a posting. FORBID-002 and LOCK-004 still hold — no
 * ledger line is ever written from this module.
 *
 * ── THE REGISTRY GIVES ONE FIELD AND ONE INDEX ───────────────────────────
 *   FLD-017  amount_due  DECIMAL(18,2)                      -> COL-001
 *   IDX-009  INDEX (company_id, due_date, status) "collections ageing"
 *
 * IDX-009 is the more useful of the two, because it names two columns the field
 * registry never declares — `due_date` and `status` — and therefore proves they
 * are meant to exist. Everything else is constructed against the acceptance
 * criterion, the discipline `trip_advances`, `trip_costs` and `trip_bills` all
 * used before it.
 *
 * COL-001 resolves through TRC-007 to `BR-COL-001` / `FRS-COL-001` — business
 * RULE references, not a vocabulary. So unlike CST-001 it is not quite a
 * dangling pointer; it simply points at documents in the reference-only tier
 * that define no enum. The status vocabulary is still constructed. See D-61.
 *
 * ── ONE RECEIVABLE PER TRIP ──────────────────────────────────────────────
 * Unique on (tenant_id, trip_id). `trip_bills` is already unique per trip and a
 * receivable follows an invoice, so a second row here would mean a second
 * invoice — which SNG-TRN-015 does not permit. Part payments are recorded by
 * moving `amount_received`, not by adding rows.
 *
 * ── BLOCKERS ARE A FIELD, NOT A STATUS ───────────────────────────────────
 * A blocked receivable is still outstanding, and "blocked" would hide how much
 * is owed. So `blocker_reason` is nullable alongside the amount status, and a
 * collection can truthfully be part-paid AND blocked — which is the case
 * somebody actually has to chase.
 *
 * ── THE AUDIT TRAIL IS THE SHARED ONE ────────────────────────────────────
 * The fourth noun needs no table of its own: RecordsTransportAudit gives this
 * model the same morph trail every other Transport record writes to, so a
 * follow-up, a blocker and a receipt all land in one timeline with the actor
 * and the reason. A private history table would split the story in two.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_collections')) {
            return;
        }

        Schema::create('trip_collections', function (Blueprint $table) {
            $table->id();

            // DB-013's tenant key is written `company_id`; this module has
            // called it `tenant_id` since SNG-TRN-001.
            $table->unsignedBigInteger('tenant_id')->index();

            $table->unsignedBigInteger('trip_id');

            // The invoice this receivable chases. Nullable for the same reason
            // trip_bills.invoice_id is: the bill may exist before Accounts has
            // raised anything, and this row is created from the bill.
            $table->unsignedBigInteger('bill_id')->nullable()->index();

            // FLD-017, verbatim.
            $table->decimal('amount_due', 18, 2);
            // CTR-014's `amount_received`, accumulated across part payments.
            $table->decimal('amount_received', 18, 2)->default(0);
            $table->char('currency', 3)->default('INR');

            // IDX-009 names both of these; the field registry declares neither.
            $table->date('due_date')->nullable();
            $table->string('status', 40)->default('pending');

            // "blockers" — why this receivable is not moving.
            $table->string('blocker_reason', 500)->nullable();
            $table->timestamp('blocked_at')->nullable();

            // "follow-up" — when somebody next intends to chase, and when they
            // last did. Both, because a receivable nobody has touched in three
            // weeks reads very differently from one chased yesterday.
            $table->date('next_follow_up_on')->nullable();
            $table->timestamp('last_followed_up_at')->nullable();
            $table->unsignedBigInteger('last_followed_up_by')->nullable();

            $table->timestamp('settled_at')->nullable();
            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // IDX-009, with tenant_id leading — the ageing report is the query
            // this table exists to serve.
            $table->index(['tenant_id', 'due_date', 'status'], 'trip_collections_ageing_idx');

            // "what do I chase today" — the other query anybody runs.
            $table->index(['tenant_id', 'next_follow_up_on'], 'trip_collections_followup_idx');

            $table->unique(['tenant_id', 'trip_id'], 'trip_collections_trip_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_collections');
    }
};
