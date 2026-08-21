<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MOM two-level approval + distribution tracking (Meeting.docx §12/§13).
 *
 * Adds the Organizer approval stamp (the Chairperson approval reuses the existing
 * mom_approved_at/by as the FINAL sign-off), a first-viewed timestamp so
 * distribution can show Sent / Viewed / Acknowledged, and the vendor's response
 * type (acknowledged / disputed / correction-requested).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->timestamp('mom_organizer_approved_at')->nullable()->after('mom_approval_note');
            $table->unsignedBigInteger('mom_organizer_approved_by')->nullable()->after('mom_organizer_approved_at');
            $table->timestamp('mom_viewed_at')->nullable()->after('mom_distributed_by');
            $table->string('acknowledgement_response_type', 24)->nullable()->after('acknowledgement_comment');
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropColumn([
                'mom_organizer_approved_at', 'mom_organizer_approved_by',
                'mom_viewed_at', 'acknowledgement_response_type',
            ]);
        });
    }
};
