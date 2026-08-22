<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase MOM action engine (Sangoe TPV §9 parity) — the register of action
 * items raised in a Purchase meeting's minutes: Meeting → Action → Owner → Due →
 * Evidence → Verification → Closure. Purchase-owned mirror of kickoff_mom_items;
 * never shares the shared/TPV table. No DB foreign keys (row-level multi-tenancy,
 * consistent with the other purchase_kickoff_* tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_mom_action_items')) {
            return;
        }

        Schema::create('purchase_mom_action_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_kickoff_meeting_id')->index();
            $table->string('action_ref', 32)->nullable();          // ACT-YYYY-NNNN
            $table->text('description');
            // Owner — Business Rule 11: every action has an owner.
            $table->unsignedBigInteger('responsible_participant_id')->nullable()->index();
            $table->string('responsible_names', 500)->nullable();
            $table->string('responsible_org', 160)->nullable();
            $table->string('status', 24)->default('Open');
            $table->string('priority', 20)->nullable();
            $table->date('target_date')->nullable();
            $table->text('remark')->nullable();
            $table->text('notes')->nullable();
            // Closure evidence — Business Rule 12: every closure requires evidence.
            $table->string('evidence_path')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'purchase_kickoff_meeting_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_mom_action_items');
    }
};
