<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // hr_manpower_requests — add rejection_reason, approved_by, approved_at
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('rejection_reason');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        // hr_job_postings — link to manpower request
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->unsignedBigInteger('manpower_request_id')->nullable()->after('applicant_count');
        });

        // hr_candidates — AI breakdown JSON
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->json('ai_breakdown')->nullable()->after('ai_score');
        });

        // hr_interview_rounds — score fields + reminder
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->integer('technical_score')->nullable()->after('notes');
            $table->integer('communication_score')->nullable()->after('technical_score');
            $table->integer('problem_solving_score')->nullable()->after('communication_score');
            $table->decimal('overall_score', 5, 2)->nullable()->after('problem_solving_score');
            $table->integer('reminder_minutes')->default(60)->after('calendar_event_created');
        });

        // hr_offers — rejection_reason
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('accepted_at');
        });

        // hr_onboarding — document_checklist JSON
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->json('document_checklist')->nullable()->after('step_record_created');
        });

        // hr_employees — personal fields
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->date('dob')->nullable()->after('phone');
            $table->enum('gender', ['Male','Female','Other','Prefer not to say'])->nullable()->after('dob');
            $table->text('address')->nullable()->after('gender');
            $table->date('probation_end_date')->nullable()->after('joining_date');
            $table->date('confirmation_date')->nullable()->after('probation_end_date');
        });
    }

    public function down(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason','approved_by','approved_at']);
        });
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropColumn('manpower_request_id');
        });
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn('ai_breakdown');
        });
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->dropColumn(['technical_score','communication_score','problem_solving_score','overall_score','reminder_minutes']);
        });
        Schema::table('hr_offers', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
        Schema::table('hr_onboarding', function (Blueprint $table) {
            $table->dropColumn('document_checklist');
        });
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['dob','gender','address','probation_end_date','confirmation_date']);
        });
    }
};
