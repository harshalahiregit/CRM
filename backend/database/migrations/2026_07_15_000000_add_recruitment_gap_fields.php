<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment Module gap-fill (Sangoe AIR OS docs → practical fields only).
 *
 * All columns are additive and nullable, extending the EXISTING recruitment
 * tables — no new tables, no duplicate fields, fully backward compatible.
 *   • Job Requisition (Doc 3 M2): preferred_skills, education, criticality
 *   • Candidate DB     (Doc 3 M3): dob, address, education, certifications,
 *                                  languages, professional_references
 *   • Interview        (Doc 3 M6): structured ratings + strengths / concerns
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->json('preferred_skills')->nullable()->after('required_skills');
            $table->string('education')->nullable()->after('experience_required');
            $table->string('criticality')->nullable()->after('priority'); // Low | Medium | High | Business Critical
        });

        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->date('dob')->nullable()->after('phone');
            $table->text('address')->nullable()->after('location');
            $table->json('education')->nullable()->after('skills');
            $table->json('certifications')->nullable()->after('education');
            $table->json('languages')->nullable()->after('certifications');
            $table->json('professional_references')->nullable()->after('languages');
        });

        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            // Structured interviewer ratings (0–10), matching the existing
            // technical_score / communication_score / problem_solving_score style.
            foreach ([
                'knowledge_score', 'confidence_score', 'ownership_score', 'learning_ability_score',
                'decision_making_score', 'leadership_score', 'integrity_score', 'culture_fit_score',
            ] as $col) {
                $table->integer($col)->nullable();
            }
            $table->text('strengths')->nullable();
            $table->text('concerns')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->dropColumn(['preferred_skills', 'education', 'criticality']);
        });
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['dob', 'address', 'education', 'certifications', 'languages', 'professional_references']);
        });
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->dropColumn([
                'knowledge_score', 'confidence_score', 'ownership_score', 'learning_ability_score',
                'decision_making_score', 'leadership_score', 'integrity_score', 'culture_fit_score',
                'strengths', 'concerns',
            ]);
        });
    }
};
