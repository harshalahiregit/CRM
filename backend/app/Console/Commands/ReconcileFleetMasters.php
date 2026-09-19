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
 * prints.
 *
 * ── TWO DIFFERENT THINGS USED TO PRINT THE SAME SENTENCE ──────────────────
 * Every unmigrated row was reported as "AMBIGUOUS, matches N Fleet vehicles",
 * followed by "resolve by correcting the duplicate plates". With N = 1 there is
 * no duplicate to correct, and the reader goes looking for something that is
 * not there. Person 1 hit exactly that.
 *
 * One match is not an ambiguity, it is a BROKEN LINK: the truck is in Fleet,
 * the plate agrees, and only `legacy_transport_vehicle_id` is wrong — which is
 * what a reseed of the legacy table does. That case is repairable without a
 * judgement call, so `--relink` repairs it and nothing else. Anything that
 * needs a person is still only reported.
 */
class ReconcileFleetMasters extends Command
{
    protected $signature = 'stos:reconcile-fleet
                            {--relink : repair links where exactly one Fleet vehicle carries the plate}
                            {--company= : limit to one company}';

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

        $moved = $repairable = $ambiguous = $missing = [];

        foreach ($legacy as $row) {
            if (DB::table('vehicles')->where('legacy_transport_vehicle_id', $row->id)->exists()) {
                $moved[] = $row;

                continue;
            }

            // Same normalisation the migration uses: a plate is written
            // "MH 12 AB 1234" as often as "MH12AB1234" and they are one truck.
            $plate = $this->normalise($row->registration_number);

            $candidates = DB::table('vehicles')
                ->where('company_id', $row->tenant_id)
                ->whereNull('deleted_at')
                ->get(['id', 'registration_number', 'legacy_transport_vehicle_id'])
                ->filter(fn ($v) => $this->normalise($v->registration_number) === $plate)
                ->values();

            $entry = ['legacy_id' => $row->id, 'plate' => $row->registration_number, 'candidates' => $candidates];

            match (true) {
                $candidates->count() === 0 => $missing[] = $entry,
                $candidates->count() === 1 => $repairable[] = $entry,
                default => $ambiguous[] = $entry,
            };
        }

        $outstanding = count($repairable) + count($ambiguous) + count($missing);

        $this->line("Vehicles: {$legacy->count()} legacy rows — ".count($moved).' migrated, '.$outstanding.' outstanding.');

        if ($outstanding === 0) {
            $this->info('  Nothing needs a person. The legacy table can be retired on schedule.');

            return;
        }

        $this->newLine();

        foreach ($missing as $row) {
            // Should not happen: the migration inserts these. Worth saying
            // loudly rather than reporting a tidy zero.
            $this->warn("  · #{$row['legacy_id']} {$row['plate']} — no Fleet vehicle carries this plate and none was inserted. Re-run the migration.");
        }

        foreach ($ambiguous as $row) {
            $plates = $row['candidates']->pluck('registration_number')->implode(', ');
            $ids = $row['candidates']->pluck('id')->implode(', ');

            $this->warn("  · #{$row['legacy_id']} {$row['plate']} — AMBIGUOUS, {$row['candidates']->count()} Fleet vehicles carry this plate (ids {$ids}: {$plates}).");
            $this->line('      Needs a person: correct the duplicate plates in Fleet, then re-run the migration.');
            $this->line("      Do not guess — a wrong match attaches one truck's fuel and job history to another.");
        }

        if ($repairable !== []) {
            $this->reportRepairable($repairable);
        }
    }

    /**
     * Exactly one Fleet vehicle carries the plate — so the link is knowable.
     *
     * The only reason it is not already set is that something overwrote the
     * legacy side: a reseed gives the same truck a new `transport_vehicles` id
     * and the stored link keeps pointing at the row that used to be there.
     *
     * Repairing it is still refused when the Fleet vehicle is already linked to
     * a legacy row that STILL EXISTS. That is two live legacy rows competing
     * for one Fleet vehicle, which is a decision, not a repair.
     */
    private function reportRepairable(array $repairable): void
    {
        $relink = (bool) $this->option('relink');

        $this->line('  These are not ambiguous — exactly one Fleet vehicle carries the plate, and only the');
        $this->line('  stored link is wrong. That is what a reseed of the legacy table leaves behind.');
        $this->newLine();

        $repaired = 0;

        foreach ($repairable as $row) {
            $fleet = $row['candidates']->first();
            $held = $fleet->legacy_transport_vehicle_id;

            $contested = $held
                && (int) $held !== (int) $row['legacy_id']
                && DB::table('transport_vehicles')->where('id', $held)->exists();

            if ($contested) {
                $this->warn("  · #{$row['legacy_id']} {$row['plate']} — Fleet #{$fleet->id} is already linked to live legacy #{$held}.");
                $this->line('      Needs a person: two legacy rows claim one Fleet vehicle.');

                continue;
            }

            if (! $relink) {
                $this->line("  · #{$row['legacy_id']} {$row['plate']} — Fleet #{$fleet->id}"
                    .($held ? " (link points at #{$held}, which is gone)" : ' (no link stored)').'. Repairable.');

                continue;
            }

            DB::table('vehicles')->where('id', $fleet->id)
                ->update(['legacy_transport_vehicle_id' => $row['legacy_id']]);

            $repaired++;
            $this->info("  · #{$row['legacy_id']} {$row['plate']} — relinked to Fleet #{$fleet->id}.");
        }

        $this->newLine();

        if ($relink) {
            $this->info("  Repaired {$repaired} link".($repaired === 1 ? '' : 's').'.');

            return;
        }

        $this->line('  Re-run with --relink to repair them. It only writes `legacy_transport_vehicle_id`,');
        $this->line('  and only where one plate matches one vehicle — nothing else is touched.');
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
