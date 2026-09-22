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
        Schema::rename('task_party_assignees', 'party_assignees');

        Schema::table('party_assignees', function (Blueprint $table) {
            // 'task' | 'project'. Defaulted so the rename needs no backfill and
            // so a row written without one is a task, which is what every row
            // would have been.
            $table->string('subject_type', 32)->default('task')->after('tenant_id');
        });

        Schema::table('party_assignees', function (Blueprint $table) {
            $table->renameColumn('task_id', 'subject_id');
        });

        /*
         * The old indexes still carry task_id in their names and their columns.
         * SQLite rebuilds a table on renameColumn and carries indexes across by
         * column, so they keep working — but the UNIQUE one is now wrong: it
         * says "one person per subject id", and a task and a project can share
         * an id. It has to include the subject type.
         */
        Schema::table('party_assignees', function (Blueprint $table) {
            $table->dropUnique('task_party_unique');
            $table->unique(['subject_type', 'subject_id', 'party_type', 'party_id'], 'party_subject_unique');
        });
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
