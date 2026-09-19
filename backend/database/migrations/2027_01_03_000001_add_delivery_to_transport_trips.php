<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STT-007 — `in_transit → delivered`. RTM STOS-REQ-OPS-010 "Record delivery".
 *
 * Two columns, and that number is a decision.
 *
 * The owner's Q3 ruling set the precedent for what a manually recorded event on
 * this table looks like: "departed_at, departed_by columns only. Nothing beyond
 * that." Delivery follows the same shape, and everything else FRS TRP-P0-013
 * lists for a delivery — POD image, signature, geotag, shortage and damage
 * remarks — belongs to P3's POD capture, which has its own table
 * (`trip_documents`, DB-009) and its own permission (PERM-010).
 *
 * The state change is not the proof. Keeping them apart is what lets a driver
 * submit their POD without being able to declare the trip delivered, and lets
 * an operator confirm arrival without touching the document chain.
 *
 * ── NO `arrived_at`, BECAUSE THERE IS NO `arrived` ────────────────────────
 * Step 9 places ARRIVED between IN_TRANSIT and DELIVERED. No document gates it
 * and no requirement records an arrival distinct from a delivery — the RTM runs
 * OPS-008 dispatch → OPS-009 track → OPS-010 delivery with nothing in between.
 * Under the standing rule of 2026-09-17 (vocabulary from Step 9, edges from
 * Step 11) the state stays declared and unreachable, so a column for it would
 * be a field nothing can ever write. D-36, closed.
 *
 * `delivered_by` carries no foreign key, matching `dispatched_by`,
 * `departed_by` and `approved_by` already on this table: a user who leaves the
 * company must not make a delivered trip undeletable or unreadable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dateTime('delivered_at')->nullable()->after('departed_by');
            $table->unsignedBigInteger('delivered_by')->nullable()->after('delivered_at');

            // The mirror of transport_trips_tenant_departed_idx. "What was
            // delivered this week, and what is still out?" is one query over
            // two columns, and the control room asks it every morning.
            $table->index(['tenant_id', 'delivered_at'], 'transport_trips_tenant_delivered_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropIndex('transport_trips_tenant_delivered_idx');
            $table->dropColumn(['delivered_at', 'delivered_by']);
        });
    }
};
