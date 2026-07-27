<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase kickoff meetings — the Purchase module's OWN kickoff engine, fully
 * independent of the shared/TPV kickoff_meetings table. Not polymorphic: a
 * Purchase kickoff always belongs to a Purchase vendor (vendor_id) and,
 * optionally, its onboarding record. Row-level multi-tenancy; no DB foreign keys.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_kickoff_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            // Direct subject — the Purchase vendor (shared Vendor Master identity).
            $table->unsignedBigInteger('vendor_id')->index();
            $table->unsignedBigInteger('purchase_onboarding_id')->nullable()->index();

            $table->string('reference')->nullable();
            $table->string('title');
            $table->text('agenda')->nullable();
            $table->string('status')->default('Scheduled');   // Scheduled | Delayed | Completed | Cancelled

            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('mode')->nullable();       // online | onsite
            $table->string('location')->nullable();
            $table->timestamp('original_scheduled_at')->nullable();
            $table->string('delay_reason')->nullable();

            // MOM: minutes text is edited here; the generated/uploaded PDF lives in
            // purchase_kickoff_mom.
            $table->text('minutes')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Vendor acknowledgement via a public token link.
            $table->string('ack_token', 64)->nullable()->unique();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledged_by_name')->nullable();
            $table->string('acknowledged_ip')->nullable();

            // Online meeting fields (only set when mode = 'online').
            $table->string('meeting_platform')->nullable();
            $table->string('meeting_link', 1024)->nullable();
            $table->string('meeting_id')->nullable();
            $table->string('meeting_passcode')->nullable();
            $table->string('meeting_host_link', 1024)->nullable();

            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vendor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_kickoff_meetings');
    }
};
