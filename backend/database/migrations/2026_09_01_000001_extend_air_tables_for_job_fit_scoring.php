<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the existing AIR tables so they can carry job-fit scoring alongside the
 * psychometric model they were designed for.
 *
 * The AIR schema (2026_07_08_000001) models talent / integrity / culture-fit /
 * retention — a different question from "does this candidate fit this requisition".
 * Rather than stand up a parallel set of tables, the same rows now carry both: the
 * psychometric columns stay untouched at their defaults until that engine is built,
 * and the job-fit columns added here are what CandidateScoringEngine writes.
 *
 * Strictly additive — nothing is dropped or renamed. Every new dimension column is
 * NULLABLE ON PURPOSE: a dimension with no input data must be distinguishable from
 * one that genuinely scored zero. `default(0)` would reintroduce exactly the
 * fabricated-baseline problem this engine exists to remove.
 */
return new class extends Migration
{
    /** score 0-100 with no default — null means "no data", not "scored zero". */
    private const DIMENSIONS = [
        'skills', 'experience', 'education', 'location', 'salary',
        'notice', 'resume', 'screening', 'interview', 'jd',
    ];

    /** Phase-1 weight vector. Sums to 100; the engine renormalises over whatever scored. */
    private const DEFAULT_WEIGHTS = [
        'skills' => 25, 'experience' => 20, 'jd' => 15, 'interview' => 12,
        'education' => 8, 'location' => 6, 'salary' => 5, 'notice' => 4,
        'screening' => 3, 'resume' => 2,
    ];

    public function up(): void
    {
        Schema::table('air_candidate_scores', function (Blueprint $table) {
            foreach (self::DIMENSIONS as $d) {
                if (! Schema::hasColumn('air_candidate_scores', $d.'_score')) {
                    $table->unsignedTinyInteger($d.'_score')->nullable();
                }
            }
            // Job-fit overall, kept separate from the psychometric `overall_air_score`
            // so the two engines can never overwrite each other's headline number.
            if (! Schema::hasColumn('air_candidate_scores', 'overall_score')) {
                $table->unsignedTinyInteger('overall_score')->nullable();
            }
            // Per-dimension reason + evidence, so every number can explain itself.
            if (! Schema::hasColumn('air_candidate_scores', 'dimension_details')) {
                $table->json('dimension_details')->nullable();
            }
            if (! Schema::hasColumn('air_candidate_scores', 'risk_flags')) {
                $table->json('risk_flags')->nullable();
            }
            // Which config produced this score — makes a re-score auditable when
            // weights change underneath.
            if (! Schema::hasColumn('air_candidate_scores', 'scoring_config_id')) {
                $table->unsignedBigInteger('scoring_config_id')->nullable();
            }
            if (! Schema::hasColumn('air_candidate_scores', 'applied_weights')) {
                $table->json('applied_weights')->nullable();
            }
            if (! Schema::hasColumn('air_candidate_scores', 'scored_trigger')) {
                $table->string('scored_trigger', 40)->nullable();
            }
        });

        Schema::table('air_candidate_scores', function (Blueprint $table) {
            // One live score per candidate+job. NOTE: on SQLite/MySQL, NULLs compare
            // distinct in a unique index, so candidates with no job_posting_id are not
            // constrained here — ScoreRecorder resolves those with an explicit lookup.
            $table->unique(['candidate_id', 'job_id'], 'air_scores_candidate_job_unique');
            $table->index(['tenant_id', 'candidate_id'], 'air_scores_tenant_candidate_idx');
        });

        Schema::table('air_scoring_config', function (Blueprint $table) {
            foreach (array_keys(self::DEFAULT_WEIGHTS) as $key) {
                if (! Schema::hasColumn('air_scoring_config', $key.'_weight')) {
                    $table->unsignedSmallInteger($key.'_weight')->default(self::DEFAULT_WEIGHTS[$key]);
                }
            }
            // Recommendation bands — the single source of truth. The frontend must not
            // hold thresholds; these drive RecommendationEngine.
            if (! Schema::hasColumn('air_scoring_config', 'highly_recommended_threshold')) {
                $table->unsignedTinyInteger('highly_recommended_threshold')->default(90);
            }
            if (! Schema::hasColumn('air_scoring_config', 'recommended_threshold')) {
                $table->unsignedTinyInteger('recommended_threshold')->default(75);
            }
            if (! Schema::hasColumn('air_scoring_config', 'consider_threshold')) {
                $table->unsignedTinyInteger('consider_threshold')->default(60);
            }
            // Below this share of total weight the score is too thin to act on.
            if (! Schema::hasColumn('air_scoring_config', 'min_confidence')) {
                $table->unsignedTinyInteger('min_confidence')->default(40);
            }
            if (! Schema::hasColumn('air_scoring_config', 'is_default')) {
                $table->boolean('is_default')->default(false);
            }
        });

        Schema::table('air_prediction_history', function (Blueprint $table) {
            if (! Schema::hasColumn('air_prediction_history', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable();
            }
            // The existing predicted_* pair records a forecast awaiting an outcome.
            // These record the transition itself — what the score WAS before this run.
            if (! Schema::hasColumn('air_prediction_history', 'previous_score')) {
                $table->unsignedTinyInteger('previous_score')->nullable();
            }
            if (! Schema::hasColumn('air_prediction_history', 'new_score')) {
                $table->unsignedTinyInteger('new_score')->nullable();
            }
            if (! Schema::hasColumn('air_prediction_history', 'previous_recommendation')) {
                $table->string('previous_recommendation')->nullable();
            }
            if (! Schema::hasColumn('air_prediction_history', 'new_recommendation')) {
                $table->string('new_recommendation')->nullable();
            }
            if (! Schema::hasColumn('air_prediction_history', 'confidence_level')) {
                $table->unsignedTinyInteger('confidence_level')->nullable();
            }
            if (! Schema::hasColumn('air_prediction_history', 'trigger')) {
                $table->string('trigger', 40)->nullable();
            }
        });

        Schema::table('air_prediction_history', function (Blueprint $table) {
            $table->index(['candidate_id', 'job_id'], 'air_history_candidate_job_idx');
        });

        // Ranking reads `orderByDesc('ai_score')` with no index today.
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->index('ai_score', 'hr_candidates_ai_score_idx');
        });
    }

    /**
     * Indexes only. The columns are deliberately NOT dropped: SQLite cannot drop a
     * column that participates in an index, and a partially-applied rollback would
     * leave scores unreadable. Additive up, index-only down.
     */
    public function down(): void
    {
        Schema::table('air_candidate_scores', function (Blueprint $table) {
            $table->dropUnique('air_scores_candidate_job_unique');
            $table->dropIndex('air_scores_tenant_candidate_idx');
        });
        Schema::table('air_prediction_history', function (Blueprint $table) {
            $table->dropIndex('air_history_candidate_job_idx');
        });
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropIndex('hr_candidates_ai_score_idx');
        });
    }
};
