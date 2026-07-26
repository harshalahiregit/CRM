<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise ATS fields for interview rounds: online/offline mode + venue, an
 * interviewer panel (multiple interviewers), and an overall rating +
 * recommendation captured with feedback. Additive only — existing single
 * interviewer_name / meet_link / score columns stay for backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->string('mode')->default('online')->after('round_name');       // online | offline
            $table->string('venue')->nullable()->after('meet_link');              // for offline interviews
            $table->json('interviewers')->nullable()->after('interviewer_id');    // panel: [{name,email}]
            $table->unsignedTinyInteger('rating')->nullable()->after('overall_score'); // 1..5
            $table->string('recommendation')->nullable()->after('rating');        // Strong Hire | Hire | Neutral | No Hire
        });
    }

    public function down(): void
    {
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->dropColumn(['mode', 'venue', 'interviewers', 'rating', 'recommendation']);
        });
    }
};
