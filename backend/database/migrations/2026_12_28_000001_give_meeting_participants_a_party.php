<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which side of the table is this person sitting on?
 *
 * The attendance sheet is four columns — Organiser (internal team), Client,
 * Vendor, Third-Party Vendor — and until now a participant row could not say
 * which one it belonged to. `side` answers internal-or-external, which splits
 * the four into one and three; `role` is free text from a list that mixes job
 * titles with meeting duties ("HSE Manager", "Note-taker"), so it cannot be
 * read as a party either. The organisation name was the only hint, and it is a
 * typed string.
 *
 * So a meeting saved from the grid and reopened had to guess where to put
 * everybody, and guessed wrong for every external party.
 *
 * `party_ref` records WHERE the person came from — 'tpv_worker:12',
 * 'client_contact:4' — as an opaque string rather than a foreign key. A key
 * would tie purchase_kickoff_participants to TPV's tables and kickoff_attendees
 * to Purchase's, which is the one thing these two engines are built not to do.
 * The existing vendor_contact_id / purchase_contact_id columns stay as they
 * are: each still points only within its own module.
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
                if (! Schema::hasColumn($table, 'party')) {
                    // organiser | client | vendor | tpv
                    $t->string('party', 16)->nullable()->after('side');
                }
                if (! Schema::hasColumn($table, 'party_ref')) {
                    $t->string('party_ref', 64)->nullable()->after('party');
                }
            });

            // Back-fill what can be known for certain. Everyone marked internal
            // is an organiser; nobody else can be placed without guessing, and a
            // guess here would move real people into the wrong column of a
            // meeting that already happened. Those rows stay null and the grid
            // shows them in an "unassigned" row for somebody to place.
            DB::table($table)->where('side', 'internal')->whereNull('party')
                ->update(['party' => 'organiser']);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                foreach (['party', 'party_ref'] as $col) {
                    if (Schema::hasColumn($table, $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
