<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · Feature 2 — Advanced Approval Workflow.
 *
 * One row per approval level for an onboarding. A row is pending until decided;
 * the chain finalises the onboarding at the last level (reusing the existing
 * approval + registration-number generation). Additive — the legacy single
 * approve/reject/hold flow is unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('onboarding_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('tpv_onboarding_id')->index();
            $table->unsignedInteger('level');
            $table->string('approver_role');
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->unsignedBigInteger('delegated_by')->nullable();
            $table->string('decision')->nullable();       // approve | reject | hold
            $table->text('remarks')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['tpv_onboarding_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_approvals');
    }
};
