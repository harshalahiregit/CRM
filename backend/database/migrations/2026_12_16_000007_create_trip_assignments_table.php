<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-003 `trip_assignments` — "Vehicle/driver assignment history". LOCKED.
 *
 * SNG-TRN-009 step 4. STOS-DB §46 calls this "a critical business-control
 * entity", and the reason is §46's second half: "Do not permanently embed
 * driver_id inside vehicle as the only allocation mechanism." Who drove what,
 * when, and on whose authority is a history, not a pair of current-value columns.
 *
 * ── NAME ──────────────────────────────────────────────────────────────────
 * Step 11 says `trip_assignments`; STOS-DB §46 says `vehicle_driver_allocations`.
 * Step 11 wins on authority and the name is already specific enough not to need
 * the transport_ prefix the masters were given. Ruled 2026-09-07.
 *
 * ── REGISTRY DEFECTS THIS TABLE SITS ON ───────────────────────────────────
 * D-3  Step 11 specifies ZERO columns and ZERO indexes for DB-003. Every column
 *      below is reference tier: STOS-DB §47 (history fields), §48 (override),
 *      LSM §44 (lifecycle), OPS §123 (transfer), SEC §103/§104.
 * D-9  NEW. STOS-DB §47 lists "allocation type" as a field. That phrase occurs
 *      exactly once in all 42 documents and is never defined — no values, no
 *      examples. The column exists so the table need not be altered later; no
 *      enum is invented. Same treatment as transport_documents.source.
 *
 * ── FIELD TRACE (§47: vehicle; driver; start; end; reason; allocation type;
 *                      approved by; override flag) ────────────────────────
 *   vehicle          → vehicle_id
 *   driver           → driver_id
 *   start            → assigned_at    (factual; see below)
 *   end              → released_at
 *   reason           → reason
 *   allocation type  → allocation_type   (D-9, no enum)
 *   approved by      → approved_by
 *   override flag    → allocation_override + override_reason  (§48 names both
 *                      verbatim: "allocation_override = true" and
 *                      "override_reason")
 *
 * start/end are FACTUAL timestamps, not a planned window. transport_trips has no
 * date column at all — planned_departure_at was deliberately omitted because
 * Step 11's IDX-004 indexes a field no field registry defines — so a planned
 * range could not be populated by anything. Overlap is therefore detected by
 * STATUS, which is also how STOS-TEST §33 frames it ("assigned elsewhere") and
 * how TRP-P0-003's acceptance frames it ("No overlapping ACTIVE allocation").
 * Ruled 2026-09-07.
 *
 * ── NO SOFT DELETES, DELIBERATELY ─────────────────────────────────────────
 * Every other transport table has them. Step 5's mandatory conventions say:
 * "Soft delete — only for masters where legal/business safe. Transactional
 * records are reversed/cancelled, not destroyed." An assignment is a
 * transactional fact; it is RELEASED, never deleted. Removing the row would
 * destroy exactly the history §46 exists to keep and SEC §103 lists as a fraud
 * control ("vehicle assignment history; driver allocation history").
 *
 * ── WHAT IS DELIBERATELY ABSENT ───────────────────────────────────────────
 * location         OPS §123 lists it among transfer fields. Transfer is a
 *                  mid-execution workflow no P0 ticket owns; previous_assignment_id
 *                  and approved_by already carry §123's structural half (old→new
 *                  link, authorizer). Recorded rather than added empty.
 * assignment score  BRW-041/PLN-008 — P1, and AllocationScope excludes scoring.
 * eligibility snapshot  The checks that passed at assignment time are written to
 *                  the audit trail by the service, not stored here — SNG-TRN-008
 *                  owns snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            // An assignment with no trip is meaningless. Same reasoning as
            // CTR-004's "cannot create orphan trip", one level down.
            $table->unsignedBigInteger('trip_id');

            // BOTH nullable, and that is the point. CTR-007/008 mark vehicle_id
            // and driver_id required on API-004, but FRS TRP-P0-004's trigger is
            // "Vehicle allocated" — i.e. the driver is chosen after the vehicle.
            // Nullable columns satisfy both: one call may set both, or a vehicle
            // may be assigned first and the driver added to the same row later.
            // The service refuses a row with neither. Ruled 2026-09-07.
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();

            // LSM §44. Defaults to the state this ticket creates.
            $table->string('status', 30)->default('assigned');

            // §47 start/end.
            $table->dateTime('assigned_at');
            $table->dateTime('released_at')->nullable();

            // §47 reason / allocation type / approved by.
            $table->string('reason', 500)->nullable();
            $table->string('allocation_type', 30)->nullable();   // D-9 — no canonical values
            $table->unsignedBigInteger('approved_by')->nullable();

            // §48, verbatim field names. RESERVED, NOT ENFORCED: manual override
            // is PLN-007, which RTM §17 marks P1 and AllocationScope defers. The
            // columns exist now so the table needs no ALTER when that ticket
            // lands — the same convention transport_trips used for vehicle_id and
            // driver_id. Nothing in this ticket writes a true value.
            $table->boolean('allocation_override')->default(false);
            $table->text('override_reason')->nullable();

            // OPS §122-123 — "Trip may be transferred to another driver; vehicle;
            // route. The transfer must preserve complete history. Record: old
            // assignment; new assignment..." This is the old→new link. Nothing
            // writes it in this ticket; it exists so a transfer never has to
            // rewrite history to record itself.
            $table->unsignedBigInteger('previous_assignment_id')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            // No softDeletes — see the class docblock.

            // ── BR-P0-003 enforced in the DATABASE, not only in PHP ─────
            //
            // "Vehicle cannot have overlapping active trips." Hard. Critical.
            // STOS-DB §198/§199 repeat it for vehicle and driver.
            //
            // The service takes a row lock before inserting (STOS-DB §192
            // requires a transaction around exactly this sequence), but a lock
            // only protects callers that remember to take it. These indexes are
            // the backstop that holds for a direct INSERT, a future second
            // service, or two dispatchers racing on separate connections.
            //
            // MySQL 8 has no partial/filtered unique index — `UNIQUE ... WHERE`
            // is a syntax error, verified against this server. The portable
            // equivalent is a STORED generated column that is NULL unless the row
            // is active: both MySQL and SQLite permit unlimited NULLs in a unique
            // index, so released rows accumulate freely while at most one active
            // row can exist per vehicle, per driver, per tenant.
            //
            // CASE WHEN rather than MySQL's IF(): IF() does not exist in SQLite,
            // and the test suite runs on SQLite. This codebase has already been
            // bitten by CAST(... AS INTEGER) working on one driver and not the
            // other, so the expression is deliberately ANSI. Declared inside
            // CREATE TABLE because SQLite cannot ALTER in a STORED column.
            $active = "CASE WHEN status IN ('assigned','confirmed','active') THEN %s ELSE NULL END";

            $table->unsignedBigInteger('active_vehicle_id')->nullable()->storedAs(sprintf($active, 'vehicle_id'));
            $table->unsignedBigInteger('active_driver_id')->nullable()->storedAs(sprintf($active, 'driver_id'));
            // One active assignment per trip. This is what makes sequential
            // assignment an UPDATE to the existing row rather than a second row,
            // and it matches §47's shape: one history record per allocation,
            // carrying both vehicle and driver.
            $table->unsignedBigInteger('active_trip_id')->nullable()->storedAs(sprintf($active, 'trip_id'));

            $table->index(['tenant_id', 'trip_id'], 'trip_assign_tenant_trip_idx');       // 27
            $table->index(['tenant_id', 'status'], 'trip_assign_tenant_status_idx');      // 29
            $table->index(['tenant_id', 'vehicle_id'], 'trip_assign_tenant_vehicle_idx'); // 30
            $table->index(['tenant_id', 'driver_id'], 'trip_assign_tenant_driver_idx');   // 29

            $table->unique(['tenant_id', 'active_vehicle_id'], 'trip_assign_active_vehicle_uniq'); // 31
            $table->unique(['tenant_id', 'active_driver_id'], 'trip_assign_active_driver_uniq');   // 30
            $table->unique(['tenant_id', 'active_trip_id'], 'trip_assign_active_trip_uniq');       // 28
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_assignments');
    }
};
