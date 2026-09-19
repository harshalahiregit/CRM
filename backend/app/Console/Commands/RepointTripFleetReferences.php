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
 * ── THE MAP IS NOT STORED ANYWHERE ────────────────────────────────────────
 * Every moved row carries `legacy_transport_vehicle_id` / `legacy_transport_driver_id`,
 * so the mapping is reconstructed from the data each time. A stored map would be
 * one more thing that can go stale between the move and the switch.
 */
class RepointTripFleetReferences extends Command
{
    protected $signature = 'stos:repoint-trip-fleet-refs
                            {--apply : actually write; without this it only reports}
                            {--company= : limit to one company}';

    protected $description = 'D-109 — point transport_trips and trip_assignments at the Fleet masters (dry run unless --apply)';

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

        $total = 0;

        foreach ($targets as [$table, $column, $map]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $map === []) {
                continue;
            }

            $count = $this->processOne($table, $column, $map, $apply);
            $total += $count;

            $this->line(sprintf('  %-18s %-12s %s row%s',
                $table, $column, $count, $count === 1 ? '' : 's'));
        }

        $this->newLine();

        if (! $apply) {
            $this->line("Nothing written. Re-run with --apply once Operations reads Fleet's tables —");
            $this->line('until then, repointing these would blank the vehicle and driver on every trip.');

            return self::SUCCESS;
        }

        Log::channel('stos')->warning('Trip references repointed onto the Fleet masters', [
            'defect' => 'D-109', 'rows' => $total,
        ]);

        $this->info("Repointed {$total} rows.");
        $this->line('The legacy tables still hold their rows and can still be read. Nothing was dropped.');

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
     * Only rows that still point at a legacy id are touched.
     *
     * Re-running is therefore harmless: a row already repointed no longer
     * matches any legacy id, so it is skipped rather than double-mapped onto
     * whatever Fleet row happens to share that number.
     */
    private function processOne(string $table, string $column, array $map, bool $apply): int
    {
        $count = 0;

        foreach ($map as $oldId => $newId) {
            $rows = DB::table($table)->where($column, $oldId)->count();

            if ($rows === 0) {
                continue;
            }

            $count += $rows;

            if ($apply) {
                DB::table($table)->where($column, $oldId)->update([$column => $newId]);
            }
        }

        return $count;
    }
}
