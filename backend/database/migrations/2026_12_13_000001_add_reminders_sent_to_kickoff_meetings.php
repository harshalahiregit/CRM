<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-reminders for kickoff meetings. `reminders_sent` records which reminder
 * windows (e.g. "1440" = 24h before, "60" = 1h before) have already gone out for
 * a meeting, so the scheduled command is idempotent and never double-sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->json('reminders_sent')->nullable()->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropColumn('reminders_sent');
        });
    }
};
