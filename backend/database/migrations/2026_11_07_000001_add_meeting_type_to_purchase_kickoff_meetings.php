<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doc alignment (Sangoe TPV §9 / §39): a kickoff is not a separate concept — it
 * is ONE meeting type under the permanent Meetings function. This gives the
 * Purchase kickoff engine a `meeting_type` column so a meeting can be Kickoff,
 * Vendor Review, HSE, etc. Existing rows default to 'kickoff', so nothing about
 * current data or the onboarding Step-1 finder changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_kickoff_meetings', 'meeting_type')) {
                $table->string('meeting_type', 60)->default('kickoff')->after('title')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_kickoff_meetings', 'meeting_type')) {
                $table->dropIndex(['meeting_type']);
                $table->dropColumn('meeting_type');
            }
        });
    }
};
