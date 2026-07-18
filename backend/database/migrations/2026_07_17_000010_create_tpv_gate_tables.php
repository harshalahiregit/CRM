<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site gate: the scan log (every badge presented, admitted or refused) and daily
 * attendance (one row per worker per day).
 *
 * Scans of unknown tokens are not stored here — they can't be attributed to a
 * tenant, so they go to the tpv log channel instead.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tpv_gate_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id')->index();

            $table->string('decision')->index();        // Admit | Warn | Deny
            $table->json('reasons')->nullable();        // why, in human terms
            $table->string('action')->nullable();       // scan | check_in | check_out
            $table->string('gate')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('scanned_at');

            $table->timestamps();
            $table->index(['tenant_id', 'scanned_at']);
        });

        Schema::create('tpv_gate_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_worker_id')->index();

            $table->date('work_date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('check_in_gate')->nullable();
            $table->string('check_out_gate')->nullable();
            // Derived at check-out so the roster doesn't recompute per row.
            $table->unsignedInteger('minutes_on_site')->nullable();

            $table->timestamps();
            // One attendance row per worker per day.
            $table->unique(['tenant_id', 'tpv_worker_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_gate_attendances');
        Schema::dropIfExists('tpv_gate_scans');
    }
};
