<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9 — agenda item supporting-documents field + previous-discussion reference.
 * The Agenda→Discussion→Decision→Action chain already exists on the item; these
 * add the doc's remaining agenda fields. Additive/nullable — shared by both the
 * TPV and Purchase meeting flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_agenda_items', function (Blueprint $table) {
            $table->json('supporting_documents')->nullable()->after('decision');
            $table->string('previous_discussion_ref', 200)->nullable()->after('supporting_documents');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_agenda_items', function (Blueprint $table) {
            $table->dropColumn(['supporting_documents', 'previous_discussion_ref']);
        });
    }
};
