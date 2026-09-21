<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STT-012 — `collection_pending → closed`. API-009, CTR-013, EVT-012.
 *
 * Three columns. Two of them are the same when/who pair every other milestone
 * on this table already carries; the third is required by contract.
 *
 *   CTR-013 | API-009 | closure_reason | body | TEXT | REQUIRED | non-empty |
 *             tenant scope | "Closure controls run first"
 *
 * `closure_reason` is the one field in this migration that is not a convention
 * — it is quoted. A trip can be closed for several different reasons (settled
 * in full, written off, superseded), and CTR-013 requires the person closing it
 * to say which. It is nullable in the SCHEMA because every existing row
 * predates it; it is mandatory in the CONTRACT, enforced by CloseTripRequest
 * and again by the service, so no trip can ever be closed without one.
 *
 * ── THESE COLUMNS ARE PLUMBED, NOT REACHABLE — D-106 ──────────────────────
 * `collection_pending` is the only state STT-012 leaves from and no user can
 * reach it: STT-010 (billable → billed) runs through TripBill::markInvoiced(),
 * which has no caller and no route. That is P3's surface.
 *
 * The columns ship anyway, with the service, the endpoint and the tests. The
 * day P3 adds one route this becomes live with no change here. This is exactly
 * the D-105 shape — schema without the work that writes it — and the only
 * reason it is acceptable this time is that it is written down, in the register,
 * in ClosureScope::REACHABLE = false, and in the coverage document, rather than
 * being left for the next reader to discover.
 *
 * No index. `closed` is terminal and the closed set only grows; the queries that
 * matter are over open trips, which `(tenant_id, status)` already serves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable()->after('delivered_by');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            $table->text('closure_reason')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closed_by', 'closure_reason']);
        });
    }
};
