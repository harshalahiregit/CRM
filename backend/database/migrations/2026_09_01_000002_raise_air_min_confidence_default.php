<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raise the default confidence floor from 40 to 60.
 *
 * The Phase-2 dry run showed most candidates scoring on 3-4 of 10 dimensions
 * (~46% confidence), which at a floor of 40 still produced confident verdicts —
 * "Highly Recommended" off three dimensions. 60 requires a majority of the scoring
 * weight to have real data behind it before the engine will commit to a band;
 * below it the recommendation is "Insufficient Data".
 *
 * Existing rows are moved only if they still carry the old default, so a tenant
 * that has deliberately tuned this keeps their value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('air_scoring_config', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_confidence')->default(60)->change();
        });

        DB::table('air_scoring_config')->where('min_confidence', 40)->update(['min_confidence' => 60]);
    }

    public function down(): void
    {
        Schema::table('air_scoring_config', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_confidence')->default(40)->change();
        });

        DB::table('air_scoring_config')->where('min_confidence', 60)->update(['min_confidence' => 40]);
    }
};
