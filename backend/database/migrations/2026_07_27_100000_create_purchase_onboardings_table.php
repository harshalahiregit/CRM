<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase-vendor onboarding — the procurement-side mirror of tpv_onboardings,
 * over the SAME shared `vendors` master (engagement 'purchase'). Deliberately a
 * separate table from tpv_onboardings so the two lifecycles stay isolated and
 * can diverge; vendor identity/profile/documents still live on vendors +
 * vendor_documents (one vendor record, not two).
 *
 * Row-level multi-tenancy (tenant_id); no DB foreign keys (module convention);
 * soft deletes; audited via the Auditable trait.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_onboardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            // Convenience pointer to the shared polymorphic KickoffMeeting.
            $table->unsignedBigInteger('kickoff_meeting_id')->nullable()->index();

            $table->unsignedTinyInteger('current_step')->default(1);   // 1..6
            $table->string('status')->default('Draft')->index();
            $table->json('profile')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('remarks')->nullable();
            $table->string('hold_reason')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('work_start_letter_path')->nullable();

            // Kickoff acknowledgement (Step 1) + completion declaration (Step 5).
            $table->string('kickoff_pdf_path')->nullable();
            $table->boolean('acknowledged')->default(false);
            $table->string('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_ip')->nullable();
            $table->string('acknowledged_browser')->nullable();
            $table->string('acknowledged_device')->nullable();
            $table->timestamp('declaration_accepted_at')->nullable();
            $table->boolean('onboarding_complete')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('completed_ip')->nullable();
            $table->string('completed_browser')->nullable();
            $table->string('completed_device')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'vendor_id']);
            $table->index(['tenant_id', 'status']);
        });

        // Per-tenant/year sequence for the Purchase registration number
        // (PUR-YYYY-NNNNN). Isolated from tpv_registration_sequences.
        Schema::create('purchase_registration_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_registration_sequences');
        Schema::dropIfExists('purchase_onboardings');
    }
};
