<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comments #39 ("Employee overall score?") and #40 ("Positive, area of
 * improvement, and Risk factor of this employee based on profile, performance,
 * data entry to the system etc.").
 *
 * WHY NOT air_candidate_scores. That table is keyed by candidate_id + job_id and
 * its vocabulary is recruitment ("Strong Hire", "job fit"). An employee has no
 * job to be matched against — they already have the job. Scoring an employee
 * there would mean inventing a null job_id row per employee and reading a
 * recommendation that cannot apply. Separate storage, same discipline.
 *
 * TWO tables on purpose. `hr_employee_scores` is the CURRENT score, one row per
 * employee, so a profile read is a single lookup. `hr_employee_score_history` is
 * APPEND-ONLY: the comment asks for recalculation, and a recalculation that
 * overwrote the past would destroy the trend that makes the score worth having.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_scores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id');

            // Nullable: an employee with too little data has NO score rather than
            // a fabricated one — the same rule the candidate engine follows.
            $table->unsignedTinyInteger('overall_score')->nullable();
            $table->unsignedTinyInteger('provisional_score')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('band', 24)->nullable();          // Excellent | Strong | …
            $table->text('summary')->nullable();

            // Per-dimension breakdown + the weights actually applied, so a score
            // stays explainable after the config changes.
            $table->json('dimensions')->nullable();
            $table->json('applied_weights')->nullable();

            // #40 — the three insight groups, derived from the dimensions above.
            $table->json('positives')->nullable();
            $table->json('improvements')->nullable();
            $table->json('risks')->nullable();
            $table->text('insight_narrative')->nullable();
            // 'rules' when derived from data alone, 'ai' when a provider phrased
            // it. Recorded so nobody has to guess which produced the text.
            $table->string('insight_source', 16)->nullable();
            $table->json('insight_meta')->nullable();        // provider, model, inputs
            $table->timestamp('insights_generated_at')->nullable();

            $table->timestamp('scored_at')->nullable();
            $table->unsignedBigInteger('scored_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'employee_id']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->cascadeOnDelete();
        });

        Schema::create('hr_employee_score_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('employee_id');

            $table->unsignedTinyInteger('overall_score')->nullable();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->string('band', 24)->nullable();
            $table->json('dimensions')->nullable();

            // What the score was immediately before this run — the delta is the
            // whole point of keeping history.
            $table->unsignedTinyInteger('previous_score')->nullable();
            $table->string('trigger', 40)->default('manual');   // manual | scheduled | review_saved
            $table->unsignedBigInteger('scored_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'created_at']);
            $table->foreign('employee_id')->references('id')->on('hr_employees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_score_history');
        Schema::dropIfExists('hr_employee_scores');
    }
};
