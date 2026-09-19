<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * D-109 — repoint trip references from the legacy masters onto Fleet.
 *
 * This was inside the data-move migration and should never have been. Moving
 * the rows and repointing the keys are two different decisions: the first is
 * safe on its own, the second is only safe at the exact moment Operations
 * switches its readers from `transport_vehicles` to `vehicles`.
 *
 * Doing it early orphans every trip SILENTLY — the vehicle and driver fields
 * just go blank on screen, with no error — and it cannot be rolled back,
 * because by then the Fleet rows are live masters carrying their own history.
 *
 * So it is a command, it defaults to a dry run, and it has to be asked for.
 *
 * ── THE MAP IS NOT STORED, BUT THE VERDICT IS ─────────────────────────────
 * The mapping is reconstructed from `legacy_transport_vehicle_id` /
 * `legacy_transport_driver_id` on each run, because a stored map is one more
 * thing that can go stale between the move and the switch. It does go stale:
 * a reseed of the legacy table left ours pointing at rows 29 and 30 while the
 * live rows for the same two trucks were 35 and 36, and the dry run then read
 * "0 rows" — which looks like "nothing to do" and actually meant "the map
 * matches nothing". `--relink` on `stos:reconcile-fleet` repairs that, and
 * this command now says so rather than reporting a tidy zero.
 *
 * What IS written down is what this command decided about each reference, in
 * `fleet_reference_repoints`. That exists for D-116: after the switch, nothing
 * in a trip row says which id space its number is in, and the references this
 * command could NOT map are still sitting in the old one. A verdict per row —
 * including "this pointed at something that is gone" — is what lets telemetry
 * answer that question from data instead of from the shape of the number.
 *
 * It also makes the switch reversible, which it was not before.
 */
class RepointTripFleetReferences extends Command
{
    protected $signature = 'stos:repoint-trip-fleet-refs
                            {--apply : actually write; without this it only reports}
                            {--force : apply even though some references cannot be mapped}
                            {--company= : limit to one company}';

    protected $description = 'D-109 — point transport_trips and trip_assignments at the Fleet masters (dry run unless --apply)';

    private const LEDGER = 'fleet_reference_repoints';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $vehicleMap = $this->legacyMap('vehicles', 'legacy_transport_vehicle_id');
        $driverMap  = $this->legacyMap('driver_profiles', 'legacy_transport_driver_id');

        if ($vehicleMap === [] && $driverMap === []) {
            $this->info('Nothing has been moved into the Fleet masters yet — there is nothing to repoint.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Mapping: %d vehicles, %d drivers.', count($vehicleMap), count($driverMap)));
        $this->newLine();

        if (! $apply) {
            $this->warn('DRY RUN — nothing will be written. Add --apply when Operations switches its readers.');
            $this->newLine();
        }

        $targets = [
            ['transport_trips', 'vehicle_id', $vehicleMap],
            ['transport_trips', 'driver_id', $driverMap],
            ['trip_assignments', 'vehicle_id', $vehicleMap],
            ['trip_assignments', 'driver_id', $driverMap],
        ];

        $plan = [];

        foreach ($targets as [$table, $column, $map]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $map === []) {
                continue;
            }

