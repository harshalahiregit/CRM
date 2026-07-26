<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the EXISTING employee-onboarding schema to carry every question of the
 * "EMPLOYEES ONBOARDING FORM" (Form_Responses) and the Orientation Feedback form
 * (Form_Responses2).
 *
 * Deliberately minimal — verified against the live schema first:
 *   • dob, blood_group, marital_status, mobile_phone, alternate_phone,
 *     emergency_phone, personal_email, bank_*, pan/aadhaar/uan/pf/esic already
 *     exist on hr_employee_onboarding_profile          -> reused, NOT re-added
 *   • all 12 document uploads are rows in the existing
 *     hr_employee_onboarding_documents table            -> no new table
 *   • section save / verify / remarks already exist in
 *     hr_employee_onboarding_section_status             -> no new table
 *   • progress lives on hr_employee_onboardings.progress_percent -> reused
 *
 * Only two new tables are unavoidable: professional references (no equivalent —
 * the family table is for dependents/nominees) and orientation feedback (only a
 * timestamp existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_onboarding_profile', function (Blueprint $t) {
            // Stored verbatim as a single answer (approved decision 1 & 2).
            $t->string('full_name')->nullable()->after('onboarding_id');
            $t->text('complete_address_with_pin')->nullable()->after('current_country');

            // Application
            $t->string('applied_for_company')->nullable()->after('full_name');
            $t->string('designation_applied_for')->nullable()->after('applied_for_company');

            // Health
            $t->string('covid_vaccinated')->nullable();
            $t->string('physical_fitness_level')->nullable();
            $t->string('mental_health_stress_level')->nullable();

            // Experience & joining
            $t->text('core_experience')->nullable();
            $t->date('expected_joining_date')->nullable();   // candidate's answer; HR's joining_date stays untouched
            $t->string('salary_expected_or_confirmed')->nullable();
            $t->string('worked_here_before')->nullable();
            $t->string('applied_here_before')->nullable();

            // Skills
            $t->text('skills_to_manage_job')->nullable();

            // Bond & statutory
            $t->string('ready_to_sign_bond')->nullable();
            $t->string('has_pf_esic_uan')->nullable();

            // Declaration
            $t->boolean('declaration_accepted')->default(false);
            $t->timestamp('declaration_accepted_at')->nullable();
        });

        Schema::table('hr_employee_onboarding_education', function (Blueprint $t) {
            // One row per education block: High School / Intermediate / Graduate /
            // Post Graduate / Trade — keeps every block's own questions intact.
            $t->string('level')->nullable()->after('onboarding_id');
            $t->string('duration_attended')->nullable();   // "Number of Years/Days Attended"
            $t->string('completion_status')->nullable();   // "Completed" / "Graduated" / "Post Graduated?"
        });

        Schema::table('hr_employee_onboarding_experience', function (Blueprint $t) {
            $t->boolean('is_current')->default(false)->after('onboarding_id');
        });

        // Professional references — dedicated table (approved decision 3): future-proof
        // for multiple references and keeps the profile table clean.
        Schema::create('hr_employee_onboarding_references', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('onboarding_id')->index();
            $t->string('reference_name')->nullable();
            $t->string('relationship')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        // Orientation feedback — one row per onboarding. Employee Name / Position /
        // Department are NOT stored (approved decision 4): they are read from the
        // existing candidate / onboarding records.
        Schema::create('hr_employee_onboarding_orientation_feedback', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->index();
            $t->unsignedBigInteger('onboarding_id')->index();
            $t->date('date_of_orientation')->nullable();
            $t->string('overall_orientation_rating')->nullable();      // F
            $t->string('information_helpfulness')->nullable();         // G
            $t->string('topics_covered_overall')->nullable();          // H
            $t->string('topic_benefits_compensation')->nullable();     // I
            $t->string('topic_company_culture_values')->nullable();    // J
            $t->string('topic_job_specific_training')->nullable();     // K
            $t->text('topics_not_covered')->nullable();                // L
            $t->string('orientation_duration')->nullable();            // M
            $t->string('comfort_level_starting')->nullable();          // N
            $t->text('additional_comments')->nullable();               // O
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_onboarding_orientation_feedback');
        Schema::dropIfExists('hr_employee_onboarding_references');

        Schema::table('hr_employee_onboarding_experience', fn (Blueprint $t) => $t->dropColumn('is_current'));
        Schema::table('hr_employee_onboarding_education', fn (Blueprint $t) => $t->dropColumn(['level', 'duration_attended', 'completion_status']));
        Schema::table('hr_employee_onboarding_profile', fn (Blueprint $t) => $t->dropColumn([
            'full_name', 'complete_address_with_pin', 'applied_for_company', 'designation_applied_for',
            'covid_vaccinated', 'physical_fitness_level', 'mental_health_stress_level',
            'core_experience', 'expected_joining_date', 'salary_expected_or_confirmed',
            'worked_here_before', 'applied_here_before', 'skills_to_manage_job',
            'ready_to_sign_bond', 'has_pf_esic_uan', 'declaration_accepted', 'declaration_accepted_at',
        ]));
    }
};
