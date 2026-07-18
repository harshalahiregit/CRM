<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment Services — Phase 3 (Recruitment Operations).
 *
 * Adds SLA lifecycle timestamps to hiring requests plus three small tables for
 * genuinely new concepts: internal recruiter notes, client ratings, and resume
 * shares. Nothing existing is duplicated — resume files still live on the
 * hr_resumes disk (shares only reference a candidate), and feedback/aggregate
 * metrics are derived from existing candidate/interview/offer/employee data.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SLA clocks — set as the request moves through its lifecycle.
        Schema::table('hr_hiring_requests', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_recruiter_id');
            $table->timestamp('first_submitted_at')->nullable()->after('converted_at');
            $table->timestamp('closed_at')->nullable()->after('first_submitted_at');
        });

        // Internal recruiter notes on a hiring request (never exposed to the client portal).
        Schema::create('hr_recruiter_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hiring_request_id')->constrained('hr_hiring_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->timestamps();
            $table->index(['hiring_request_id', 'id']);
        });

        // Client rating for a completed engagement (one per hiring request).
        Schema::create('hr_client_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hiring_request_id')->unique()->constrained('hr_hiring_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('external_company_id')->nullable();
            $table->unsignedBigInteger('recruiter_id')->nullable()->index();
            $table->unsignedTinyInteger('overall');            // 1..5
            $table->unsignedTinyInteger('communication')->nullable();
            $table->unsignedTinyInteger('candidate_quality')->nullable();
            $table->unsignedTinyInteger('turnaround_time')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();
        });

        // Resume shares — recruiter shares an existing candidate resume with the client.
        Schema::create('hr_resume_shares', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hiring_request_id')->constrained('hr_hiring_requests')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('hr_candidates')->cascadeOnDelete();
            $table->string('share_token', 64)->unique();
            $table->unsignedBigInteger('shared_by')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->unique(['hiring_request_id', 'candidate_id']); // one share row per candidate per request
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_resume_shares');
        Schema::dropIfExists('hr_client_ratings');
        Schema::dropIfExists('hr_recruiter_notes');
        Schema::table('hr_hiring_requests', function (Blueprint $table) {
            $table->dropColumn(['assigned_at', 'first_submitted_at', 'closed_at']);
        });
    }
};
