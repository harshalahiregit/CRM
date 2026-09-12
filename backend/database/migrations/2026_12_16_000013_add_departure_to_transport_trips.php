<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STT-006's departure record — `dispatched → in_transit`.
 *
 *   STT-006  dispatched → in_transit | trigger "Dispatch vehicle"
 *            | actor TripEngine | precondition "Dispatch confirmed"
 *            | side effect "Start monitoring" | LOCKED
 *   SM-TRP   in_transit · active · entry gate "Departure recorded"
 *            · exit "Delivered/Exception" · Operations · LOCKED
 *
 * SM-TRP's entry gate is the whole of this migration: "Departure recorded" is
 * a time and a person, and those are the two columns.
 *
 * ── EXACTLY TWO COLUMNS, BY RULING ───────────────────────────────────────
 * Owner's Q3, verbatim: "departed_at, departed_by columns only. Nothing beyond
 * that — no in_transit → delivered, no GPS/telemetry/odometer/temperature, no
 * automatic triggers."
 *
 * That matters because OPS §38 ("TRIP START") asks for rather more: timestamp,
 * location, GPS, odometer, vehicle, driver, container, temperature, Genset.
 * Every one of those beyond the timestamp needs a data model this module does
 * not have — GPS is SNG-TRN-020 (P1), temperature and Genset have no entity at
 * all, and odometer belongs with the fuel maths in SNG-TRN-012. Adding nullable
 * columns for them now would be the D-9 mistake: fields with no defined values
 * and nothing able to populate them.
 *
 * Recorded in ExceptionScope::EXCLUDED rather than silently dropped.
 *
 * ── WHY THIS IS NOT PART OF THE DISPATCH MIGRATION ───────────────────────
 * `dispatched_at` (migration ...000011) is when the trip was RELEASED;
 * `departed_at` is when it actually LEFT. Two different facts, minutes or hours
 * apart, and the gap between them is exactly what an operational delay
 * exception is about. Collapsing them would make that gap unmeasurable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dateTime('departed_at')->nullable()->after('dispatch_version');
            $table->unsignedBigInteger('departed_by')->nullable()->after('departed_at');

            // "Which trips are on the road right now?" is the control room's
            // first question, and in_transit is the state it asks about.
            $table->index(['tenant_id', 'departed_at'], 'transport_trips_tenant_departed_idx');   // 38
        });
    }

    public function down(): void
    {
        Schema::table('transport_trips', function (Blueprint $table) {
            $table->dropIndex('transport_trips_tenant_departed_idx');
            $table->dropColumn(['departed_at', 'departed_by']);
        });
    }
};
