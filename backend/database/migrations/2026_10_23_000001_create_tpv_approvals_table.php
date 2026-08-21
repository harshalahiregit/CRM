<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central TPV Approval register (Sangoe TPV §12).
 *
 * A generic approval request that ANY TPV action can raise — the ~18 approval
 * types in ApprovalType. Deliberately SEPARATE from `onboarding_approvals` (the
 * multi-level onboarding chain that gates real vendor activation): that flow is
 * left untouched. This is an additive central register; individual flows can be
 * wired to auto-raise entries here over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('reference')->index();              // APR-YYYY-###
            $table->string('approval_type', 40)->index();
            // Optional polymorphic link to the thing being approved.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Convenience vendor link for filtering (most approvals relate to a vendor).
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 12)->default('Medium');
            // Pending / Approved / Rejected / Cancelled
            $table->string('status', 16)->default('Pending')->index();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_approvals');
    }
};
