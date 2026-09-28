<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where, and on what, each person joined the meeting from.
 *
 * Some of this was already captured and then thrown into a sentence:
 * MeetingJoinRecorder wrote "Opened the meeting 14 Mar 2026, 10:04 from Mobile
 * on Chrome (10.2.4.9)" into the roster's free-text `remark`. Three problems
 * with that, and they are the reason for these columns:
 *
 *  - `remark` is the column a person types a note into, so the evidence sat in
 *    the same field as "joined late, apologised" and could be overwritten by
 *    anybody editing the row;
 *  - it is a string, so nothing could read it back — not the minutes, not the
 *    attendance register, not a query asking which joins came from one address;
 *  - only the SHARED roster has a `remark` column. Purchase's does not, and the
 *    recorder checked for it and silently skipped. Every Purchase meeting
 *    recorded no evidence at all.
 *
 * Geolocation follows the house pattern already set by contract_signatures: the
 * browser offers coordinates if the person allows it, and they are stored
 * beside a human label. Nothing here calls out to a geo-IP service — that would
 * put a third party between this app and an attendance record, and an address
 * resolved by someone else's database is not evidence of anything.
 *
 * A null column means it was not offered, which is different from zero.
 */
return new class extends Migration
{
    private const TABLES = ['kickoff_attendees', 'purchase_kickoff_participants'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $add = function (string $col, callable $make) use ($table, $t) {
                    if (! Schema::hasColumn($table, $col)) {
                        $make($t);
                    }
                };

                $add('join_ip', fn ($t) => $t->string('join_ip', 45)->nullable());
                $add('join_user_agent', fn ($t) => $t->string('join_user_agent', 255)->nullable());
                // "Mobile · Chrome" — the coarse classification UserAgentInfo
                // already produces, stored so the register need not re-parse a
                // 200-character string every time it draws a row.
                $add('join_device', fn ($t) => $t->string('join_device', 60)->nullable());
                $add('join_latitude', fn ($t) => $t->decimal('join_latitude', 10, 7)->nullable());
                $add('join_longitude', fn ($t) => $t->decimal('join_longitude', 10, 7)->nullable());
                $add('join_location_label', fn ($t) => $t->string('join_location_label', 160)->nullable());
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
                foreach ([
                    'join_ip', 'join_user_agent', 'join_device',
                    'join_latitude', 'join_longitude', 'join_location_label',
                ] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
