<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase kickoff participants — one row per attendee on a Purchase kickoff
 * meeting. Links back to a Purchase-vendor contact (purchase_contacts) when the
 * attendee is a known contact; a free name/email is allowed otherwise.
 * Purchase-owned — independent of kickoff_attendees.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_kickoff_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_kickoff_meeting_id')->index();

            $table->unsignedBigInteger('purchase_contact_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('organisation')->nullable();
            $table->string('role')->nullable();   // Chair | Vendor | Procurement | Guest …

            $table->boolean('attended')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'purchase_kickoff_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_kickoff_participants');
    }
};
