<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry-forward provenance (Meeting.docx — "previous meeting's open items are
 * carried into the next"). When an open action or issue is rolled into a new
 * meeting, the fresh record points back at the one it came from. That link is
 * what stops the same item being carried twice: carry-forward only offers open
 * items that are not already the origin of a later record.
 *
 * Self-referencing id, deliberately without an FK constraint — same convention
 * the rest of these tables use (converted_id etc.), and it keeps a delete of the
 * origin meeting from cascading into unrelated child rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->unsignedBigInteger('carried_from_id')->nullable()->after('agenda_item_id')->index();
        });

        Schema::table('meeting_issues', function (Blueprint $table) {
            $table->unsignedBigInteger('carried_from_id')->nullable()->after('kickoff_meeting_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_mom_items', function (Blueprint $table) {
            $table->dropColumn('carried_from_id');
        });

        Schema::table('meeting_issues', function (Blueprint $table) {
            $table->dropColumn('carried_from_id');
        });
    }
};
