<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment Services (Phase 1) — external-company hiring intake.
 *
 * Two NEW, isolated tables. Nothing in the existing recruitment schema is
 * touched. A hiring request, once approved, is converted into a normal
 * hr_manpower_requests row (via the existing ManpowerRequestService) and the
 * standard recruitment flow continues — so there is no duplication of the
 * candidate/job/interview/offer/onboarding tables.
 *
 * Statuses are string columns (SQLite enum CHECK-constraints can't be altered
 * later — a trap this codebase has hit before).
 */
return new class extends Migration
{
    public function up(): void
    {
        // External client companies (NOT tenants — they never log in).
        Schema::create('hr_external_companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();     // the provider org that owns this client
            $table->string('name');
            $table->string('industry')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('location')->nullable();
            $table->string('website')->nullable();
            $table->string('status')->default('Active');          // Active | Inactive
            $table->text('notes')->nullable();
            // Public submission credential — the external company submits requests
            // through /hiring-request/{public_token} with no login.
            $table->string('public_token', 64)->unique();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // Hiring requests submitted by / on behalf of external companies.
        Schema::create('hr_hiring_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('external_company_id')->constrained('hr_external_companies')->cascadeOnDelete();

            // Requirement fields — mirror what the Manpower Request needs so the
            // conversion is a straight mapping (no re-keying).
            $table->string('job_title');
            $table->string('department')->nullable();
            $table->string('employment_type')->nullable();        // Full-time | Part-time | Contract | Internship
            $table->string('work_mode')->nullable();              // Onsite | Remote | Hybrid
            $table->unsignedInteger('number_of_positions')->default(1);
            $table->string('experience_required')->nullable();
            $table->string('education')->nullable();
            $table->string('location')->nullable();
            $table->decimal('salary_min', 14, 2)->nullable();
            $table->decimal('salary_max', 14, 2)->nullable();
            $table->json('required_skills')->nullable();
            $table->text('job_description')->nullable();
            $table->string('priority')->default('Medium');        // Low | Medium | High | Critical

            // Workflow
            $table->string('status')->default('Submitted');       // Submitted | Under_Review | Approved | Rejected | Converted
            $table->string('source')->default('Internal');        // Internal (HR-entered) | Portal (external submission)
            $table->unsignedBigInteger('submitted_by')->nullable();   // internal user, null for portal
            $table->string('submitter_name')->nullable();             // portal submitter contact
            $table->string('submitter_email')->nullable();
            $table->unsignedBigInteger('assigned_recruiter_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            // Link to the created Manpower Request after conversion (the hand-off
            // point into the existing recruitment flow).
            $table->unsignedBigInteger('manpower_request_id')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('assigned_recruiter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_hiring_requests');
        Schema::dropIfExists('hr_external_companies');
    }
};
