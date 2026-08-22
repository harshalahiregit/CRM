<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase MOM decision register (Sangoe TPV §9 parity) — the durable record of
 * decisions taken in a Purchase meeting, each standing Active until Superseded or
 * Rescinded. Purchase-owned mirror of meeting_decisions; never shares the
 * shared/TPV table. No DB foreign keys (row-level multi-tenancy, consistent with
 * the other purchase_mom_* tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_mom_decisions')) {
            return;
        }

        Schema::create('purchase_mom_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_kickoff_meeting_id')->index();
            $table->string('decision_ref', 32)->nullable();        // DEC-YYYY-NNNN
            $table->text('decision');
            $table->unsignedBigInteger('decided_by_participant_id')->nullable()->index();
            $table->string('decided_by_names', 300)->nullable();
            $table->text('impact')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('status', 20)->default('Active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Explicit short name — the generated one would exceed MySQL's 64-char
            // identifier limit. Guarded by MigrationIdentifierLengthTest.
            $table->index(['tenant_id', 'purchase_kickoff_meeting_id'], 'pmd_tenant_meeting_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_mom_decisions');
    }
};
