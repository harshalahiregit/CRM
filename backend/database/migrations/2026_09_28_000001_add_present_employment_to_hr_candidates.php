<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #15 — "ADD CANDIDATE – AUTO FETCH CANDIDATE DETAIL AND
 * (PRESENT CO., DEPT, DESIGNATION, REFERENCE".
 *
 * `current_company` already existed and was already auto-filled. The other two
 * present-employment facts had nowhere to live: the LinkedIn parser has always
 * extracted a `headline` (which is the designation) and then thrown it away,
 * because `hr_candidates` has no column to put it in.
 *
 * REFERENCE needs no column — `professional_references` already exists and is
 * already cast to an array; it was simply never captured anywhere in the UI.
 *
 * Both columns are nullable with no default, so every existing row and every
 * existing API response is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_candidates', 'current_designation')) {
                $table->string('current_designation')->nullable()->after('current_company');
            }
            if (! Schema::hasColumn('hr_candidates', 'current_department')) {
                $table->string('current_department')->nullable()->after('current_designation');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['current_designation', 'current_department']);
        });
    }
};
