<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimum-viable SLA engine schema.
 *
 *  - ticket_priorities gains per-priority targets (first_response_hours,
 *    resolution_hours) — configured through the existing settings list-manager.
 *  - ticket_statuses gains sla_paused — a status can pause the SLA clock
 *    (e.g. "waiting on customer"), reusing the Phase 1 custom-status system.
 *  - tickets gains the computed-SLA support columns: when the first staff reply
 *    landed, when it was resolved, and accumulated paused time.
 *
 * SLA due dates are NOT stored — they are computed from created_at + target
 * (± paused time) so they stay correct as targets change. `due_date` stays a
 * separate, manual agent deadline and is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_priorities', function (Blueprint $table) {
            $table->unsignedInteger('first_response_hours')->nullable()->after('order');
            $table->unsignedInteger('resolution_hours')->nullable()->after('first_response_hours');
        });

        Schema::table('ticket_statuses', function (Blueprint $table) {
            $table->boolean('sla_paused')->default(false)->after('is_closed_status');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('first_responded_at')->nullable()->after('due_date');
            $table->timestamp('resolved_at')->nullable()->after('first_responded_at');
            $table->timestamp('sla_paused_at')->nullable()->after('resolved_at');
            $table->unsignedBigInteger('sla_paused_seconds')->default(0)->after('sla_paused_at');
        });

        // Backfill sensible targets on existing priorities (by name) so current
        // tenants have working SLAs immediately, no reseed needed.
        $targets = ['low' => [8, 48], 'medium' => [4, 24], 'high' => [2, 8], 'urgent' => [1, 4]];
        foreach ($targets as $name => [$fr, $res]) {
            DB::table('ticket_priorities')->where('name', $name)
                ->update(['first_response_hours' => $fr, 'resolution_hours' => $res]);
        }

        // Give every existing tenant a paused "pending" status (waiting on customer)
        // so the pause feature is usable out of the box.
        foreach (DB::table('ticket_statuses')->distinct()->pluck('tenant_id') as $tid) {
            $exists = DB::table('ticket_statuses')->where('tenant_id', $tid)->where('name', 'pending')->exists();
            if (! $exists) {
                $maxOrder = (int) DB::table('ticket_statuses')->where('tenant_id', $tid)->max('order');
                DB::table('ticket_statuses')->insert([
                    'tenant_id' => $tid, 'name' => 'pending', 'color' => '#f59e0b',
                    'order' => $maxOrder + 1, 'is_closed_status' => false, 'sla_paused' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Seed resolved_at for already-closed tickets so historical SLA isn't blank.
        DB::table('tickets')->whereIn('status', ['closed', 'merged'])->whereNull('resolved_at')
            ->update(['resolved_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('ticket_priorities', fn (Blueprint $t) => $t->dropColumn(['first_response_hours', 'resolution_hours']));
        Schema::table('ticket_statuses', fn (Blueprint $t) => $t->dropColumn('sla_paused'));
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn(['first_responded_at', 'resolved_at', 'sla_paused_at', 'sla_paused_seconds']));
    }
};
