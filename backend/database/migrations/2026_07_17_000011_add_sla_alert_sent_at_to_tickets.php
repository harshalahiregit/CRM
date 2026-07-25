<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLA state (at_risk|breached) is computed, never stored — so an alerter has no
 * natural "already told them" marker and would re-send every 15 minutes for the
 * lifetime of a breached ticket. sla_alert_sent_at throttles it: an open ticket
 * is alerted at most once per hour, and the column doubles as the narrowing
 * predicate that keeps the scheduled query off the full ticket table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sla_alert_sent_at')->nullable()->after('sla_paused_seconds');

            // The alerter filters on this first, then narrows by status/priority.
            $table->index('sla_alert_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['sla_alert_sent_at']);
            $table->dropColumn('sla_alert_sent_at');
        });
    }
};
