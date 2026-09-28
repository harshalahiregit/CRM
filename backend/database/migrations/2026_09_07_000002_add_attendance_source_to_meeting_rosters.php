<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HOW we know somebody attended.
 *
 * Attendance could be recorded two ways and the record did not say which: a
 * person seen in the embedded call, or a tick somebody made by hand. Those are
 * very different claims — one is observed, the other is a recollection — and a
 * register that presents them identically cannot be relied on afterwards.
 *
 * It also left a hole. The call can only be observed when the meeting is held
 * INSIDE the CRM. A meeting on Google Meet, Zoom or Teams is invisible to us:
 * the chair opens it, it runs for an hour, and nothing here knows who was
 * there. The third source closes that — clicking Join from the portal is
 * something we CAN see, whatever platform the meeting itself runs on.
 *
 *   link    they opened the meeting through the CRM's Join button
 *   call    they were seen in the call itself (in-app meetings only)
 *   manual  somebody ticked them on the register
 *
 * NULL for every row written before this existed, and for anyone not yet marked.
 */
return new class extends Migration
{
    private const TABLES = ['kickoff_attendees', 'purchase_kickoff_participants'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'attendance_source')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('attendance_source', 12)->nullable()->after('attendance_status'));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'attendance_source')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('attendance_source'));
            }
        }
    }
};
