<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase onboarding approval chain — the Purchase module's OWN approval engine,
 * fully independent of the shared onboarding_approvals table and of any TPV
 * approval workflow. One row per (onboarding, stage) across the five-stage chain:
 * Registration → Document → Commercial → Purchase → Final Activation. Each row
 * carries its decision (approved_by/at or rejected_by/at) + remarks; the full
 * history is on the audit trail. Row-level multi-tenancy; no DB foreign keys.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_onboarding_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();

            $table->string('stage');                      // registration | document | commercial | purchase | activation
            $table->unsignedTinyInteger('sequence');      // 1..5
            $table->string('status')->default('Pending')->index();   // Pending | Approved | Rejected
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();

            $table->timestamps();

            // One canonical row per stage per onboarding (the chain).
            $table->unique(['purchase_onboarding_id', 'stage']);
            $table->index(['tenant_id', 'purchase_onboarding_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_approvals');
    }
};
