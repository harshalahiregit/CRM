<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #44 — "Exit questionnaire: option to set various types of exit
 * questionnaire and select while processing any exit formality."
 *
 * The existing `hr_exit_interviews` table is a FIXED form: fifteen named feedback
 * columns, the same fifteen questions for a resignation, a retirement and a
 * termination. That is what the comment is asking to replace.
 *
 * The fifteen columns are NOT dropped. Interviews already recorded still hold
 * their answers there, and `questionnaire_id` is nullable so an interview taken
 * on the old form keeps working exactly as before. A tenant that defines no
 * template sees no change at all.
 *
 * Question types reuse HrSurveyQuestion's vocabulary rather than inventing a
 * second one — an exit questionnaire and an employee survey ask the same KINDS of
 * question, and two divergent lists would eventually disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_exit_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->text('description')->nullable();

            // Optional binding to an exit type (Resignation, Termination…), which
            // is what lets the right questionnaire be offered automatically
            // instead of chosen from a list every time.
            $table->unsignedBigInteger('exit_type_id')->nullable();

            // The fallback when no template matches the exit type.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->unique(['tenant_id', 'code']);
            $table->foreign('exit_type_id')->references('id')->on('hr_exit_types')->nullOnDelete();
        });

        Schema::create('hr_exit_questionnaire_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('questionnaire_id');
            $table->text('question_text');
            $table->string('question_type', 24)->default('text');
            $table->json('options')->nullable();      // choice questions
            $table->unsignedTinyInteger('rating_max')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'questionnaire_id']);
            $table->foreign('questionnaire_id')->references('id')
                ->on('hr_exit_questionnaires')->cascadeOnDelete();
        });

        Schema::create('hr_exit_interview_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('exit_interview_id');
            $table->unsignedBigInteger('question_id');

            // One column per answer shape, like HrSurveyAnswer: a rating that has
            // to be parsed back out of free text cannot be averaged, and averaging
            // exit ratings is the point of exit reporting.
            $table->text('answer_text')->nullable();
            $table->unsignedTinyInteger('answer_rating')->nullable();
            $table->boolean('answer_boolean')->nullable();
            $table->json('answer_options')->nullable();

            $table->timestamps();

            $table->unique(['exit_interview_id', 'question_id']);
            $table->foreign('exit_interview_id')->references('id')
                ->on('hr_exit_interviews')->cascadeOnDelete();
            $table->foreign('question_id')->references('id')
                ->on('hr_exit_questionnaire_questions')->cascadeOnDelete();
        });

        Schema::table('hr_exit_interviews', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_exit_interviews', 'questionnaire_id')) {
                // Nullable: an interview taken on the fixed form has no template,
                // and that must stay a valid state forever.
                $table->unsignedBigInteger('questionnaire_id')->nullable()->after('employee_id');
                $table->foreign('questionnaire_id')->references('id')
                    ->on('hr_exit_questionnaires')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_exit_interviews', function (Blueprint $table) {
            $table->dropForeign(['questionnaire_id']);
            $table->dropColumn('questionnaire_id');
        });

        Schema::dropIfExists('hr_exit_interview_answers');
        Schema::dropIfExists('hr_exit_questionnaire_questions');
        Schema::dropIfExists('hr_exit_questionnaires');
    }
};
