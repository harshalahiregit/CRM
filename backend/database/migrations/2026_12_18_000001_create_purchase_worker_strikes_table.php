<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety strikes ("punches") against a Purchase vendor's worker.
 *
 * TPV has had this since July; Purchase had no strikes engine at all — no
 * table, no model, no service — so the Strikes tab on a Purchase vendor simply
 * did not exist and a repeat safety offender on a Purchase crew could not be
 * recorded anywhere. The rule the two sides now share: three active strikes, or
 * one Critical, terminates site access.
 *
 * A mirror of `tpv_safety_strikes`, column for column. Only the register
 * differs, which is the standing rule for Purchase ← TPV parity — nothing is
 * redesigned on the way across, so the two ledgers stay comparable and the
 * services read the same.
 *
 * Strikes are VOIDABLE, never deletable: an appeal has to leave a trace, so the
 * row stays and records who voided it and why. Voiding stops the strike
 * counting; it does not restore access, because reinstating a terminated worker
 * is a deliberate separate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_worker_strikes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_worker_id')->index();
            $table->unsignedBigInteger('issued_by')->nullable()->index();

            $table->string('severity')->default('Minor');   // Minor | Major | Critical
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('location')->nullable();

            // Voiding (appeal upheld) — the row stays, the strike stops counting.
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason')->nullable();

            // True when this strike is what tipped the worker into termination.
            $table->boolean('triggered_termination')->default(false);

            $table->timestamps();

            // The query the ledger exists to answer: this worker's ACTIVE strikes.
            $table->index(['tenant_id', 'purchase_worker_id', 'voided_at'], 'pws_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_worker_strikes');
    }
};
