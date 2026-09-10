<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispatch confirmation fields on `transport_trips` — FRS TRP-P0-006.
 *
 * ── NO TICKET; AUTHORISED DIRECTLY ────────────────────────────────────────
 * Step 12's register owns no dispatch ticket (D-18). The owner authorised this
 * bounded scope in writing on 2026-09-10. See DispatchScope::AUTHORIZATION.
 *
 * The requirement itself is not in doubt — RTM STOS-REQ-OPS-008 is P0 with the
 * acceptance "Dispatch timestamp/status recorded", and FRS TRP-P0-006 names the
 * fields exactly.
 *
 * ── IDX-004 FINALLY HAS A FIELD ───────────────────────────────────────────
 * Step 11 carries a LOCKED index:
 *
 *   IDX-004 | transport_trips | INDEX | company_id, planned_departure_at
 *           | non-null | "dispatch planning" | LOCKED
 *
 * SNG-TRN-007 recorded that as a defect, because the field registry defines no
 * such column. It was never a phantom — it is TRP-P0-006's ETD, and it belongs
 * to dispatch. Adding it here closes that gap and honours a LOCKED index spec
 * rather than inventing a column name.
 *
 * Note the index is declared WITHOUT the non-null part of IDX-004's rule. The
 * column must be nullable: a trip has no planned departure until it is
 * dispatched, and every trip created since SNG-TRN-007 has none. MySQL and
 * SQLite both index NULLs happily, so the lookup IDX-004 exists for
 * ("dispatch planning") still works.
 *
 * ── WHY THESE COLUMNS AND NOT A `trip_dispatches` TABLE ───────────────────
 * FRS TRP-P0-006 describes one confirmation per trip, not a history of them,
 * and SM-TRP has exactly one `dispatched` state. A child table would imply
 * many dispatches per trip and would be a second unregistered entity on top of
 * an already unticketed scope. The AMENDMENT history that TRP-P0-006 does ask
 * for ("changes create version") lives in the audit trail, which already stores
 * old_values/new_values per change — see DispatchService.
 *
 * ── WHAT IS DELIBERATELY ABSENT ───────────────────────────────────────────
 * TAT          TRP-P0-006 writes "ETA/TAT" as one field and defines TAT
 *              nowhere — ETD→ETA? ETD→return? gate-in→gate-out? A stored
 *              number nobody can interpret is the D-9 mistake
 *              (`allocation_type`), and it is not repeated. Derivable from
 *              planned_departure_at → planned_arrival_at.
 * approved_by  TRP-P0-006 wants "Change approval after release". No approval
 *              entity exists in Step 11 and every other approval in this
 *              package is P1. An amendment carries a reason, a version and an
 *              audit row, but no approver.
 * dispatch pack / notifications — no capability, and SNG-TRN-021 respectively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            // ── FRS TRP-P0-006's five fields ────────────────────────────
            // ETD. The column IDX-004 has been waiting for.
            $table->dateTime('planned_departure_at')->nullable()->after('route');
            // ETA. TAT is the interval between the two, not a column.
            $table->dateTime('planned_arrival_at')->nullable()->after('planned_departure_at');
            // "pickup contact" — a name and/or number, unstructured because the
            // FRS gives it no shape and no contact entity exists for a pickup.
            $table->string('pickup_contact', 190)->nullable()->after('planned_arrival_at');
            // "destination". Distinct from transport_orders.delivery_location:
            // this is what the dispatcher confirmed at release, which is the
            // thing TRP-P0-006 freezes.
            $table->string('dispatch_destination', 190)->nullable()->after('pickup_contact');
            $table->text('dispatch_instructions')->nullable()->after('dispatch_destination');

            // ── STOS-REQ-OPS-008, verbatim: "Dispatch timestamp/status
            //    recorded". The status is `status`; this is the timestamp.
            $table->dateTime('dispatched_at')->nullable()->after('dispatch_instructions');
            $table->unsignedBigInteger('dispatched_by')->nullable()->after('dispatched_at');

            // ── "Freeze key dispatch fields after release; changes create
            //    version". 0 before release, 1 at release, +1 per amendment.
            //    The values themselves are versioned in the audit trail.
            $table->unsignedInteger('dispatch_version')->default(0)->after('dispatched_by');

            // IDX-004 — LOCKED. Named explicitly so MySQL derives nothing over
            // its 64-character identifier limit.
            $table->index(['tenant_id', 'planned_departure_at'], 'transport_trips_tenant_etd_idx');   // 30
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropIndex('transport_trips_tenant_etd_idx');
            $table->dropColumn([
                'planned_departure_at', 'planned_arrival_at', 'pickup_contact',
                'dispatch_destination', 'dispatch_instructions',
                'dispatched_at', 'dispatched_by', 'dispatch_version',
            ]);
        });
    }
};
