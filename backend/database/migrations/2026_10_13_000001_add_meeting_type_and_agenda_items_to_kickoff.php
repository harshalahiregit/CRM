<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A / slice 1 of the Meetings engine (Meeting.docx).
 *
 * The shared kickoff engine becomes a general Meetings engine: "kickoff" stops
 * being the only kind of meeting and becomes ONE configurable meeting_type. This
 * is purely additive — meeting_type defaults to 'kickoff', so every existing row
 * and every existing consumer (TPV is the only one; Purchase/Projects have their
 * own separate engines) is untouched and keeps behaving exactly as before.
 *
 * It also adds a STRUCTURED agenda (meeting_agenda_items) beside the existing
 * free-text kickoff_meetings.agenda column — modelled on kickoff_mom_items — so an
 * agenda item's owner is a person and its duration is a number, not one sentence.
 * The free-text agenda column stays and is left completely alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            // Kickoff is now one meeting type among many. Defaulted so nothing that
            // omits it — every existing row and caller — changes behaviour.
            $table->string('meeting_type')->default('kickoff')->after('title');
            $table->index(['tenant_id', 'meeting_type']);
        });

        Schema::create('meeting_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id')->index();

            // The agenda topic (what will be discussed) + optional supporting detail.
            $table->string('item');
            $table->text('description')->nullable();

            // The attendee who owns this item. Nullable: an agenda can be drafted
            // before owners are assigned, and an attendee may later be removed.
            $table->unsignedBigInteger('owner_attendee_id')->nullable()->index();
            // Free-typed owner names for anyone not (yet) an attendee row — mirrors
            // kickoff_mom_items.responsible_names.
            $table->string('owner_names', 500)->nullable();

            $table->unsignedSmallInteger('duration_minutes')->nullable();
            // Low | Medium | High — a plain string, validated in the request layer.
            $table->string('priority', 20)->nullable();

            // Explicit ordering — an agenda's "item 3 before item 4" must survive a
            // re-save that touches rows out of id order.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->index(['tenant_id', 'kickoff_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_agenda_items');
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'meeting_type']);
            $table->dropColumn('meeting_type');
        });
    }
};
