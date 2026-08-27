<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-recipient delivery ledger for a meeting (Meeting.docx §13 — "maintain
 * Sent / Viewed / Acknowledged").
 *
 * Until now those three were tracked on the meeting itself: one
 * `mom_distributed_at`, one `mom_viewed_at`, one `acknowledged_at`. With five
 * recipients that answers "was it sent?" but never "who has actually read it?",
 * which is the question a coordinator chasing an acknowledgement is asking.
 *
 * One row per recipient per send. `kind` separates the two sends a meeting has:
 * the invitation (§1 "Schedule / Send Invitation") and the minutes (§13). The
 * meeting-level stamps are NOT removed — they stay the headline state and every
 * existing reader keeps working; this is the detail underneath them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_distributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id')->index();

            // invite | mom — the two sends a meeting makes.
            $table->string('kind', 16)->default('mom');

            // Who this went to. attendee_id when the recipient is on the roster;
            // a vendor/client recipient resolved from the master has none.
            $table->unsignedBigInteger('kickoff_attendee_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // §13's recipient groups: internal | vendor | client | management | other.
            $table->string('party', 20)->default('other');
            $table->string('name')->nullable();
            $table->string('email')->nullable();

            // email | in_app | whatsapp | sms
            $table->string('channel', 16)->default('email');

            // sent | skipped | failed — skipped is the honest state for a
            // recipient with no address, which must not read as delivered.
            $table->string('status', 16)->default('sent');
            $table->string('error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();
            $table->index(['tenant_id', 'kickoff_meeting_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_distributions');
    }
};
