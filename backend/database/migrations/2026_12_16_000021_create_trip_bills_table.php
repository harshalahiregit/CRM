<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-012 `trip_bills` — "Customer billing linkage", LOCKED.  SNG-TRN-015.
 *
 * ── THIS IS A LINKAGE ROW, NOT AN INVOICE ────────────────────────────────
 * The distinction is the entire ticket. Step 11's Owner column for DB-012 reads
 * **Accounts**, and the event registry is unambiguous about who does what:
 *
 *   EVT-010  InvoicePosted       Producer: Accounts
 *   EVT-011  CollectionRecorded  Producer: Accounts/Collections
 *
 * Transport does not post invoices and does not take money. FORBID-002 and
 * LOCK-004 say so directly — no ledger line is ever written from this module,
 * accounting reaches the books through PostingService and nowhere else.
 *
 * What Transport CAN say is "this trip is ready to be billed, and here is what
 * it is worth". That is SNG-TRN-015's name — *Billing trigger* — and its whole
 * acceptance criterion: "No billing without defined preconditions."
 *
 * ── WHY `invoice_id` IS NULLABLE, AND WHY THAT IS THE SEAM ───────────────
 * FLD-016 is the ONLY field row Step 11 gives this table:
 *
 *   FLD-016 | DB-012 | trip_bills | invoice_id | BIGINT | nullable | FK+INDEX | BIL-001
 *
 * A nullable foreign key to an invoice only makes sense if the row can exist
 * **before the invoice does**. That is the handover written into the schema:
 * Transport creates the row when the trip becomes billable, with invoice_id
 * NULL; Accounts fills it in when it posts the invoice and emits EVT-010.
 *
 * So this migration builds Transport's half and stops. Nothing here creates,
 * numbers or posts an invoice, and `invoice_id` carries no FK CONSTRAINT —
 * only the index FLD-016 asks for — because the invoices table belongs to a
 * module that may not even be installed in a Transport-only deployment, and a
 * hard constraint would make this migration fail there.
 *
 * ── EVERY OTHER COLUMN IS CONSTRUCTED ────────────────────────────────────
 * One field row for the whole table, so the rest are named against the
 * requirement that asks for them — the discipline `trip_advances` and
 * `trip_costs` both used.
 *
 *   trip_id            DB-012 is "trip_bills"; the linkage needs both ends
 *   billable_amount    "here is what it is worth" — the freight agreed on the
 *                      trip, frozen at the moment billing was prepared, so a
 *                      later amendment to the trip cannot silently restate an
 *                      invoice Accounts has already raised
 *   status             prepared | invoiced — see TripBillStatus
 *   prepared_by/_at    STT-009 is audited: Yes
 *   basis              WHY it was billable — a verified POD, or the exception
 *                      that waived one. Without it, "unless approved exception"
 *                      leaves no trace of which arm let the money through.
 *
 * ── ONE BILL PER TRIP ────────────────────────────────────────────────────
 * Unique on (tenant_id, trip_id). A trip is billed once; preparing twice is a
 * duplicate callback or a double-click, and the service absorbs it rather than
 * creating a second linkage that would invite a second invoice. Part-billing a
 * trip is not in any ticket, and a column that can only ever hold one value
 * invites somebody to fill it with a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_bills')) {
            return;
        }

        Schema::create('trip_bills', function (Blueprint $table) {
            $table->id();

            // DB-012's tenant key is written `company_id`; this module has
            // called it `tenant_id` since SNG-TRN-001.
            $table->unsignedBigInteger('tenant_id')->index();

            $table->unsignedBigInteger('trip_id');

            // FLD-016 — nullable, indexed, NO foreign-key constraint. See above.
            $table->unsignedBigInteger('invoice_id')->nullable()->index();

            $table->decimal('billable_amount', 18, 2)->nullable();
            $table->char('currency', 3)->default('INR');

            $table->string('status', 40)->default('prepared');

            // Which arm of "POD required unless approved exception" let it pass.
            $table->string('basis', 40)->nullable();

            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->unsignedBigInteger('invoiced_by')->nullable();
            $table->timestamp('invoiced_at')->nullable();

            $table->string('notes', 500)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A trip is billed once.
            $table->unique(['tenant_id', 'trip_id'], 'trip_bills_trip_unique');

            // "Which trips are billable but not yet invoiced" is the question
            // Accounts will ask on a schedule, so it gets an index.
            $table->index(['tenant_id', 'status'], 'trip_bills_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_bills');
    }
};
