<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── AIR candidate scores (10-dimension) ──────────────────────────────
        if (!Schema::hasTable('air_candidate_scores')) {
            Schema::create('air_candidate_scores', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('candidate_id');
                $table->unsignedBigInteger('job_id')->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable();
                // 10 Dimensions
                $table->integer('talent_score')->default(0);
                $table->integer('learning_ability')->default(0);
                $table->integer('integrity_score')->default(0);
                $table->integer('stability_score')->default(0);
                $table->integer('leadership_score')->default(0);
                $table->integer('culture_fit')->default(0);
                $table->integer('communication_score')->default(0);
                $table->integer('stress_handling')->default(0);
                $table->integer('problem_solving')->default(0);
                $table->integer('growth_potential')->default(0);
                // Derived
                $table->decimal('overall_air_score', 5, 2)->default(0);
                $table->decimal('hire_probability', 5, 2)->default(0);
                $table->decimal('confidence_level', 5, 2)->default(0);
                // Retention
                $table->decimal('retention_6mo', 5, 2)->default(0);
                $table->decimal('retention_1yr', 5, 2)->default(0);
                $table->decimal('retention_3yr', 5, 2)->default(0);
                // Risks
                $table->string('fraud_risk')->default('low');
                $table->string('job_hopping_risk')->default('low');
                $table->string('burnout_risk')->default('low');
                $table->string('integrity_risk')->default('low');
                $table->string('attendance_risk')->default('low');
                // AI Output
                $table->string('recommendation')->nullable();
                $table->text('ai_summary')->nullable();
                $table->text('strengths')->nullable();
                $table->text('weaknesses')->nullable();
                $table->text('suggested_actions')->nullable();
                $table->timestamp('scored_at')->nullable();
                $table->timestamps();
            });
        }

        // ── AIR prediction history ────────────────────────────────────────────
        if (!Schema::hasTable('air_prediction_history')) {
            Schema::create('air_prediction_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('candidate_id');
                $table->unsignedBigInteger('job_id')->nullable();
                $table->decimal('predicted_score', 5, 2)->default(0);
                $table->string('predicted_recommendation')->nullable();
                $table->string('actual_outcome')->nullable();
                $table->string('actual_performance')->nullable();
                $table->decimal('prediction_accuracy', 5, 2)->nullable();
                $table->timestamp('prediction_date')->nullable();
                $table->timestamps();
            });
        }

        // ── Assessment library ────────────────────────────────────────────────
        if (!Schema::hasTable('hr_assessment_library')) {
            Schema::create('hr_assessment_library', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('title');
                $table->string('type'); // technical/behavioral/psychometric/situational/communication/logical/integrity/learning_agility/leadership
                $table->text('description')->nullable();
                $table->integer('duration_minutes')->default(30);
                $table->integer('total_marks')->default(100);
                $table->integer('passing_marks')->default(60);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('hr_assessment_questions')) {
            Schema::create('hr_assessment_questions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assessment_id');
                $table->text('question');
                $table->string('question_type')->default('mcq');
                $table->text('options')->nullable();
                $table->text('correct_answer')->nullable();
                $table->integer('marks')->default(1);
                $table->integer('order_index')->default(0);
                $table->timestamps();
                $table->foreign('assessment_id')->references('id')->on('hr_assessment_library')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('hr_assessment_assignments')) {
            Schema::create('hr_assessment_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('candidate_id');
                $table->unsignedBigInteger('job_id')->nullable();
                $table->unsignedBigInteger('assessment_id');
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('deadline')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->integer('total_score')->default(0);
                $table->decimal('percentage', 5, 2)->default(0);
                $table->string('status')->default('pending');
                $table->timestamps();
            });
        }

        // ── AIR Scoring config ────────────────────────────────────────────────
        if (!Schema::hasTable('air_scoring_config')) {
            Schema::create('air_scoring_config', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('job_family')->default('General');
                $table->integer('talent_weight')->default(10);
                $table->integer('learning_weight')->default(10);
                $table->integer('integrity_weight')->default(10);
                $table->integer('stability_weight')->default(10);
                $table->integer('leadership_weight')->default(10);
                $table->integer('culture_fit_weight')->default(10);
                $table->integer('communication_weight')->default(10);
                $table->integer('stress_weight')->default(10);
                $table->integer('problem_solving_weight')->default(10);
                $table->integer('growth_weight')->default(10);
                $table->integer('pass_threshold')->default(70);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // ── AIR Pulse surveys ─────────────────────────────────────────────────
        if (!Schema::hasTable('air_pulse_surveys')) {
            Schema::create('air_pulse_surveys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('employee_id');
                $table->integer('week_number');
                $table->integer('year');
                $table->integer('mood_score')->default(5);
                $table->integer('manager_support')->default(5);
                $table->integer('workload_score')->default(5);
                $table->integer('role_clarity')->default(5);
                $table->integer('learning_score')->default(5);
                $table->integer('recognition_score')->default(5);
                $table->integer('stress_score')->default(5);
                $table->integer('relationship_score')->default(5);
                $table->integer('growth_score')->default(5);
                $table->text('suggestion')->nullable();
                $table->decimal('happiness_score', 5, 2)->nullable();
                $table->decimal('burnout_score', 5, 2)->nullable();
                $table->string('retention_risk')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('air_pulse_surveys');
        Schema::dropIfExists('air_scoring_config');
        Schema::dropIfExists('hr_assessment_assignments');
        Schema::dropIfExists('hr_assessment_questions');
        Schema::dropIfExists('hr_assessment_library');
        Schema::dropIfExists('air_prediction_history');
        Schema::dropIfExists('air_candidate_scores');
    }
};
