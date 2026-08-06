<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor's response when acknowledging the Minutes of Meeting.
 *
 * Lives on kickoff_meetings, beside acknowledged_at / acknowledgement_status,
 * because the comment is feedback about the MINUTES — not about the onboarding
 * that happens to link to them. Putting it here also means the public token
 * flow (PublicKickoffController) and the portal flow store it in one place
 * rather than each growing their own column.
 *
 * Nullable: acknowledging without a comment is the normal case, and every
 * existing caller sends no comment at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->text('acknowledgement_comment')->nullable()->after('acknowledgement_status');
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropColumn('acknowledgement_comment');
        });
    }
};
