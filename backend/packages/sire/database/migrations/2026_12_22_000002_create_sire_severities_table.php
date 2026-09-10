<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — per-tenant severity, modelled column-for-column on ticket_priorities.
 *
 * `level` is the sort and comparison key, never `code`: a workspace may call its
 * top band Critical, Sev 1 or Blocker, and the UI derives urgency from position in
 * the scale rather than from the name.
 *
 * SLA targets live here and are never copied onto an issue — SLA state is computed
 * at read time so that editing a target changes what it means everywhere at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_severities')) {
            return;
        }

        Schema::create('sire_severities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->string('name', 60);
            $table->string('code', 32);
            $table->unsignedTinyInteger('level');       // 1 = lowest
            $table->string('color', 16)->nullable();

            $table->integer('ack_target_minutes')->nullable();
            $table->integer('triage_target_minutes')->nullable();
            $table->integer('resolve_target_minutes')->nullable();

            $table->boolean('requires_closure_approval')->default(false);
            $table->boolean('auto_escalate')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'code'], 'sire_sev_tenant_code_uq');
            $table->index(['tenant_id', 'level'], 'sire_sev_tenant_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_severities');
    }
};
