<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-62, step 2 — move the rows.
 *
 * Carries `transport_vehicles` and `transport_drivers` into the Fleet masters
 * and repoints every foreign key that referenced them.
 *
 * ── The one rule this migration will not break ───────────────────────────
 * It moves a vehicle ONLY when its normalised plate is unambiguous: absent from
 * Fleet (a clean insert) or matching exactly one Fleet row (a merge into it).
 * Anything ambiguous is LEFT ALONE and reported by `stos:reconcile-fleet`.
 *
 * A wrong automatic match silently attaches one truck's fuel, telemetry and
 * workshop history to a different truck. That is unrecoverable once a human
 * stops remembering which was which, so the machine refuses to guess and asks.
 *
 * Drivers are moved differently, and deliberately: Fleet stores NO names. A
 * driver who cannot be matched to a person in the CRM directory becomes a row
 * in `stos_drivers` (the standalone register) and the profile points at that —
 * so no name is lost, and no name is duplicated into `driver_profiles`.
 *
 * Idempotent: `legacy_transport_*_id` records what has already moved, so
 * re-running is a no-op rather than a second copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transport_vehicles')) {
            return;     // Operations module not installed on this deployment
        }

        $vehicleMap = $this->moveVehicles();
        $driverMap = $this->moveDrivers();

        $this->repointForeignKeys($vehicleMap, $driverMap);
    }

    /** @return array<int,int> old transport_vehicles id => new vehicles id */
    private function moveVehicles(): array
    {
        $map = [];

        DB::table('transport_vehicles')->orderBy('id')->chunk(200, function ($rows) use (&$map) {
            foreach ($rows as $row) {
                $companyId = (int) $row->tenant_id;
                $plate = $this->normalise($row->registration_number);

                // Already moved by an earlier run.
                $moved = DB::table('vehicles')->where('legacy_transport_vehicle_id', $row->id)->value('id');
                if ($moved) {
                    $map[(int) $row->id] = (int) $moved;

                    continue;
                }

                $matches = DB::table('vehicles')
                    ->where('company_id', $companyId)
                    ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(registration_number,' ',''),'-',''),'+','')) = ?", [$plate])
                    ->pluck('id');

                if ($matches->count() > 1) {
                    continue;   // ambiguous — left for a person, see the command
                }

                $map[(int) $row->id] = $matches->count() === 1
                    ? $this->mergeInto((int) $matches->first(), $row)
                    : $this->insertNew($companyId, $plate, $row);
            }
        });

        return $map;
    }

    /**
     * Fleet's row wins on the operational columns it owns; Operations' row
     * fills only what Fleet has blank. A vehicle that already accumulated fuel
     * and telemetry must not have its identity rewritten underneath it.
     */
    private function mergeInto(int $vehicleId, $row): int
    {
        $fill = array_filter([
            'registration_normalized' => $this->normalise($row->registration_number),
            'fleet_number'       => $row->fleet_number ?? null,
            'manufacturer'       => $row->manufacturer ?? null,
            'model'              => $row->model ?? null,
            'variant'            => $row->variant ?? null,
            'manufacturing_year' => $row->manufacturing_year ?? null,
            'purchase_date'      => $row->purchase_date ?? null,
            'fuel_type'          => $row->fuel_type ?? null,
            'branch'             => $row->branch ?? null,
            'capacity_tonnes'    => $row->capacity_tonnes ?? null,
            'chassis_number'     => $row->chassis_number ?? null,
            'engine_number'      => $row->engine_number ?? null,
            'gps_device_id'      => $row->gps_device_id ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $existing = DB::table('vehicles')->where('id', $vehicleId)->first();

        foreach (array_keys($fill) as $column) {
            // Only fill a gap — never overwrite what Fleet already holds.
            if (($existing->{$column} ?? null) !== null && $existing->{$column} !== '') {
                unset($fill[$column]);
            }
        }

        // A device id already claimed by another vehicle would break the unique
        // index and, worse, misroute telemetry. Drop it and let the reconcile
        // command surface it.
        if (isset($fill['gps_device_id']) && DB::table('vehicles')
            ->where('company_id', $existing->company_id)
            ->where('gps_device_id', $fill['gps_device_id'])
            ->where('id', '!=', $vehicleId)->exists()) {
            unset($fill['gps_device_id']);
        }

        $fill['legacy_transport_vehicle_id'] = $row->id;
        $fill['updated_at'] = now();

        DB::table('vehicles')->where('id', $vehicleId)->update($fill);

        return $vehicleId;
    }

    private function insertNew(int $companyId, string $plate, $row): int
    {
        $deviceTaken = ! empty($row->gps_device_id) && DB::table('vehicles')
            ->where('company_id', $companyId)->where('gps_device_id', $row->gps_device_id)->exists();

        $vehicleId = DB::table('vehicles')->insertGetId([
            'company_id'              => $companyId,
            'registration_number'     => $plate,
            'registration_normalized' => $plate,
            'fleet_number'            => $row->fleet_number ?? null,
            'vehicle_type'            => $this->mapType($row->vehicle_type ?? null),
            'ownership_type'          => $this->mapOwnership($row->ownership_type ?? null),
            'manufacturer'            => $row->manufacturer ?? null,
            'model'                   => $row->model ?? null,
            'variant'                 => $row->variant ?? null,
            'manufacturing_year'      => $row->manufacturing_year ?? null,
            'purchase_date'           => $row->purchase_date ?? null,
            'fuel_type'               => $row->fuel_type ?? null,
            'branch'                  => $row->branch ?? null,
            'capacity_tonnes'         => $row->capacity_tonnes ?? null,
            'chassis_number'          => $row->chassis_number ?? null,
            'engine_number'           => $row->engine_number ?? null,
            'gps_device_id'           => $deviceTaken ? null : ($row->gps_device_id ?? null),
            // Status is DERIVED in Fleet — a job card moves a vehicle in and out
            // of the workshop. A migrated vehicle starts active; the nightly
            // compliance sweep sets its real verdict within hours.
            'status'                  => 'active',
            'compliance_status'       => 'compliant',
            'created_by'              => $row->created_by ?? null,
            'updated_by'              => $row->updated_by ?? null,
            'legacy_transport_vehicle_id' => $row->id,
            'created_at'              => $row->created_at ?? now(),
            'updated_at'              => now(),
        ]);

        // Fleet's invariant: one live-status row per vehicle, from birth, so
        // telemetry ingestion is a pure primary-key update for its whole life.
        DB::table('vehicle_live_status')->insertOrIgnore([
            'vehicle_id' => $vehicleId, 'company_id' => $companyId, 'last_ping_at' => null,
        ]);

        return $vehicleId;
    }

    /** @return array<int,int> old transport_drivers id => driver_profiles id */
    private function moveDrivers(): array
    {
        if (! Schema::hasTable('transport_drivers')) {
            return [];
        }

        $map = [];

        DB::table('transport_drivers')->orderBy('id')->chunk(200, function ($rows) use (&$map) {
            foreach ($rows as $row) {
                $companyId = (int) $row->tenant_id;

                $moved = DB::table('driver_profiles')->where('legacy_transport_driver_id', $row->id)->value('id');
                if ($moved) {
                    $map[(int) $row->id] = (int) $moved;

                    continue;
                }

                // The name lives in a directory, never in driver_profiles. With
                // no CRM person to point at, the standalone register is the
                // directory — which is what it exists for.
                $standaloneId = DB::table('stos_drivers')->insertGetId([
                    'company_id'    => $companyId,
                    'name'          => $row->name ?: 'Unnamed driver',
                    'phone'         => $row->mobile ?? null,
                    'designation'   => 'Driver',
                    'external_ref'  => $row->driver_code ?? null,
                    'created_at'    => $row->created_at ?? now(),
                    'updated_at'    => now(),
                ]);

                $profileId = DB::table('driver_profiles')->insertGetId([
                    'company_id'     => $companyId,
                    'source'         => 'stos',
                    'source_id'      => $standaloneId,
                    'hr_employee_id' => $row->hr_employee_id ?? null,
                    'supplier_id'    => $row->supplier_id ?? null,
                    'driver_code'    => $row->driver_code ?? null,
                    'licence_number' => $row->licence_number ?? null,
                    'licence_normalized' => $row->licence_normalized ?? null,
                    'licence_class'  => $row->licence_class ?? null,
                    'licence_valid_from'  => $row->licence_valid_from ?? null,
                    'licence_expiry' => $row->licence_valid_until ?? null,
                    'status'         => $this->mapDriverStatus($row),
                    'legacy_transport_driver_id' => $row->id,
                    'created_at'     => $row->created_at ?? now(),
                    'updated_at'     => now(),
                ]);

                $map[(int) $row->id] = $profileId;
            }
        });

        return $map;
    }

    /**
     * Repoint the trips. A trip pointing at a vehicle id that no longer means
     * anything is worse than no trip at all — it silently reads another truck.
     */
    private function repointForeignKeys(array $vehicleMap, array $driverMap): void
    {
        foreach ([['transport_trips', 'vehicle_id', $vehicleMap], ['transport_trips', 'driver_id', $driverMap]] as [$table, $column, $map]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $map === []) {
                continue;
            }

            foreach ($map as $oldId => $newId) {
                DB::table($table)->where($column, $oldId)->update([$column => $newId]);
            }
        }

        if (Schema::hasTable('trip_assignments')) {
            foreach ($vehicleMap as $oldId => $newId) {
                if (Schema::hasColumn('trip_assignments', 'vehicle_id')) {
                    DB::table('trip_assignments')->where('vehicle_id', $oldId)->update(['vehicle_id' => $newId]);
                }
            }
            foreach ($driverMap as $oldId => $newId) {
                if (Schema::hasColumn('trip_assignments', 'driver_id')) {
                    DB::table('trip_assignments')->where('driver_id', $oldId)->update(['driver_id' => $newId]);
                }
            }
        }
    }

    /* ── vocabulary mapping ─────────────────────────────────────── */

    private function mapType(?string $type): string
    {
        return match (strtoupper((string) $type)) {
            'REEFER'         => 'reefer',
            'CONTAINER_BODY' => 'truck',
            'FLATBED'        => 'truck',
            'TRAILER'        => 'trailer',
            'TANKER'         => 'tanker',
            'TIPPER'         => 'tipper',
            'LCV'            => 'lcv',
            default          => 'other',
        };
    }

    private function mapOwnership(?string $ownership): string
    {
        return match (strtoupper((string) $ownership)) {
            'OWNED'      => 'owned',
            'LEASED'     => 'leased',
            'ATTACHED'   => 'attached',
            'CONTRACTED' => 'attached',
            'FINANCED'   => 'owned',
            default      => 'owned',
        };
    }

    private function mapDriverStatus($row): string
    {
        $status = strtolower((string) ($row->status ?? ''));
        $availability = strtolower((string) ($row->availability ?? ''));

        if ($status === 'suspended' || $status === 'blocked') {
            return 'suspended';
        }
        if ($status && $status !== 'active') {
            return 'inactive';
        }

        return $availability === 'on_trip' ? 'on_trip' : 'available';
    }

    private function normalise(?string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $plate)));
    }

    public function down(): void
    {
        // Deliberately not reversible by deletion: the rows are now the live
        // masters and may have accumulated telemetry, fuel and workshop history
        // since. The legacy tables are left intact by `up()`, so recovery is
        // re-pointing the code back, not deleting merged rows.
    }
};
