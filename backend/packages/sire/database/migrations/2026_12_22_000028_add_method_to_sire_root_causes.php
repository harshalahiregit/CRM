<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — record WHICH root cause technique was used, and let it be one of three.
 *
 * sire_root_causes modelled 5-Why and only 5-Why: a five_whys column and nothing
 * to say a team had used anything else. Fishbone and Fault Tree Analysis are the
 * other two the specification names, and a team forced to express a fishbone as
 * five sequential whys has not done a fishbone -- they have flattened a
 * many-branched cause map into a single chain and lost the thing that makes it
 * worth doing.
 *
 * `analysis` is a JSON bag rather than a table per technique, because the shapes
 * are genuinely different -- a fishbone is categories of contributing causes, a
 * fault tree is a boolean graph -- and neither is queried. What IS queried is
 * the category and whether a human confirmed it, and those already have columns.
 *
 * Existing rows are stamped five_whys: that is what they are, and leaving the
 * column null would make "which method" unanswerable for every analysis written
 * before today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sire_root_causes')) {
            return;
        }

        if (! Schema::hasColumn('sire_root_causes', 'method')) {
            Schema::table('sire_root_causes', function (Blueprint $table) {
                $table->string('method', 32)->default('five_whys')->after('category');
                $table->json('analysis')->nullable()->after('five_whys');
            });

            DB::table('sire_root_causes')->whereNull('method')->update(['method' => 'five_whys']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sire_root_causes')) {
            return;
        }

        Schema::table('sire_root_causes', function (Blueprint $table) {
            $table->dropColumn(['method', 'analysis']);
        });
    }
};
