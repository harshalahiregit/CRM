<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-62 — what moved into Fleet, and what a person still has to decide.
 *
 * The data-move migration deliberately refuses ambiguous plates: when a
 * normalised registration matches more than one Fleet vehicle, no automatic
 * rule can say which truck it is, and a wrong match silently attaches one
 * vehicle's fuel and maintenance history to another. The migration leaves those
 * rows alone and says to run this.
 *
 * So this exists to answer one question: after the migration, is there anything
 * left in the old tables that a human needs to look at? It reads, counts and
 * prints. It writes nothing — resolving an ambiguity is a decision, and this
 * command's whole purpose is to put that decision in front of somebody.
 */
class ReconcileFleetMasters extends Command
{
    protected $signature = 'stos:reconcile-fleet {--company= : limit to one company}';

    protected $description = 'Report which legacy transport vehicles and drivers are still unmigrated, and why';

    public function handle(): int
    {
        if (! Schema::hasTable('transport_vehicles')) {
            $this->info('No legacy `transport_vehicles` table — nothing to reconcile.');

            return self::SUCCESS;
        }

        $company = $this->option('company');

        $this->reconcileVehicles($company);
        $this->newLine();
        $this->reconcileDrivers($company);

        return self::SUCCESS;
    }

    private function reconcileVehicles(?string $company): void
    {
        $legacy = DB::table('transport_vehicles')
            ->when($company, fn ($q) => $q->where('tenant_id', $company))
            ->get(['id', 'tenant_id', 'registration_number']);

        if ($legacy->isEmpty()) {
            $this->info('Vehicles: the legacy table is empty. Nothing outstanding.');

            return;
        }

        $moved = $pending = [];

        foreach ($legacy as $row) {
            $already = DB::table('vehicles')->where('legacy_transport_vehicle_id', $row->id)->exists();

            if ($already) {
                $moved[] = $row;

                continue;
            }

            // Same normalisation the migration uses: a plate is written
            // "MH 12 AB 1234" as often as "MH12AB1234" and they are one truck.
            $plate = $this->normalise($row->registration_number);

            $candidates = DB::table('vehicles')
                ->where('company_id', $row->tenant_id)
                ->whereNull('deleted_at')
                ->get(['id', 'registration_number'])
                ->filter(fn ($v) => $this->normalise($v->registration_number) === $plate)
                ->values();

            $pending[] = [
                'legacy_id'  => $row->id,
                'plate'      => $row->registration_number,
                'candidates' => $candidates,
            ];
        }

        $this->line("Vehicles: {$legacy->count()} legacy rows — ".count($moved).' migrated, '.count($pending).' outstanding.');

        if ($pending === []) {
            $this->info('  Nothing needs a person. The legacy table can be retired on schedule.');

            return;
        }

        $this->newLine();
        $this->warn('  These were NOT migrated. Each needs a decision:');

        foreach ($pending as $row) {
            $count = $row['candidates']->count();

            if ($count === 0) {
                // Should not happen: the migration inserts these. Worth saying
                // loudly rather than reporting a tidy zero.
                $this->line("  · #{$row['legacy_id']} {$row['plate']} — no Fleet match and not inserted. Re-run the migration.");

                continue;
            }

            $plates = $row['candidates']->pluck('registration_number')->implode(', ');
            $ids = $row['candidates']->pluck('id')->implode(', ');

            $this->line("  · #{$row['legacy_id']} {$row['plate']} — AMBIGUOUS, matches {$count} Fleet vehicles (ids {$ids}: {$plates}).");
        }

        $this->newLine();
        $this->line('  Resolve by correcting the duplicate plates in Fleet, then re-running the migration.');
        $this->line('  Do not guess: a wrong match attaches one truck\'s fuel and job history to another.');
    }

    private function reconcileDrivers(?string $company): void
    {
        if (! Schema::hasTable('transport_drivers')) {
            $this->info('Drivers: no legacy table.');

            return;
        }

        $legacy = DB::table('transport_drivers')
            ->when($company, fn ($q) => $q->where('tenant_id', $company))
            ->count();

        if ($legacy === 0) {
            $this->info('Drivers: the legacy table is empty. Nothing outstanding.');

            return;
        }

        $migrated = DB::table('driver_profiles')->whereNotNull('legacy_transport_driver_id')->count();

        $this->line("Drivers: {$legacy} legacy rows — {$migrated} have a Fleet profile.");

        if ($migrated < $legacy) {
            $this->warn('  The remainder have no profile yet.');
            $this->line('  Fleet stores no names: a driver is a reference into the CRM directory plus');
            $this->line('  a licence. A legacy driver who is not a person in the CRM has to be created');
            $this->line('  there first — that is the design, not a gap.');
        }
    }

    private function normalise(?string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $plate));
    }
}
