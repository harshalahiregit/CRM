<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add online-meeting platform fields to kickoff_meetings.
 *
 * All columns are nullable so existing on-site meetings are not affected.
 * These are populated by OnlineMeetingService when mode = 'online'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            // Which platform was used: google_meet | zoom | teams | stub
            $table->string('meeting_platform')->nullable()->after('mode');

            // Participant join URL (shown in UI)
            $table->string('meeting_link', 1024)->nullable()->after('meeting_platform');

            // Platform-native meeting identifier
            $table->string('meeting_id')->nullable()->after('meeting_link');

            // Optional passcode (Zoom always generates one; Meet/Teams may not)
            $table->string('meeting_passcode')->nullable()->after('meeting_id');

            // Host / start URL — only for the organiser; kept nullable if not provided
            $table->string('meeting_host_link', 1024)->nullable()->after('meeting_passcode');
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropColumn([
                'meeting_platform',
                'meeting_link',
                'meeting_id',
                'meeting_passcode',
                'meeting_host_link',
            ]);
        });
    }
};
