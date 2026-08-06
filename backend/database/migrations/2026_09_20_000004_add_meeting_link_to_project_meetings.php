<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generated (or pasted) online-meeting link on a project meeting — so a
 * Zoom / Google Meet / Jitsi link lives with the meeting and attendees can join
 * straight from the Meetings tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_meetings') && ! Schema::hasColumn('project_meetings', 'meeting_link')) {
            Schema::table('project_meetings', function (Blueprint $t) {
                $t->string('meeting_link', 1000)->nullable()->after('mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_meetings', 'meeting_link')) {
            Schema::table('project_meetings', function (Blueprint $t) {
                $t->dropColumn('meeting_link');
            });
        }
    }
};
