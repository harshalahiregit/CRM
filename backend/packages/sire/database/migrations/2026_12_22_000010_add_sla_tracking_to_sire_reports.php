<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — SLA clock tracking.
 *
 * The SLA STATE stays computed. What is stored here is elapsed pause, which is a
 * fact about the timeline rather than a derived verdict. The alternative — walking
 * audit_logs to reconstruct every pause interval — would put an audit query behind
 * every dashboard tile and every list row, on a database shared by two deployments.
 */
return new class extends Migration
{
    private function columns(): array
    {
        return [
            // Clocks run from here, not from created_at, so a reopen can restart
            // them instead of resurrecting an issue that is instantly breached.
            'sla_started_at' => fn (Blueprint $t) => $t->dateTime('sla_started_at')->nullable(),

            // Accumulated paused minutes, closed out each time the issue resumes.
            'sla_paused_minutes' => fn (Blueprint $t) => $t->unsignedInteger('sla_paused_minutes')->default(0),

            // Set while currently paused; null otherwise.
            'sla_paused_since' => fn (Blueprint $t) => $t->dateTime('sla_paused_since')->nullable(),

            // Highest state already notified per clock, so the 15-minute sweep
            // sends one warning and one breach — not one of each, every 15 minutes,
            // forever. This is the whole anti-spam mechanism for SLA.
            'sla_ack_notified_state'     => fn (Blueprint $t) => $t->string('sla_ack_notified_state', 16)->nullable(),
            'sla_resolve_notified_state' => fn (Blueprint $t) => $t->string('sla_resolve_notified_state', 16)->nullable(),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('sire_reports')) {
            return;
        }

        foreach ($this->columns() as $name => $add) {
            if (Schema::hasColumn('sire_reports', $name)) {
                continue;
            }
            Schema::table('sire_reports', function (Blueprint $table) use ($add) {
                $add($table);
            });
        }

        // Backfill: every existing row starts its clock at creation.
        \Illuminate\Support\Facades\DB::table('sire_reports')
            ->whereNull('sla_started_at')
            ->update(['sla_started_at' => \Illuminate\Support\Facades\DB::raw('created_at')]);

        // `sla_notified_state` was added in the Phase 0 create migration and is
        // superseded by the two per-clock columns above. Dropped rather than left
        // in place: SIRE has never been deployed, so the column has never held a
        // row, and a dead column that looks like it controls notification dedupe
        // is a trap for whoever reads this table next. This is a deliberate
        // exception to the additive-only convention, safe only because there is
        // no data anywhere.
        if (Schema::hasColumn('sire_reports', 'sla_notified_state')) {
            Schema::table('sire_reports', function (Blueprint $table) {
                $table->dropColumn('sla_notified_state');
            });
        }

        Schema::table('sire_reports', function (Blueprint $table) {
            // Drives the scheduled sweep: open issues whose clock is running.
            $table->index(['tenant_id', 'status', 'sla_started_at'], 'sire_rep_sla_sweep_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sire_reports')) {
            return;
        }

        Schema::table('sire_reports', function (Blueprint $table) {
            $table->dropIndex('sire_rep_sla_sweep_idx');
            $table->dropColumn(array_keys($this->columns()));
        });
    }
};
