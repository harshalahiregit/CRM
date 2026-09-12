<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-010 `trip_exceptions` — "Operational exception records", LOCKED.
 *
 * Step 11 gives the table its name, its tenancy and exactly two of its columns:
 *
 *   DB-010   trip_exceptions · Tenant-scoped · pk id · tenant key company_id
 *   FLD-014  severity  VARCHAR(20)  NOT NULL  DEFAULT 'medium'  INDEX
 *   FLD-015  status    VARCHAR(30)  NOT NULL  DEFAULT 'open'    INDEX
 *   IDX-008  INDEX (company_id, trip_id, severity, status)
 *
 * Every other column here comes from OPS §87, which lists the twelve fields an
 * exception "must contain", and from FRS TRP-P0-012, whose field list is
 * "Exception type; severity; cause; owner; due time". Both are named against
 * each column below, because a column with no requirement behind it is how a
 * schema starts drifting from the package.
 *
 * ── TENANCY IS THE FIRST COLUMN AND THE FIRST INDEX ──────────────────────
 * DB-010's tenant key is written `company_id`; this codebase calls it
 * `tenant_id` everywhere, and the module has used that name since SNG-TRN-001.
 * The name differs, the rule does not — the model carries BelongsToTenant and
 * every query composes forTenant() explicitly. IDX-008 is reproduced with
 * tenant_id in company_id's place, leading, so a tenant-scoped read of the
 * control room hits it.
 *
 * ── TWO OPS §87 FIELDS ARE DELIBERATELY ABSENT ───────────────────────────
 * `financial impact` and `customer impact` have no column. D-32: no formula
 * exists anywhere in the package and no cost model exists until SNG-TRN-012/018.
 * A column that can only ever be NULL is worse than no column — it invites
 * someone to fill it with a guess, and then a report to add it up.
 *
 * ── NO SOFT DELETES, ON PURPOSE ──────────────────────────────────────────
 * OPS §154: "An exception must interrupt the workflow only when necessary, but
 * it must never disappear from the system." There is no deleted_at and
 * ExceptionStatus has no `deleted` or `cancelled`. An exception that turned out
 * not to matter is resolved with a note, not erased.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_exceptions', function (Blueprint $table) {
            $table->id();

            // BelongsToTenant. First column, first index, never optional.
            $table->unsignedBigInteger('tenant_id')->index();

            // OPS §87 "exception ID". Allocated through the Document Numbering
            // Engine like every other reference in this module.
            $table->string('exception_number', 40);

            // OPS §87 "transaction"; TRP-P0-012 "link to Trip/Vehicle/Driver".
            // The trip is required — an exception with no transaction is an
            // incident, which is a different entity (see ExceptionScope).
            $table->unsignedBigInteger('trip_id');

            // The crew the trip was carrying when this was raised. Nullable
            // because a trip may have none, and denormalised because releasing
            // the crew later must not rewrite what the exception recorded.
            // Read-only references to Fleet's tables — never written by this module.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();

            // OPS §88's eight. No Step 11 enum exists for this (D-37).
            $table->string('category', 30);

            // FLD-014, verbatim: VARCHAR(20) NOT NULL DEFAULT 'medium'.
            $table->string('severity', 20)->default('medium');

            // FLD-015, verbatim: VARCHAR(30) NOT NULL DEFAULT 'open'.
            $table->string('status', 30)->default('open');

            // OPS §87 "source". Only 'manual' is reachable — all three automatic
            // rules need tickets that are not built (see AUTOMATIC_SOURCES).
            $table->string('source', 30)->default('manual');

            // TRP-P0-012 "cause". What actually happened, in words.
            $table->text('cause');

            // OPS §87 "owner". Nullable until someone is assigned: Q6 makes
            // assignment manual, and STT-015's precondition is "Owner assigned",
            // so an open exception legitimately has none yet.
            $table->unsignedBigInteger('owner_id')->nullable();

            // OPS §87 "timestamp".
            $table->unsignedBigInteger('raised_by')->nullable();
            $table->dateTime('raised_at');

            // OPS §87 "due time"; TRP-P0-012 "severity drives SLA".
            // Stored rather than derived because it is a commitment made at a
            // point in time — if a tenant later edits the policy, what was
            // promised on this exception must not silently move.
            $table->dateTime('due_at')->nullable();

            // The policy duration this due_at was computed from, kept so the
            // due time can be explained after the policy changes.
            $table->unsignedInteger('sla_minutes')->nullable();

            // STT-015: open → acknowledged, "Assign owner".
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->dateTime('acknowledged_at')->nullable();

            // STT-016: acknowledged → resolved. OPS §87 "resolution" + "closure".
            // The note is BR-P0-011's evidence; Q4 makes it note-only, because
            // photo and document need file upload Transport does not have (D-22).
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // BR-P0-001's shape, applied to this reference: unique within tenant.
            $table->unique(['tenant_id', 'exception_number'], 'trip_exc_tenant_number_uniq');      // 27

            // IDX-008, with tenant_id standing in for company_id.
            $table->index(['tenant_id', 'trip_id', 'severity', 'status'], 'trip_exc_idx_008');     // 17

            // The control room's two commonest reads: "what is open" and "what
            // is due soon". Neither is in Step 11; both are what §104's
            // On Track / At Risk / Overdue has to scan.
            $table->index(['tenant_id', 'status'], 'trip_exc_tenant_status_idx');                  // 28
            $table->index(['tenant_id', 'due_at'], 'trip_exc_tenant_due_idx');                     // 25

            // "Which exceptions are mine?" — the owner's queue.
            $table->index(['tenant_id', 'owner_id'], 'trip_exc_tenant_owner_idx');                 // 27
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_exceptions');
    }
};
