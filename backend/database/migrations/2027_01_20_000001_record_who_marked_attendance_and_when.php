<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance is the admin's record — so the record has to say whose.
 *
 * Until now a roster row could say "Present" and nothing said who decided that
 * or when. The tick was also silent about the one thing the minutes are asked
 * for most often: what time the person came in and what time they left. The
 * automatic `joined_at` / `seconds_in_call` columns answer that only for people
 * who pressed Join in the CRM, which is a minority of any real meeting.
 *
 * So four columns, on both rosters:
 *
 *   in_at / out_at    the times the ADMIN types. This is the official record.
 *                     `joined_at` / `left_at` stay exactly as they were — they
 *                     are observed evidence, and overwriting them with typed
 *                     values would destroy the thing the typed values are meant
 *                     to be checked against.
 *   marked_by         the account that ticked. An attendance record with no
 *                     author is an assertion nobody owns.
 *   marked_at         when they ticked it.
 *
 * Deliberately NOT reusing verdict_from / verdict_to: those belong to
 * MeetingAttendanceReview, which drops them for any verdict other than
 * Partial_Absent. Times that vanish when the organiser marks somebody fully
 * present are not a record of when they were there.
 */
return new class extends Migration
{
    /** kickoff_attendees (shared) and purchase_kickoff_participants (Purchase). */
    private const TABLES = ['kickoff_attendees', 'purchase_kickoff_participants'];

    private const COLUMNS = ['in_at', 'out_at', 'marked_by', 'marked_at'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'in_at')) {
                    $t->dateTime('in_at')->nullable()->after('attendance_source');
                }
                if (! Schema::hasColumn($table, 'out_at')) {
                    $t->dateTime('out_at')->nullable()->after('in_at');
                }
                if (! Schema::hasColumn($table, 'marked_by')) {
                    $t->unsignedBigInteger('marked_by')->nullable()->after('out_at');
                }
                if (! Schema::hasColumn($table, 'marked_at')) {
                    $t->timestamp('marked_at')->nullable()->after('marked_by');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $columns = array_values(array_filter(
                    self::COLUMNS,
                    fn ($c) => Schema::hasColumn($table, $c),
                ));

                if ($columns) {
                    $t->dropColumn($columns);
                }
            });
        }
    }
};
