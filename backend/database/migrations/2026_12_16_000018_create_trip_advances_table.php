<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-007 `trip_advances` — "Driver/supplier trip advances", LOCKED.
 *
 * Step 11 gives the table its name, its tenancy, one column and one index:
 *
 *   DB-007   trip_advances · Tenant-scoped · pk id · tenant key company_id
 *            Owner: Finance Control · FRS-PAY
 *   FLD-012  status  VARCHAR(40)  NOT NULL  DEFAULT 'requested'  INDEX
 *   IDX-006  INDEX (company_id, trip_id, status) — "advance controls"
 *
 * Everything else is named against the requirement that asks for it, because a
 * column with no requirement behind it is how a schema drifts from the package.
 *
 *   trip_id                  Step 9 domain object 9: an advance relates to
 *                            "Trip, driver/supplier, payment"
 *   driver_id / supplier_id  DB-007's own description, "Driver/supplier"
 *   amount_requested         TRP-P0-007 "Requested amount"
 *   amount_approved          TRP-P0-007 "System shows permitted amount" — the
 *                            approver may grant less than was asked for, which
 *                            is the entire point of a policy limit
 *   purpose                  TRP-P0-007 "purpose"
 *   payment_method           TRP-P0-007 "method"
 *   decided_by / decided_at / decision_reason
 *                            BR-P0-005's override path and TRP-P0-007's
 *                            "reason for exception"
 *
 * ── TENANCY IS THE FIRST COLUMN AND THE FIRST INDEX ──────────────────────
 * DB-007's tenant key is written `company_id`; this module has called it
 * `tenant_id` since SNG-TRN-001. The name differs, the rule does not — the model
 * carries BelongsToTenant and every query composes forTenant() explicitly.
 * IDX-006 is reproduced with tenant_id leading, so the exposure query that
 * BR-P0-005 runs on every request hits it.
 *
 * ── NO PAYMENT COLUMNS, ON PURPOSE ───────────────────────────────────────
 * There is no `paid_at`, no bank reference, no UTR. Those belong to TRP-P0-008
 * "Advance payment", which SNG-TRN-011 does not cover — this ticket is "Advance
 * request and approval". Following the rule the exceptions table set: a column
 * that can only ever be NULL invites somebody to fill it with a guess, and then
 * a report to add it up.
 *
 * ── MONEY ────────────────────────────────────────────────────────────────
 * DECIMAL(18,2) matching FLD-008's approved_freight, never a float. FIN-06 in
 * Step 13 blocks release on float drift, and an advance is compared against the
 * freight it is drawn from, so the two must be the same type or the comparison
 * is wrong at the edges.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('trip_advances')) {
            return;
        }

        Schema::create('trip_advances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->unsignedBigInteger('trip_id');

            // One of the two is set, never both — enforced in the service rather
            // than by a CHECK constraint, because SQLite and MySQL disagree on
            // how those are declared and the rule needs a message a user can act
            // on, not a driver-level error.
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();

            $table->decimal('amount_requested', 18, 2);
            $table->decimal('amount_approved', 18, 2)->nullable();
            $table->char('currency', 3)->default('INR');

            $table->string('purpose', 500)->nullable();
            $table->string('payment_method', 40)->nullable();

            // FLD-012, verbatim.
            $table->string('status', 40)->default('requested');

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();

            // BR-P0-005 permits an authorised override of the exposure cap. The
            // fact that one was used has to survive the request that used it,
            // or "who let this through" has no answer.
            $table->boolean('limit_overridden')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // IDX-006.
            $table->index(['tenant_id', 'trip_id', 'status'], 'trip_advances_controls_idx');
            // FLD-012 asks for status to be indexed in its own right — the
            // approval queue reads it across trips, not within one.
            $table->index(['tenant_id', 'status'], 'trip_advances_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_advances');
    }
};
