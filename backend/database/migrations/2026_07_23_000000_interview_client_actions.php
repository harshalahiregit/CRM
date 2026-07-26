<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interview & Offer Collaboration — Sprint 4.
 *
 * A dedicated client-action history table (the architecture reserved this so the
 * existing hr_interview_rounds is never modified). The company Confirms / requests
 * Reschedule / Cancel — recorded here + on the interview's audit trail + notified
 * to the recruiter, who still executes the actual change via InterviewService.
 * Offer company decisions ride the offer's existing audit trail (no table needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_interview_client_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('interview_id')->constrained('hr_interview_rounds')->cascadeOnDelete();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('company_id');            // hr_external_companies
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->string('action', 30);                        // Confirmed | Reschedule | Cancel | Accepted
            $table->date('preferred_date')->nullable();
            $table->string('preferred_time', 20)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['interview_id', 'id']);
            $table->index(['company_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_interview_client_actions');
    }
};
