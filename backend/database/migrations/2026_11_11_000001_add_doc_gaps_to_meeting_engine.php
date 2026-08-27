<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The remaining Meeting.docx field gaps on the shared meetings engine.
 *
 * All additive and nullable, so every existing row and every existing consumer
 * is untouched:
 *
 *  • kickoff_meetings.organizer   — §2 lists Organizer, Chairperson and
 *    Coordinator as three distinct people. Only the last two existed; the
 *    organiser was implied to be whoever created the record, which is not the
 *    same thing and could not be edited.
 *
 *  • kickoff_meetings.client_id   — §2/§16 want a real Customer on the meeting.
 *    `client_name` (free text) stays and is still what every reader displays;
 *    this is the soft link beside it, resolved through CustomerServiceContract
 *    so the meetings engine never queries the customers table itself.
 *
 *  • meeting_agenda_items.discussion / .decision — §7's MOM structure is
 *    Agenda → Discussion → Decision → Action. Discussion had nowhere to live,
 *    so it collapsed into the meeting-level free-text `minutes`. The Purchase
 *    engine already carries both columns; this brings the shared engine level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('kickoff_meetings', 'organizer')) {
                $table->string('organizer')->nullable()->after('chairperson');
            }
            if (! Schema::hasColumn('kickoff_meetings', 'client_id')) {
                $table->unsignedBigInteger('client_id')->nullable()->after('client_name');
                $table->index(['tenant_id', 'client_id']);
            }
        });

        Schema::table('meeting_agenda_items', function (Blueprint $table) {
            if (! Schema::hasColumn('meeting_agenda_items', 'discussion')) {
                $table->text('discussion')->nullable()->after('description');
            }
            if (! Schema::hasColumn('meeting_agenda_items', 'decision')) {
                $table->text('decision')->nullable()->after('discussion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meeting_agenda_items', function (Blueprint $table) {
            $table->dropColumn(['discussion', 'decision']);
        });

        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'client_id']);
            $table->dropColumn(['organizer', 'client_id']);
        });
    }
};
