<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — recurrence groups.
 *
 * RECURRING IS NOT DUPLICATE, and conflating them is the most common way a defect
 * register loses its meaning:
 *
 *   duplicate  the SAME occurrence reported twice. One is real work; the other is
 *              closed with a pointer and no separate investigation.
 *   recurrence the same defect happening AGAIN, later. Every occurrence is real,
 *              separate work — and the fact that there are five of them is the
 *              most important thing the register knows.
 *
 * A duplicate therefore cannot be a member of a recurrence group; it is not an
 * occurrence. That rule is enforced in SireRecurrenceService, not just documented.
 *
 * The statistics are DERIVED and recomputed, never hand-entered — a count someone
 * can type is a count that will be wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_recurrence_groups')) {
            return;
        }

        Schema::create('sire_recurrence_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->string('reference', 32);            // SIRE-REC-0001
            $table->string('title', 255);
            $table->text('description')->nullable();

            // Deterministic grouping hint: module|section|screen|type. Used to
            // SUGGEST membership, never to assign it. No fuzzy matching, no AI.
            $table->string('signature', 191)->nullable();

            $table->unsignedInteger('occurrence_count')->default(0);
            $table->dateTime('first_occurrence_at')->nullable();
            $table->dateTime('latest_occurrence_at')->nullable();
            $table->decimal('average_interval_days', 8, 2)->nullable();

            $table->unsignedBigInteger('root_cause_id')->nullable();

            // none | planned | in_progress | shipped | verified
            $table->string('permanent_fix_status', 24)->default('none');
            $table->unsignedBigInteger('permanent_fix_release_id')->nullable();

            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('recurrence_risk', 16)->default('low'); // low | medium | high | critical
            $table->dateTime('risk_computed_at')->nullable();

            $table->boolean('is_closed')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'reference'], 'sire_recg_tenant_ref_uq');
            $table->index(['tenant_id', 'recurrence_risk'], 'sire_recg_tenant_risk_idx');
            $table->index(['tenant_id', 'signature'], 'sire_recg_tenant_sig_idx');
            $table->index(['tenant_id', 'is_closed'], 'sire_recg_tenant_closed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_recurrence_groups');
    }
};
