<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase central approval register (Sangoe TPV §12 parity) — a generic,
 * additive register of governance approvals across ~18 types. Purchase-owned
 * mirror of tpv_approvals; never shares the shared/TPV table.
 *
 * Deliberately named purchase_approval_requests, NOT purchase_approvals — the
 * latter already exists for the onboarding stage chain (analogous to TPV's
 * onboarding_approvals). This is the central register (analogous to
 * tpv_approvals). No DB foreign keys (row-level multi-tenancy convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_approval_requests')) {
            return;
        }

        Schema::create('purchase_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();                   // PAPR-YYYY-NNN
            $table->string('approval_type', 40)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 12)->default('Medium');
            $table->string('status', 16)->default('Pending')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Explicit short name — under MySQL's 64-char identifier limit.
            // Guarded by MigrationIdentifierLengthTest.
            $table->index(['subject_type', 'subject_id'], 'par_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_approval_requests');
    }
};
