<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #26 — "Add employee survey here dashboard, report etc."
 *
 * A new module: nothing survey-shaped existed anywhere in HR.
 *
 * ANONYMITY is the design decision worth reading. An anonymous response stores
 * NO employee_id — not a hashed one, not an encrypted one. If the column held
 * anything derived from the employee, someone with database access could
 * de-anonymise it, and a survey people believe is anonymous but is not is worse
 * than no survey at all.
 *
 * `department` IS snapshotted on the response, because "Department Responses" is
 * an explicit requirement. That is a genuine trade-off: in a department of two,
 * a department label narrows an anonymous answer to one of two people. The
 * service therefore suppresses department breakdowns below a configurable
 * minimum — see SurveyReportService::MIN_ANONYMOUS_GROUP.
 *
 * Targeting reuses the EXISTING hr_departments / hr_designations masters rather
 * than a survey-specific audience list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_survey_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name');
            $table->string('code', 40)->nullable();
            $table->string('colour', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'hr_survey_cat_unique');
        });

        Schema::create('hr_surveys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();

            // Draft | Scheduled | Active | Closed | Archived
            $table->string('status', 20)->default('Draft');
            $table->boolean('is_anonymous')->default(false);

            // Scheduling. A Scheduled survey opens itself when `starts_at` passes
            // and closes when `ends_at` does — see SurveyService::refreshStatuses().
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // All | Department | Designation — reusing the org masters.
            $table->string('audience', 20)->default('All');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();

            $table->boolean('allow_multiple_responses')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'hr_survey_status_idx');
        });

        Schema::create('hr_survey_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('survey_id')->index();
            $table->text('question_text');
            // text | rating | single_choice | multiple_choice | boolean
            $table->string('question_type', 20)->default('text');
            // Choices for the choice types. Null for text/rating/boolean.
            $table->json('options')->nullable();
            $table->unsignedTinyInteger('rating_max')->nullable();   // rating only
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hr_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('survey_id')->index();
            // NULL on an anonymous survey — see the class note. Nothing derived
            // from the employee is stored in its place.
            $table->unsignedBigInteger('employee_id')->nullable()->index();
            // Snapshot so department analytics survive an anonymous response AND
            // a later transfer. Suppressed below a minimum group size on read.
            $table->string('department', 150)->nullable();
            $table->string('designation', 150)->nullable();

            $table->string('status', 20)->default('Draft');   // Draft | Submitted
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'survey_id', 'status'], 'hr_survey_resp_idx');
        });

        Schema::create('hr_survey_answers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('response_id')->index();
            $table->unsignedBigInteger('question_id')->index();
            $table->text('answer_text')->nullable();          // text
            $table->decimal('answer_number', 8, 2)->nullable(); // rating
            $table->boolean('answer_boolean')->nullable();     // yes/no
            $table->json('selected_options')->nullable();      // choice types
            $table->timestamps();

            $table->unique(['response_id', 'question_id'], 'hr_survey_answer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_survey_answers');
        Schema::dropIfExists('hr_survey_responses');
        Schema::dropIfExists('hr_survey_questions');
        Schema::dropIfExists('hr_surveys');
        Schema::dropIfExists('hr_survey_categories');
    }
};
