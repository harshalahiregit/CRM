<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last time anybody was seen in the call.
 *
 * `actual_end_at` records a call that finished properly — the chair pressed
 * Leave, or the room closed. Calls do not always finish properly: a laptop is
 * shut, a browser crashes, somebody clicks away to another page. Nothing then
 * reports the end, and with `actual_start_at` set and no end the meeting reads
 * "In progress" for ever — a worse version of exactly the bug this set out to
 * fix, because at least the old one stopped at the end of the booked hour.
 *
 * This column is moved forward by every heartbeat while the call runs, so a
 * meeting whose last heartbeat is minutes old has plainly ended even though
 * nobody said so, and the timing can say so on its own. See MeetingTiming.
 */
return new class extends Migration
{
    private const TABLES = ['kickoff_meetings', 'purchase_kickoff_meetings'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'presence_seen_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dateTime('presence_seen_at')->nullable()->after('actual_end_at'));
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'presence_seen_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('presence_seen_at'));
            }
        }
    }
};
