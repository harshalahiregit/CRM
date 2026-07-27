<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI JD provenance on the Job Description. Additive + nullable — records whether
 * a JD body came from manual entry, the deterministic template, or the AI
 * generator, plus the AI provider/model metadata. Does not alter any existing
 * flow (the column is optional; the convert flow keeps working unchanged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_job_postings', 'jd_source')) {
                $table->string('jd_source')->nullable()->after('requirements'); // manual|ai|template
            }
            if (! Schema::hasColumn('hr_job_postings', 'ai_jd_meta')) {
                $table->json('ai_jd_meta')->nullable()->after('jd_source'); // { provider, model, generated_at }
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropColumn(['jd_source', 'ai_jd_meta']);
        });
    }
};
