<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safety strikes ("punches") against a worker. Three active strikes — or one
 * Critical — terminates site access automatically.
 *
 * Strikes are voidable rather than deletable: an appeal must leave a trace, so
 * the ledger keeps the row and records who voided it and why.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tpv_safety_strikes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id')->index();
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
            $table->index(['tenant_id', 'tpv_worker_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_safety_strikes');
    }
};
