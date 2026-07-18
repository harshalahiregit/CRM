<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Candidate Collaboration — Sprint 3.
 *
 * Two tiny additive changes: a viewed_at stamp on submissions (drives the
 * "Viewed" timeline event), and an optional submission_id on the existing
 * request-message table so the SAME messaging reuses for per-candidate comments
 * (NULL = request thread, set = candidate thread). No candidate/interview/AI/
 * resume schema is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidate_submissions', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('feedback_at');
        });

        Schema::table('hr_hiring_request_messages', function (Blueprint $table) {
            $table->foreignId('submission_id')->nullable()->after('hiring_request_id')
                ->constrained('hr_candidate_submissions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_hiring_request_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submission_id');
        });
        Schema::table('hr_candidate_submissions', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
