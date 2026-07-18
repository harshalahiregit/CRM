<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared kickoff-meeting feature.
 *
 * Deliberately NOT a TPV table: meetings attach polymorphically to any
 * allowlisted subject (vendor/onboarding today, project next), because the brief
 * calls for one shared KickoffMeeting that both TPV and Shivam's Project&Task
 * module can plug into. Nothing here references a vendor by column.
 *
 * The MoM is a plain uploaded-document path for now — no PDF generation
 * dependency is being added without group sign-off — but the acknowledgement
 * workflow (public token → acknowledged_at) is fully built, since that is what
 * the brief specifies and it needs no new dependency.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kickoff_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            // The subject. Nullable so a meeting can be scheduled before it is
            // wired to a specific record.
            $table->nullableMorphs('kickoffable');

            $table->string('reference')->nullable();
            $table->string('title');
            $table->text('agenda')->nullable();
            $table->string('status')->default('Scheduled');   // Scheduled | Delayed | Completed | Cancelled

            // Scheduling.
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('mode')->nullable();       // online | onsite
            $table->string('location')->nullable();   // venue or meeting link
            // Why a Delayed meeting slipped — a coordinator reporting on late
            // kickoffs needs the reason, not just the new date.
            $table->timestamp('original_scheduled_at')->nullable();
            $table->string('delay_reason')->nullable();

            // Minutes of meeting — uploaded document, not generated (see class doc).
            $table->string('mom_path')->nullable();
            $table->text('minutes')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Vendor acknowledgement via a public token link. The 48-char token
            // is a bearer credential — hidden on the model, minted when minutes
            // are published, cleared once acknowledged.
            $table->string('ack_token', 64)->nullable()->unique();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by_name')->nullable();
            $table->string('acknowledged_ip')->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
        });

        // Attendee registry. A row per attendee rather than a JSON blob so
        // attendance can be marked per person and a vendor contact can be linked
        // back to the master record.
        Schema::create('kickoff_attendees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id')->index();

            // Links back to the vendor master when the attendee is a known
            // contact; free name/email otherwise (an external party, a regulator).
            $table->unsignedBigInteger('vendor_contact_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('organisation')->nullable();
            $table->string('role')->nullable();   // Chair | Vendor | HSSE | Guest …

            $table->boolean('attended')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'kickoff_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kickoff_attendees');
        Schema::dropIfExists('kickoff_meetings');
    }
};
