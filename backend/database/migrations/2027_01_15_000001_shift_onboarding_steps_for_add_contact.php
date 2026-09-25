<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add Contact became step 1, so every stored step number means one step later.
 *
 * `current_step` is not a label, it is a stored position, and six literal
 * numbers in the two onboarding services are written against it. Inserting a
 * step at the front shifts what every one of those numbers refers to: a vendor
 * sitting at `current_step = 1` meant "Kickoff MOM" yesterday and means "Add
 * Contact" today, so without this every onboarding already under way would
 * appear to have slid backwards one step overnight.
 *
 * Nothing is lost if this is skipped — the magic numbers push a vendor forward
 * again as they complete each step — but for a while the screen would tell
 * people they are somewhere they are not, which on an onboarding wizard is the
 * one thing it exists to get right.
 *
 * ONLY THE UNFINISHED ARE MOVED. An Approved or Rejected onboarding is
 * finished: its step number is a historical record of where it ended, and
 * rewriting history to keep a progress bar tidy is not a trade worth making.
 * They are already at the end of the list either way.
 *
 * No cap is needed and none is applied. The old ceiling was 6 and the new one
 * is 7, so `+ 1` cannot overshoot; the `< 7` guard is only there to make the
 * migration safe to run twice. It is also why this uses increment() rather
 * than LEAST()/GREATEST(), which are MySQL-only — the test suite runs on
 * SQLite, where a migration written in MySQL dialect fails on the first row.
 */
return new class extends Migration
{
    /** Onboardings that are still being worked on. */
    private const UNFINISHED = ['Draft', 'In_Progress', 'Submitted', 'Under_Review', 'On_Hold'];

    private const TABLES = ['purchase_onboardings', 'tpv_onboardings'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'current_step')) {
                continue;
            }

            DB::table($table)
                ->whereIn('status', self::UNFINISHED)
                ->where('current_step', '<', 7)
                ->increment('current_step');
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'current_step')) {
                continue;
            }

            DB::table($table)
                ->whereIn('status', self::UNFINISHED)
                ->where('current_step', '>', 1)
                ->decrement('current_step');
        }
    }
};
