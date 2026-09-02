<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation attached to a request.
 *
 * SangoeTrack already has a back-and-forth and destroys it: resubmitting a
 * settlement runs `AdvanceSettlement::where('advance_request_id', $id)->delete()`
 * first, so the original figures, the rejection and its reason are gone. Nobody
 * can answer "what did we ask for, and what did they say" a month later.
 *
 * This is where that exchange lives instead. APPEND ONLY — nothing is edited or
 * removed, the same discipline as an accounting ledger and for the same reason:
 * it is a record of decisions about money. The model enforces it; this table
 * carries no soft-delete column, deliberately, so there is nowhere to hide a
 * retraction.
 *
 * Polymorphic because the same conversation shape fits an advance, a
 * reimbursement, and later a leave request or an attendance correction. One
 * mechanism rather than four.
 *
 * ATTACHMENTS ARE NOT HERE. The `attachments` table already exists, is already
 * polymorphic and tenant-scoped, and already has a download route — a message
 * carrying files points at that rather than inventing a second store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_request_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();

            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');

            // Null for entries the system wrote itself.
            $table->unsignedBigInteger('author_id')->nullable()->index();

            // message — either side, both see it
            // note    — admin to admin, the employee never sees it
            // event   — written by a state change, so the thread IS the history
            $table->enum('kind', ['message', 'note', 'event'])->default('message');

            // Always populated, including for events.
            //
            // An event's text is RENDERED AT WRITE TIME rather than derived on
            // read from event_type and meta. If it were derived, changing the
            // wording later would silently rewrite what the record says happened
            // — which is the one thing an append-only log must never do.
            $table->text('body');

            // For events only: a stable machine-readable name (held, amount_changed,
            // approved, disbursed…) so a client can style or filter without parsing
            // the sentence, plus whatever the event needs to be reconstructed.
            $table->string('event_type', 40)->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'id'], 'hr_req_msg_subject_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'hr_req_msg_tenant_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_request_messages');
    }
};
