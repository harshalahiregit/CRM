<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — who else wants to hear about this issue.
 *
 * The reporter and the assignee are told about an issue because they are ON it.
 * A watcher is everyone else with a reason to care and no role that says so: the
 * lead of the module it broke in, the account manager whose customer filed it,
 * the developer who wrote the code last quarter. Without this they find out by
 * asking, and the answer to "what happened to that bug" arrives in a chat
 * channel that is not tenant-scoped, not audited and not searchable.
 *
 * Deliberately NOT a column on sire_reports. Watching is many-to-many and
 * changes far more often than the issue does; a JSON array on the row would turn
 * every subscribe into a read-modify-write race between two people clicking at
 * once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_report_watchers')) {
            return;
        }

        Schema::create('sire_report_watchers', function (Blueprint $table) {
            $table->id();

            // NOT NULL like every other SIRE table: the auto-stamp does nothing
            // in a command or a job, and a nullable column absorbs that mistake
            // into rows that belong to nobody.
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('report_id');
            $table->unsignedBigInteger('user_id');

            // Who added them, so "why am I getting these" has an answer. Null
            // when somebody watched an issue themselves.
            $table->unsignedBigInteger('added_by')->nullable();

            $table->timestamps();

            // One row per person per issue. Watching twice is watching once, and
            // the database is the only place that can promise it under a race.
            $table->unique(['tenant_id', 'report_id', 'user_id'], 'sire_watch_unique');

            // The read is always "who watches this issue" or "what do I watch".
            $table->index(['tenant_id', 'report_id'], 'sire_watch_report');
            $table->index(['tenant_id', 'user_id'], 'sire_watch_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_report_watchers');
    }
};
