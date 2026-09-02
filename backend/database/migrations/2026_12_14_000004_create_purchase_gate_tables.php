<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase ← TPV parity: the site gate.
 *
 * Purchase could compute a gate DECISION for a worker (PurchaseWorkforceService::
 * gateDecision) but had nowhere to record that the decision was ever made. So
 * there was no gate log, no attendance, and no way to answer "who was on site
 * on the 3rd?" — the question a gate exists to answer.
 *
 * Two tables, mirroring tpv_gate_scans and tpv_gate_events:
 *
 *  purchase_gate_scans  — one row per badge scan of a PERSON. The decision and
 *                         the reasons behind it are stored, not re-derived: a
 *                         worker admitted last week under rules that have since
 *                         changed was still admitted, and the log must say so.
 *
 *  purchase_gate_events — everything else crossing the gate (equipment,
 *                         material, vehicle, visitor), which has no badge and no
 *                         readiness to check, only a direction and a reference.
 *
 * Scans are deliberately NOT soft-deleted: a gate log that can be quietly
 * removed is not evidence. Events are, because a mistyped delivery is a
 * correction rather than a thing that happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_gate_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('purchase_worker_id')->index();

            // What the gate decided, and why. `reasons` is the blocker list the
            // decision was made from — kept so a refusal can be explained to the
            // person standing at the barrier.
            $table->string('decision', 20);              // allow | deny
            $table->text('reasons')->nullable();
            $table->string('action', 20)->nullable();    // in | out

            $table->string('gate', 80)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            // The attendance query: one worker's crossings, newest first.
            // Named explicitly: the generated name is 65 characters, one over
            // MySQL's 64-character limit, so `migrate` aborts on production
            // while every SQLite test passes.
            $table->index(['tenant_id', 'purchase_worker_id', 'scanned_at'], 'pgs_tenant_worker_scanned_idx');
            // The gate log: everything at a site on a day.
            $table->index(['tenant_id', 'scanned_at']);
        });

        Schema::create('purchase_gate_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();

            $table->string('event_kind', 30);            // equipment|material|vehicle|visitor|person
            $table->string('direction', 10);             // in | out
            $table->string('label', 190)->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit', 30)->nullable();

            $table->string('project', 150)->nullable();
            $table->string('location', 150)->nullable();
            $table->string('gate', 80)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->text('details')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'event_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_gate_events');
        Schema::dropIfExists('purchase_gate_scans');
    }
};