            $plan[] = [$table, $column, $map, $this->survey($table, $column, $map)];
        }

        $movable = array_sum(array_map(fn ($p) => count($p[3]['movable']), $plan));
        $stranded = array_sum(array_map(fn ($p) => count($p[3]['stranded']), $plan));

        foreach ($plan as [$table, $column, , $survey]) {
            $this->line(sprintf('  %-18s %-12s %d to move, %d pointing at ids that no longer map',
                $table, $column, count($survey['movable']), count($survey['stranded'])));
        }

        $this->newLine();

        if ($movable === 0 && $stranded === 0) {
            $this->info('Every reference has already been ruled on. Nothing left to do.');

            return self::SUCCESS;
        }

        // "0 to move" used to print as a clean zero and read as "done". It is
        // the opposite when the map itself is stale, so say which one it is.
        if ($movable === 0) {
            $this->warn('Nothing can be moved: every reference points at a legacy id the mapping does not cover.');
            $this->line('  That is usually a stale `legacy_transport_vehicle_id` rather than finished work.');
            $this->line('  Run `php artisan stos:reconcile-fleet` — and `--relink` if it reports repairable links.');
            $this->newLine();
        }

        if (! $apply) {
            $this->line("Nothing written. Re-run with --apply once Operations reads Fleet's tables —");
            $this->line('until then, repointing these would blank the vehicle and driver on every trip.');

            return self::SUCCESS;
        }

        // A reference that cannot be mapped is recorded as stranded and left
        // where it is. That is the safe outcome, but it is also permanent, so
        // it is not something to discover afterwards.
        if ($stranded > 0 && ! $this->option('force')) {
            $this->error("{$stranded} references point at legacy rows with no Fleet counterpart.");
            $this->line('  They will be left alone and marked unmatchable — telemetry and any other');
            $this->line('  reader will refuse them from then on rather than guess which truck they meant.');
            $this->line('  Fix the mapping first, or pass --force if stranding them is intended.');

            return self::FAILURE;
        }

        $moved = 0;

        foreach ($plan as [$table, $column, , $survey]) {
            $moved += $this->apply($table, $column, $survey);
        }

        Log::channel('stos')->warning('Trip references repointed onto the Fleet masters', [
            'defect' => 'D-109', 'rows' => $moved, 'stranded' => $stranded,
        ]);

        $this->newLine();
        $this->info("Repointed {$moved} rows; {$stranded} recorded as unmatchable.");
        $this->line('The legacy tables still hold their rows and can still be read. Nothing was dropped.');
        $this->line('Every decision is in `'.self::LEDGER.'`, so this can be read back and undone.');

        return self::SUCCESS;
    }

    /**
     * old legacy id => new Fleet id, reconstructed from the moved rows.
     */
    private function legacyMap(string $table, string $column): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        $company = $this->option('company');

        return DB::table($table)
            ->whereNotNull($column)
            ->when($company, fn ($q) => $q->where('company_id', $company))
            ->pluck('id', $column)
            ->all();
    }

    /**
     * What this column holds today, split into what maps and what does not.
     *
     * Rows already ruled on are skipped as a FACT rather than by hoping a Fleet
     * id does not collide with a legacy one. The old version assumed "a
     * repointed row no longer matches any legacy id", which is the same kind of
     * luck D-116 was about.
     */
    private function survey(string $table, string $column, array $map): array
    {
        $company = $this->option('company');
        $ruled = $this->alreadyRuled($table, $column);

        $movable = $stranded = [];

        DB::table($table)
            ->whereNotNull($column)
            ->when($company, fn ($q) => $q->where('tenant_id', $company))
            ->orderBy('id')
            ->select(['id', 'tenant_id', $column])
            ->chunk(500, function ($rows) use ($column, $map, $ruled, &$movable, &$stranded) {
                foreach ($rows as $row) {
                    if (isset($ruled[(int) $row->id])) {
                        continue;
                    }

                    $old = (int) $row->{$column};
                    $entry = ['id' => (int) $row->id, 'tenant_id' => (int) $row->tenant_id, 'from' => $old];

                    if (isset($map[$old])) {
                        $entry['to'] = (int) $map[$old];
                        $movable[] = $entry;
                    } else {
                        $entry['to'] = null;
                        $stranded[] = $entry;
                    }
                }
            });

        return ['movable' => $movable, 'stranded' => $stranded];
    }

    /** row id => true, for references this command has already decided. */
    private function alreadyRuled(string $table, string $column): array
    {
        if (! Schema::hasTable(self::LEDGER)) {
            return [];
        }

        return DB::table(self::LEDGER)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->pluck('row_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** Write the verdicts, then move the ones that have somewhere to go. */
    private function apply(string $table, string $column, array $survey): int
    {
        $moved = 0;

        foreach (array_chunk(array_merge($survey['movable'], $survey['stranded']), 200) as $batch) {
            DB::transaction(function () use ($table, $column, $batch, &$moved) {
                $ledger = [];

                foreach ($batch as $row) {
                    $ledger[] = [
                        'company_id' => $row['tenant_id'],
                        'table_name' => $table, 'column_name' => $column,
                        'row_id' => $row['id'],
                        'from_id' => $row['from'], 'to_id' => $row['to'],
                        'applied_at' => now(),
                    ];

                    if ($row['to'] !== null) {
                        DB::table($table)->where('id', $row['id'])->update([$column => $row['to']]);
                        $moved++;
                    }
                }

                // The verdict is written in the same transaction as the move it
                // describes. A ledger that can disagree with the data would be
                // worse than no ledger at all.
                DB::table(self::LEDGER)->insert($ledger);
            });
        }

        return $moved;
    }
}
