<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STT-002's approval record — `viability_pending → approved`.
 *
 *   STT-002  viability_pending → approved | trigger "Approve viable trip"
 *            | actor ApprovalService | precondition "Margin policy passed"
 *            | side effect "Emit TripApproved" | audited | LOCKED
 *   PERM-003 Trip · approve · Owner Y · Operations Y · Accounts Y
 *            · Approver Y · Admin Y · DISPATCHER N · Driver N
 *            · Customer N · Supplier N
 *
 * Two columns, following `dispatched_at` / `dispatched_by` (migration 000011)
 * and `departed_at` / `departed_by` (000013). Approval is a time and a person,
 * and those are the two facts EVT-004's payload needs: `trip_id, approved_by`.
 *
 * ── WHY THERE IS NO approvals TABLE, AND NO approval_id ──────────────────
 * EVT-004's idempotency key is specified as `trip_id+approval_id`. **No
 * approvals table exists in Step 11's DB_Registry and no field registry entry
 * defines `approval_id`.** Rather than invent an entity to satisfy a key, the
 * approval is recorded on the trip itself — which is the precedent this table
 * already sets twice over for dispatch and departure.
 *
 * Nothing is substituted for the missing `approval_id`: not the audit row id,
 * not a generated uuid. A fabricated identifier would satisfy a consumer's
 * de-duplication while keying on something the registry never meant, and would
 * fail as a silently dropped event long after anyone remembers why. Recorded as
 * D-65.
 *
 * ── WHAT THIS MIGRATION DELIBERATELY DOES NOT ADD ────────────────────────
 * No `margin_pct`, no `viability_snapshot_id`, no `viability_decision`. STT-001's
 * side effect is "Create viability snapshot" and ENUM-008 defines
 * accept|negotiate|reject|review — but both belong to SNG-TRN-008, which is not
 * built and is blocked on an unassigned rate card (D-64). Columns with nothing
 * able to populate them are the D-9 mistake.
 *
 * `rejection_reason` is likewise absent: STT-003 is the next commit, not this
 * one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dateTime('approved_at')->nullable()->after('departed_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');

            // "What is waiting for my approval?" is the approver's only
            // question, and it is asked of trips that have none.
            $table->index(['tenant_id', 'approved_at'], 'transport_trips_tenant_approved_idx');   // 38
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropIndex('transport_trips_tenant_approved_idx');
            $table->dropColumn(['approved_at', 'approved_by']);
        });
    }
};
