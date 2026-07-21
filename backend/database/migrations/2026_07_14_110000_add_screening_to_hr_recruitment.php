<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPK-1: screening-question builder + candidate screening answers. Both JSON
     * and nullable (like the existing `sources` / `ai_breakdown` columns) so
     * existing rows and the current apply flow keep working unchanged.
     */
    public function up(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->json('screening_questions')->nullable()->after('sources');
        });
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->json('screening_answers')->nullable()->after('ai_breakdown');
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropColumn('screening_questions');
        });
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn('screening_answers');
        });
    }
};
