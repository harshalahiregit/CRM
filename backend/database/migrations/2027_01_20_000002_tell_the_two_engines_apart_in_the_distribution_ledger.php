<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery ledger now carries Purchase's meetings too.
 *
 * meeting_distributions was written for the shared engine alone, so
 * `kickoff_meeting_id` meant "a row in kickoff_meetings" and nothing said
 * otherwise. The room-link send exists on both engines, and Purchase meeting #7
 * is a different meeting from shared meeting #7 — with no discriminator the two
 * ledgers would merge and a Purchase resend would wipe a TPV meeting's history.
 *
 * `engine` defaults to 'shared', which is what every existing row is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meeting_distributions') || Schema::hasColumn('meeting_distributions', 'engine')) {
            return;
        }

        Schema::table('meeting_distributions', function (Blueprint $t) {
            // shared | purchase — which table kickoff_meeting_id points into.
            $t->string('engine', 16)->default('shared')->after('kind')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('meeting_distributions') && Schema::hasColumn('meeting_distributions', 'engine')) {
            Schema::table('meeting_distributions', function (Blueprint $t) {
                $t->dropColumn('engine');
            });
        }
    }
};
