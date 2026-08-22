<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase MOM issue register (Sangoe TPV §9 parity) — issues raised in a
 * Purchase meeting's minutes, each trackable to resolution and convertible to an
 * NCR or a CAPA. Purchase-owned mirror of meeting_issues; never shares the
 * shared/TPV table. No DB foreign keys (row-level multi-tenancy, consistent with
 * the other purchase_kickoff_* / purchase_mom_* tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_mom_issues')) {
            return;
        }

        Schema::create('purchase_mom_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_kickoff_meeting_id')->index();
            $table->string('issue_ref', 32)->nullable();           // ISS-YYYY-NNNN
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category', 60)->nullable();
            $table->string('severity', 20)->nullable();
            $table->unsignedBigInteger('owner_participant_id')->nullable()->index();
            $table->string('owner_names', 300)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 24)->default('Open');
            // Escalation link — an issue converted to an NCR / CAPA.
            $table->string('converted_to', 24)->nullable();        // NCR | CAPA
            $table->string('converted_ref', 64)->nullable();
            $table->unsignedBigInteger('converted_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Explicit short name: the generated one (…tenant_id_purchase_kickoff_meeting_id_index)
            // sits 1 char under MySQL's 64-char limit — too close to trust. Same
            // convention as the action-items table. Guarded by MigrationIdentifierLengthTest.
            $table->index(['tenant_id', 'purchase_kickoff_meeting_id'], 'pmi_tenant_meeting_idx');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_mom_issues');
    }
};
