<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #25 — "Quiz: how to set multiple questions and their answer?".
 *
 * The existing `hr_training_quizzes` records a SCORE and nothing else: quiz_name,
 * total_marks, obtained_marks, passed. There is no question anywhere in it, which
 * is exactly what the comment noticed. That table is left completely untouched —
 * it is the legacy result record and existing rows keep working.
 *
 * What is added is the engine behind a score:
 *
 *   hr_quiz_questions       the QUESTION BANK, reusable across quizzes
 *   hr_quiz_question_options   the answers, with is_correct marking the right ones
 *   hr_quizzes              a quiz: pass marks, duration, which programme
 *   hr_quiz_items           which bank questions this quiz uses, and in what order
 *   hr_quiz_attempts        one employee's go at a quiz
 *   hr_quiz_answers         what they picked, and what it scored
 *
 * Questions live in a BANK rather than on the quiz because the comment asks for
 * one, and because the same question is reused across refresher quizzes — copying
 * it per quiz would mean fixing a typo in six places.
 *
 * Marks are stored on the ATTEMPT as well as computed from the answers. The bank
 * question's marks can change next year; an attempt must keep scoring what it
 * scored on the day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // Reuses the EXISTING hr_training_categories master — a question about
            // fire safety belongs to the same category the training does.
            $table->unsignedBigInteger('category_id')->nullable();
            $table->text('question_text');
            // single_choice | multiple_choice | boolean
            $table->string('question_type', 20)->default('single_choice');
            $table->decimal('marks', 8, 2)->default(1);
            $table->text('explanation')->nullable();   // shown after evaluation
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'category_id', 'is_active'], 'hr_quiz_q_cat_idx');
        });

        Schema::create('hr_quiz_question_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('question_id')->index();
            $table->text('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hr_quizzes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->unsignedBigInteger('training_program_id')->nullable();   // reuse
            $table->text('description')->nullable();
            // Pass mark as a PERCENTAGE — total marks vary as questions are added
            // or removed, so an absolute pass mark silently changes meaning.
            $table->decimal('pass_percentage', 5, 2)->default(50);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('max_attempts')->nullable();  // null = unlimited
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('show_correct_answers')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_quiz_name_unique');
        });

        Schema::create('hr_quiz_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('quiz_id')->index();
            $table->unsignedBigInteger('question_id');
            // Lets one quiz weight a bank question differently without forking it.
            $table->decimal('marks_override', 8, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['quiz_id', 'question_id'], 'hr_quiz_item_unique');
        });

        Schema::create('hr_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('quiz_id')->index();
            $table->unsignedBigInteger('employee_id')->index();
            // Ties an attempt to the training assignment it belongs to, so quiz
            // history and training history are the same story.
            $table->unsignedBigInteger('employee_training_id')->nullable();

            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('status', 20)->default('In Progress');   // In Progress | Submitted | Evaluated
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();

            // Frozen at submission — bank marks may change later.
            $table->decimal('total_marks', 8, 2)->default(0);
            $table->decimal('obtained_marks', 8, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('pass_percentage', 5, 2)->default(0);
            $table->boolean('passed')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'quiz_id'], 'hr_quiz_attempt_idx');
        });

        Schema::create('hr_quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('attempt_id')->index();
            $table->unsignedBigInteger('question_id');
            // Always an array, even for single-choice — one shape to read.
            $table->json('selected_option_ids')->nullable();
            $table->boolean('is_correct')->default(false);
            $table->decimal('marks_awarded', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id'], 'hr_quiz_answer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_quiz_answers');
        Schema::dropIfExists('hr_quiz_attempts');
        Schema::dropIfExists('hr_quiz_items');
        Schema::dropIfExists('hr_quizzes');
        Schema::dropIfExists('hr_quiz_question_options');
        Schema::dropIfExists('hr_quiz_questions');
    }
};
