<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organiser's verdict, beside the attendee's claim — never on top of it.
 *
 * Marking attendance in the CRM is a claim: this account said it was attending,
 * at this time, from this device. It is not proof of sitting through a call held
 * on Google's or Zoom's servers, because the CRM cannot see that call.
 *
 * So the organiser decides, and the whole point is that BOTH records survive.
 * "Punched CRM attendance but did not join the call" is only expressible if the
 * claim (`attended`, `attendance_source`, `joined_at`, `remark`) is still there
 * to contradict. Writing the verdict into `attendance_status` would have erased
 * the very thing being contradicted, which is why these are new columns rather
 * than a reuse of the existing ones.
 *
 * Three slabs, and Partial_Absent carries the times: joined 9:30, left 10:00,
 * on a meeting booked until 11:00. Without a window, "partial" says only that
 * somebody was unhappy — not what actually happened.
 *
 * Both engines get the same columns, because the two rosters were built
 * separately and every difference between them has cost us a defect.
 */
return new class extends Migration
{
    /** kickoff_attendees (shared) and purchase_kickoff_participants (Purchase). */
    private const TABLES = ['kickoff_attendees', 'purchase_kickoff_participants'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'verdict')) {
                    // Fully_Present | Partial_Absent | Complete_Absent.
                    // NULL means the organiser has not reviewed this person yet,
                    // which is a real and different state from "absent".
                    $t->string('verdict', 20)->nullable()->after('attendance_source');
                }
                if (! Schema::hasColumn($table, 'verdict_from')) {
                    // The window they were ACTUALLY active for. Only meaningful
                    // on Partial_Absent; the other two slabs speak for themselves.
                    $t->dateTime('verdict_from')->nullable()->after('verdict');
                }
                if (! Schema::hasColumn($table, 'verdict_to')) {
                    $t->dateTime('verdict_to')->nullable()->after('verdict_from');
                }
                if (! Schema::hasColumn($table, 'verdict_note')) {
                    // "User punched CRM attendance but did not join the call."
                    $t->text('verdict_note')->nullable()->after('verdict_to');
                }
                if (! Schema::hasColumn($table, 'verdict_by')) {
                    // Who decided. A verdict with no author is an assertion
                    // nobody owns, and this one can cost somebody their record.
                    $t->unsignedBigInteger('verdict_by')->nullable()->after('verdict_note');
                }
                if (! Schema::hasColumn($table, 'verdict_at')) {
                    $t->timestamp('verdict_at')->nullable()->after('verdict_by');
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
                    ['verdict', 'verdict_from', 'verdict_to', 'verdict_note', 'verdict_by', 'verdict_at'],
                    fn ($c) => Schema::hasColumn($table, $c),
                ));

                if ($columns) {
                    $t->dropColumn($columns);
                }
            });
        }
    }
};
