<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured minutes-of-meeting items for the shared kickoff engine.
 *
 * kickoff_meetings.minutes (free text) and mom_path (an uploaded/generated PDF)
 * both stay exactly as they are — a meeting minuted as prose before this table
 * existed still renders. This adds the *itemised* form: one row per action point,
 * so a target date is a date and a responsible person is a person, rather than a
 * sentence somebody has to read.
 *
 * Responsible person is a kickoff_attendees id, not a polymorphic pair. The
 * attendee row already carries the identity (vendor_contact_id OR user_id OR a
 * free name/email for an external party) and is the only list the UI offers, so
 * a second polymorphic indirection would resolve to the same row while allowing
 * a responsible person who was never in the meeting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kickoff_mom_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id')->index();

            $table->text('description');

            // The attendee accountable for this item. Nullable: an item can be
            // captured during the meeting before anyone owns it, and the
            // attendee may later be removed from the meeting.
            $table->unsignedBigInteger('responsible_attendee_id')->nullable()->index();

            $table->text('remark')->nullable();
            $table->text('notes')->nullable();
            $table->date('target_date')->nullable();

            // Explicit ordering — items are an agenda, and "3 before 4" must
            // survive a re-save that happens to touch rows out of id order.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->index(['tenant_id', 'kickoff_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kickoff_mom_items');
    }
};
