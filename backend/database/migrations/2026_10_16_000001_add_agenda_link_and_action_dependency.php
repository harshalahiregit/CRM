<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agenda-item MOM structure + action dependencies (Meeting.docx §7/§8).
 *
 * A decision can now be tied to the agenda item it was taken under (mom items
 * already carry agenda_item_id), and an action can declare that it depends on
 * another action (both within the same meeting). Both are nullable soft links —
 * an unlinked decision/action stays perfectly valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_decisions', function (Blueprint $table) {
            $table->unsignedBigInteger('agenda_item_id')->nullable()->after('kickoff_meeting_id');
        });

        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            // Self-reference: this action cannot start until depends_on_id is done.
            $table->unsignedBigInteger('depends_on_id')->nullable()->after('agenda_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_decisions', function (Blueprint $table) {
            $table->dropColumn('agenda_item_id');
        });
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->dropColumn('depends_on_id');
        });
    }
};
