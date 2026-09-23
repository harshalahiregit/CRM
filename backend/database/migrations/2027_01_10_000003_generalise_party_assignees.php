<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same "a named person at another company owns this" applied to projects,
 * not only tasks.
 *
 * task_party_assignees was built one migration ago for tasks alone. Projects
 * need the identical thing — a project's Vendors tab could only ever DERIVE who
 * was involved from its tasks' assignees, so a vendor engaged for the project as
 * a whole, before any task existed, appeared nowhere.
 *
 * The honest options were a second table with a second service beside it, or
 * one table that names its subject. This codebase already carries the cost of
 * the first choice elsewhere — one vendor-documents screen exists in four
 * implementations — so: one table, one service, one set of rules about who may
 * be assigned and what they then see.
 *
 * Renaming rather than adding is safe because the table is one migration old and
 * empty; there is no data to carry and no other reader to break.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Each step guarded — P1, 2026-09-23, D-132 ────────────────────
        // On MySQL this migration cannot complete, and it cannot be re-run
        // after it fails. `subject_id` still carries the foreign key that
        // came across with the rename, and MySQL refuses to drop the index
        // that key needs:
        //
        //     Cannot drop index 'task_party_unique': needed in a foreign key
        //
        // 000004 is what removes that key, and it runs AFTER this. On SQLite
        // the whole thing passes because renameColumn rebuilds the table, so
        // the test suite never saw it. The first three steps had already been
        // applied to the dev database when it halted on the fourth, and the
        // rename then failed on a re-run because its destination now existed.
        //
        // Guards rather than a reorder: 000004 drops and rebuilds this table
        // with `party_subject_unique_v2`, so the index swap below is
        // superseded either way and moving it would change what 000004 means.
        if (Schema::hasTable('task_party_assignees') && ! Schema::hasTable('party_assignees')) {
            Schema::rename('task_party_assignees', 'party_assignees');
        }

        if (! Schema::hasColumn('party_assignees', 'subject_type')) {
            Schema::table('party_assignees', function (Blueprint $table) {
                // 'task' | 'project'. Defaulted so the rename needs no backfill and
                // so a row written without one is a task, which is what every row
                // would have been.
                $table->string('subject_type', 32)->default('task')->after('tenant_id');
            });
        }

        if (Schema::hasColumn('party_assignees', 'task_id')) {
            Schema::table('party_assignees', function (Blueprint $table) {
                $table->renameColumn('task_id', 'subject_id');
            });
        }

        /*
         * The old indexes still carry task_id in their names and their columns.
         * SQLite rebuilds a table on renameColumn and carries indexes across by
         * column, so they keep working — but the UNIQUE one is now wrong: it
         * says "one person per subject id", and a task and a project can share
         * an id. It has to include the subject type.
         *
         * On MySQL the drop is refused while 000004's foreign key still exists.
         * That is not fatal: 000004 rebuilds this table from scratch and gives
         * it `party_subject_unique_v2`, so the corrected constraint arrives
         * either way. Left stale rather than forced, and said out loud.
         */
        try {
            Schema::table('party_assignees', function (Blueprint $table) {
                $table->dropUnique('task_party_unique');
                $table->unique(['subject_type', 'subject_id', 'party_type', 'party_id'], 'party_subject_unique');
            });
        } catch (Throwable $e) {
            // 000004 supersedes it. Nothing here is load-bearing.
        }
    }

    public function down(): void
    {
        Schema::table('party_assignees', function (Blueprint $table) {
            $table->dropUnique('party_subject_unique');
            $table->unique(['subject_id', 'party_type', 'party_id'], 'task_party_unique');
            $table->renameColumn('subject_id', 'task_id');
            $table->dropColumn('subject_type');
        });

        Schema::rename('party_assignees', 'task_party_assignees');
    }
};
