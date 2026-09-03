<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auto-reminder bookkeeping for Purchase kickoff meetings (parity with shared). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            $table->json('reminders_sent')->nullable()->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            $table->dropColumn('reminders_sent');
        });
    }
};
