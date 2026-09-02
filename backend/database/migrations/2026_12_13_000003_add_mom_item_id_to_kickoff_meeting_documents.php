<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a document belong to a specific MoM action item as well as the meeting,
 * so per-action evidence is ALSO multiple + labelled (through the same store).
 * Meeting-level documents keep kickoff_mom_item_id = null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meeting_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('kickoff_mom_item_id')->nullable()->after('kickoff_meeting_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('kickoff_meeting_documents', function (Blueprint $table) {
            $table->dropColumn('kickoff_mom_item_id');
        });
    }
};
