<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which app-side notification this CRM notification mirrors.
 *
 * The two in-app stores have independent ids, so a push carrying the CRM's id
 * pointed at nothing the phone could look up. Tapping the notification could
 * open a list, but never the thing that was tapped — and never its attachments,
 * which is the whole reason somebody taps an announcement.
 *
 * A column rather than a lookup by title and time: two announcements sent in
 * the same minute would be indistinguishable, and "probably this one" is not a
 * good enough answer for whose policy document you are opening.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_notifications') && ! Schema::hasColumn('hr_notifications', 'app_notification_id')) {
            Schema::table('hr_notifications', function (Blueprint $t) {
                $t->unsignedBigInteger('app_notification_id')->nullable()->index()->after('recipient_user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_notifications') && Schema::hasColumn('hr_notifications', 'app_notification_id')) {
            Schema::table('hr_notifications', fn (Blueprint $t) => $t->dropColumn('app_notification_id'));
        }
    }
};
