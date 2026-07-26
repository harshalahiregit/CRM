<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interview lifecycle completion metadata (additive).
 *
 * The existing feedback columns (result / rating / *_score / strengths / concerns /
 * notes) stay exactly as they are. This only ADDS the completion metadata the
 * Complete-Interview form needs, plus a persisted cancellation reason so it is not
 * only in the audit log. Nothing here is destructive or renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            // Attendance recorded at completion: Present | Absent | No Show.
            $table->string('attendance')->nullable()->after('result');
            // Interview length in minutes.
            $table->unsignedInteger('duration')->nullable()->after('attendance');
            // Free-text remarks, distinct from the existing evaluation `notes`.
            $table->text('remarks')->nullable()->after('concerns');
            // Who completed the round and when (actor + timestamp).
            $table->timestamp('completed_at')->nullable()->after('remarks');
            $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
            // Reason captured when an interview is cancelled (also audited).
            $table->text('cancellation_reason')->nullable()->after('completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->dropColumn([
                'attendance', 'duration', 'remarks',
                'completed_at', 'completed_by', 'cancellation_reason',
            ]);
        });
    }
};
