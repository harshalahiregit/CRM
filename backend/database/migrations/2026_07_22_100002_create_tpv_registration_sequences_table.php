<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 · Item 5 — atomic per-tenant, per-year counter for the TPV Registration
 * Number. One row per (tenant, year); `last_number` is incremented under a row
 * lock so concurrent approvals never collide. Kept as a dedicated tiny table
 * rather than scanning MAX(registration_number), which is race-prone.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tpv_registration_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_registration_sequences');
    }
};
