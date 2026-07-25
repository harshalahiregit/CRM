<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPV onboarding workflow over the shared `vendors` master (6-step wizard).
 * Vendor identity/profile/documents live on vendors + vendor_documents — this
 * table only tracks the workflow state, so there is one vendor record, not two.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tpv_onboardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            // Reserved for Shivam's shared KickoffMeeting entity — nullable FK, no
            // constraint yet, so wiring it later is a one-line migration (§2).
            $table->unsignedBigInteger('kickoff_meeting_id')->nullable()->index();

            $table->unsignedTinyInteger('current_step')->default(1);   // 1..6
            $table->string('status')->default('Draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('remarks')->nullable();
            // Work-Start Letter PDF generated on admin approval.
            $table->string('work_start_letter_path')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_onboardings');
    }
};
