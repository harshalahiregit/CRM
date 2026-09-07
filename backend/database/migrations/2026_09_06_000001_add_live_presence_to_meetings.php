<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What actually happened, as opposed to what was scheduled.
 *
 * Until now a meeting knew only its slot: scheduled_at, end_at, duration. The
 * clock-derived state was computed from that slot alone, so a meeting everyone
 * left after seven minutes still reported itself "In progress" until the hour
 * was up, and one that started late reported "Expired" while it was being held.
 * Attendance had the same shape of problem — it was a tick somebody made from
 * memory afterwards, because nothing recorded who was actually in the call.
 *
 * These columns hold the record of the call itself:
 *
 *   meetings   actual_start_at   the first person to arrive
 *              actual_end_at     the last person to leave
 *   attendees  joined_at         when this person first arrived
 *              left_at           when they last left
 *              seconds_in_call   accumulated across rejoins, so someone who
 *                                drops and comes back is not counted twice
 *              participant_key   the call's own id for them — the idempotency
 *                                key, so the same arrival reported by three
 *                                different browsers is still one arrival
 *              is_guest          added by the call rather than by the roster:
 *                                somebody who turned up under a name nobody
 *                                had listed. They were still there, so they
 *                                are recorded rather than dropped.
 *
 * Deliberately additive and nullable: every meeting written before today has no
 * record of its call, and must keep reading exactly as it does now.
 */
return new class extends Migration
{
    /** meeting table => attendee table */
    private const PAIRS = [
        'kickoff_meetings'          => 'kickoff_attendees',
        'purchase_kickoff_meetings' => 'purchase_kickoff_participants',
    ];

    public function up(): void
    {
        foreach (self::PAIRS as $meetings => $attendees) {
            if (Schema::hasTable($meetings)) {
                Schema::table($meetings, function (Blueprint $t) use ($meetings) {
                    if (! Schema::hasColumn($meetings, 'actual_start_at')) {
                        $t->dateTime('actual_start_at')->nullable()->after('duration_minutes');
                    }
                    if (! Schema::hasColumn($meetings, 'actual_end_at')) {
                        $t->dateTime('actual_end_at')->nullable()->after('actual_start_at');
                    }
                });
            }

            if (! Schema::hasTable($attendees)) {
                continue;
            }

            Schema::table($attendees, function (Blueprint $t) use ($attendees) {
                if (! Schema::hasColumn($attendees, 'joined_at')) {
                    $t->dateTime('joined_at')->nullable()->after('attendance_status');
                }
                if (! Schema::hasColumn($attendees, 'left_at')) {
                    $t->dateTime('left_at')->nullable()->after('joined_at');
                }
                if (! Schema::hasColumn($attendees, 'seconds_in_call')) {
                    $t->unsignedInteger('seconds_in_call')->default(0)->after('left_at');
                }
                if (! Schema::hasColumn($attendees, 'participant_key')) {
                    $t->string('participant_key', 191)->nullable()->after('seconds_in_call');
                }
                if (! Schema::hasColumn($attendees, 'is_guest')) {
                    $t->boolean('is_guest')->default(false)->after('participant_key');
                }
            });

            // Reconciling a snapshot looks this row up by key on every heartbeat.
            if (Schema::hasColumn($attendees, 'participant_key')) {
                Schema::table($attendees, function (Blueprint $t) use ($attendees) {
                    $t->index(['participant_key'], $attendees.'_pkey_idx');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::PAIRS as $meetings => $attendees) {
            if (Schema::hasTable($meetings)) {
                Schema::table($meetings, fn (Blueprint $t) => $t->dropColumn(['actual_start_at', 'actual_end_at']));
            }
            if (Schema::hasTable($attendees)) {
                Schema::table($attendees, function (Blueprint $t) use ($attendees) {
                    $t->dropIndex($attendees.'_pkey_idx');
                    $t->dropColumn(['joined_at', 'left_at', 'seconds_in_call', 'participant_key', 'is_guest']);
                });
            }
        }
    }
};
