<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #10 — "Question: no option to set various questions and no AI
 * generated question relevant to profile".
 *
 * WHY THIS IS NOT THE TRAINING QUIZ BANK (#25). `hr_quiz_questions` already
 * exists and is a genuine reusable bank, but it cannot carry an interview:
 *
 *  - It is indexed by TRAINING category, and an interview question is found by
 *    skill, designation, difficulty and years of experience — none of which that
 *    table has.
 *  - It supports only the three auto-scorable choice types, because a quiz is
 *    machine-graded against an answer key. Half of what a interview asks —
 *    subjective, coding, practical, behavioural — has no answer key at all and is
 *    scored by a human in the room.
 *  - Its scoring path is an ATTEMPT by the person being tested. An interview
 *    question is scored BY THE INTERVIEWER, about the candidate, inside a round.
 *
 * Bending the quiz engine to cover both would have made every training quiz carry
 * interview columns it never uses. So: a separate bank, but NO second quiz
 * engine — there is no attempt, no auto-grade and no answer-submission path here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── The bank ──────────────────────────────────────────────────────
        Schema::create('hr_interview_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->text('question_text');
            // mcq | subjective | coding | practical | behavioural | technical | hr
            $table->string('question_type', 24)->default('subjective');

            $table->string('category', 80)->nullable();
            // Reuses the designation master, so "questions for a Senior Engineer"
            // means the same designation the job and the employee record use.
            $table->unsignedBigInteger('designation_id')->nullable();

            // Matched against the same skill vocabulary #43 introduced, so a
            // question found by skill uses one spelling of that skill company-wide.
            $table->json('skills')->nullable();
            $table->json('tags')->nullable();

            $table->string('difficulty', 16)->default('medium');   // easy|medium|hard|expert
            $table->decimal('experience_min', 4, 1)->nullable();     // years
            $table->decimal('experience_max', 4, 1)->nullable();

            // Choice options with their correct flags. Only meaningful for MCQ —
            // "multiple correct answers where applicable" is simply more than one
            // option flagged correct, so no separate single/multiple type is needed.
            $table->json('options')->nullable();

            // What a good answer contains. For a human-scored question this is the
            // whole point: it is what makes two interviewers score alike.
            $table->text('expected_answer')->nullable();
            $table->decimal('marks', 6, 2)->default(0);
            $table->unsignedSmallInteger('time_limit_seconds')->nullable();

            $table->boolean('is_active')->default(true);

            // #10 — "Store generated metadata": provider, model, prompt inputs and
            // timestamp for anything AI wrote, so a reviewer can tell an authored
            // question from a generated one and know what produced it.
            $table->string('source', 16)->default('manual');   // manual | ai
            $table->json('ai_meta')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'question_type']);
            $table->index(['tenant_id', 'difficulty']);
            $table->index(['tenant_id', 'designation_id']);
            $table->index(['tenant_id', 'is_active']);

            $table->foreign('designation_id')->references('id')->on('hr_designations')->nullOnDelete();
        });

        // ── Named sets ────────────────────────────────────────────────────
        Schema::create('hr_interview_question_sets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            // Which round this set is meant for ("Technical Round 1"), matching the
            // free-text round_name the interview module already uses.
            $table->string('round_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('designation_id')->references('id')->on('hr_designations')->nullOnDelete();
        });

        Schema::create('hr_interview_question_set_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('set_id');
            $table->unsignedBigInteger('question_id');
            $table->decimal('marks_override', 6, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['set_id', 'question_id']);
            $table->foreign('set_id')->references('id')->on('hr_interview_question_sets')->cascadeOnDelete();
            $table->foreign('question_id')->references('id')->on('hr_interview_questions')->cascadeOnDelete();
        });

        // ── Questions asked in one round, and how they were scored ────────
        Schema::create('hr_interview_round_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('interview_round_id');
            $table->unsignedBigInteger('question_id');

            // The question TEXT is copied, not just referenced. An interview is a
            // record of what was actually asked — editing the bank afterwards must
            // not rewrite history.
            $table->text('question_text_snapshot');
            $table->string('question_type', 24);
            $table->decimal('marks', 6, 2)->default(0);

            // Interviewer evaluation. Nullable throughout: questions are attached
            // before the interview and scored during or after it.
            $table->decimal('score', 6, 2)->nullable();
            $table->text('answer_notes')->nullable();
            $table->json('selected_options')->nullable();   // MCQ: what they picked
            $table->boolean('is_correct')->nullable();      // MCQ: auto-derived

            $table->string('selection_mode', 12)->default('manual');   // manual | random
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['interview_round_id', 'question_id']);
            $table->index(['tenant_id', 'interview_round_id']);
            $table->foreign('interview_round_id')->references('id')
                ->on('hr_interview_rounds')->cascadeOnDelete();
            $table->foreign('question_id')->references('id')
                ->on('hr_interview_questions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_interview_round_questions');
        Schema::dropIfExists('hr_interview_question_set_items');
        Schema::dropIfExists('hr_interview_question_sets');
        Schema::dropIfExists('hr_interview_questions');
    }
};
