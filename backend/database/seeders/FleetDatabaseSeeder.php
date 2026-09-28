<?php

namespace Database\Seeders;

use App\Domains\Fleet\Models\FastagTransaction;
use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * STOS-FLEET — a minimum working fleet to develop and test against.
 *
 * Four vehicles chosen to light up EVERY state of the status grid, because a
 * fleet that is all green proves nothing about the traffic light:
 *
 *   MH12AB1234  green  — reefer, genset running, pinging, papers valid
 *   MH14CD5678  amber  — reefer with a device that has never reported
 *   MH03QR7788  amber  — tipper in the workshop on an open job card
 *   MH09XY4321  red    — dry truck blocked on compliance, no device fitted
 *
 * Deliberately idempotent: every row is written with updateOrCreate on its
 * natural key, so running this twice is a no-op rather than a duplicate fleet
 * or a unique-constraint crash. Re-running also RESETS the live row on the
 * healthy truck, which is what makes it useful after testing an excursion.
 *
 * Nothing here is authenticated, so BelongsToCompany cannot resolve the company
 * from a session — `company_id` is passed explicitly on every insert. It
 * follows the first tenant on the install, which is the workspace a developer
 * is signed into locally.
 */
class FleetDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = (int) (Tenant::orderBy('id')->value('id') ?? 1);

        $fleet = [
            [
                'registration_number' => 'MH12AB1234',
                'vehicle_type'        => 'reefer',
                'ownership_type'      => 'owned',
                'chassis_number'      => 'MAT477050N3K12345',
                'engine_number'       => 'ENG3K12345',
                'gps_device_id'       => 'DEV-TEST-0001',
                'status'              => 'AVAILABLE',
                'registration_expiry' => now()->addYears(3)->toDateString(),
                'insurance_expiry'    => now()->addMonths(7)->toDateString(),
                'fitness_expiry'      => now()->addMonths(5)->toDateString(),
                'permit_expiry'       => now()->addYear()->toDateString(),
                'puc_expiry'          => now()->addMonths(4)->toDateString(),
            ],
            [
                'registration_number' => 'MH14CD5678',
                'vehicle_type'        => 'reefer',
                'ownership_type'      => 'leased',
                'chassis_number'      => 'MAT477050N3K67890',
                'engine_number'       => 'ENG3K67890',
                'gps_device_id'       => 'DEV-TEST-0002',
                'status'              => 'AVAILABLE',
                // PUC falls due inside the warning window: amber, not blocked.
                'puc_expiry'          => now()->addDays(12)->toDateString(),
                'insurance_expiry'    => now()->addMonths(9)->toDateString(),
            ],
            [
                'registration_number' => 'MH03QR7788',
                'vehicle_type'        => 'tipper',
                'ownership_type'      => 'owned',
                'gps_device_id'       => 'DEV-TEST-0003',
                'status'              => 'UNDER_MAINTENANCE',
                'compliance_status'   => 'compliant',
            ],
            [
                'registration_number' => 'MH09XY4321',
                'vehicle_type'        => 'truck',
                'ownership_type'      => 'attached',
                'gps_device_id'       => null,          // nothing tracking it
                'status'              => 'AVAILABLE',
                // Insurance lapsed last month — this is what blocks dispatch.
                'insurance_expiry'    => now()->subMonth()->toDateString(),
                'fitness_expiry'      => now()->addMonths(8)->toDateString(),
            ],
        ];

        $vehicles = [];

        foreach ($fleet as $row) {
            $vehicles[$row['registration_number']] = Vehicle::updateOrCreate(
                ['company_id' => $companyId, 'registration_number' => $row['registration_number']],
                collect($row)->except('registration_number')->all()
            );
        }

        // Every vehicle gets its live-status row, exactly as onboarding through
        // VehicleService would create it. The seeder writes models directly, so
        // without this the seeded fleet breaks the invariant that real
        // onboarding guarantees: one live row per vehicle, from day one.
        foreach ($vehicles as $vehicle) {
            VehicleLiveStatus::firstOrCreate(
                ['vehicle_id' => $vehicle->id],
                ['company_id' => $companyId, 'last_ping_at' => null]
            );
        }

        // ── One genset, fitted to the first reefer ──────────────────
        Genset::updateOrCreate(
            ['company_id' => $companyId, 'serial_number' => 'GEN-TEST-0001'],
            ['vehicle_id' => $vehicles['MH12AB1234']->id, 'status' => 'active']
        );

        // ── Live status (tier 1) for the healthy truck only ─────────
        // Mumbai, moving, reefer holding -18.5 C. The second reefer is left
        // WITHOUT a live row on purpose: that is what "device never reported"
        // looks like, and it is the state most fleets actually have.
        VehicleLiveStatus::updateOrCreate(
            ['vehicle_id' => $vehicles['MH12AB1234']->id],
            [
                'company_id'       => $companyId,
                'latitude'         => '19.07609500',
                'longitude'        => '72.87765800',
                'speed'            => '46.50',
                'ignition'         => true,
                'generator_status' => 'on',
                'temperature'      => '-18.50',
                'last_ping_at'     => now()->subMinutes(2),
            ]
        );

        // ── An open job card, so the workshop state is real ─────────
        MaintenanceJob::updateOrCreate(
            ['company_id' => $companyId, 'job_card_number' => 'JC-2026-0001'],
            [
                'vehicle_id'  => $vehicles['MH03QR7788']->id,
                'complaint'   => 'Hydraulic tipper body slow to lift; whine under load.',
                'diagnosis'   => 'Pump pressure low — awaiting replacement seal kit.',
                'parts_cost'  => '18500.00',
                'labour_cost' => '4200.00',
                'total_cost'  => '22700.00',
                'status'      => 'awaiting_parts',
            ]
        );

        // ── Cost history on the healthy truck, so its passport is not blank ──
        $fuelRuns = [
            ['ref' => 'seed-fuel-1', 'litres' => '412.755', 'rate' => '94.37', 'amount' => '38951.71', 'odo' => '184320.5', 'vendor' => 'HP Nashik Bypass'],
            ['ref' => 'seed-fuel-2', 'litres' => '388.120', 'rate' => '94.90', 'amount' => '36832.59', 'odo' => '185960.0', 'vendor' => 'IOC Vashi'],
        ];

        foreach ($fuelRuns as $i => $run) {
            FuelTransaction::updateOrCreate(
                [
                    'company_id'     => $companyId,
                    'vehicle_id'     => $vehicles['MH12AB1234']->id,
                    'station_vendor' => $run['vendor'],
                    'odometer'       => $run['odo'],
                ],
                [
                    'litres'          => $run['litres'],
                    'rate_per_litre'  => $run['rate'],
                    'amount'          => $run['amount'],
                    'is_emergency'    => false,
                    'recovery_status' => 'not_applicable',
                ]
            );
        }

        // A third fill that FAILS the benchmark, plus an emergency one, so the
        // variance flag and the recovery workflow are visible in the UI without
        // anyone having to stage them by hand. Written with the derived columns
        // set explicitly: the seeder bypasses FuelService, which is where the
        // calculation normally happens.
        FuelTransaction::updateOrCreate(
            [
                'company_id'     => $companyId,
                'vehicle_id'     => $vehicles['MH12AB1234']->id,
                'station_vendor' => 'Roadside dealer, NH-48',
                'odometer'       => '186600.0',
            ],
            [
                'litres'          => '250.000',
                'rate_per_litre'  => '101.40',
                'amount'          => '25350.00',
                'is_emergency'    => true,
                'emergency_reason' => 'Ran dry short of the depot; fuel card not accepted',
                'customer_recoverable' => true,
                'recovery_status' => 'billable',
                'km_driven'       => '640.0',
                'efficiency_kmpl' => '2.56',
                'fuel_exception'  => true,
                'variance_note'   => '2.56 km/l against a benchmark of 2.8 — 9% below, over 640 km.',
            ]
        );

        $crossings = [
            ['plaza' => 'Kherdi', 'amount' => '830.00', 'at' => '2026-09-14 11:42:07'],
            ['plaza' => 'Talegaon', 'amount' => '615.00', 'at' => '2026-09-14 15:08:51'],
        ];

        foreach ($crossings as $crossing) {
            FastagTransaction::updateOrCreate(
                [
                    'company_id'            => $companyId,
                    'tag_id'                => 'TAG-TEST-0001',
                    'transaction_timestamp' => $crossing['at'],
                ],
                [
                    'vehicle_id'            => $vehicles['MH12AB1234']->id,
                    'plaza_name'            => $crossing['plaza'],
                    'amount'                => $crossing['amount'],
                    'reconciliation_status' => 'unreconciled',
                ]
            );
        }

        // Verdicts are DERIVED, never seeded: run the same engine the gate uses.
        app(\App\Domains\Fleet\Services\ComplianceService::class)->refreshAll($companyId);

        $this->command?->info("Fleet seeded for company {$companyId}: 4 vehicles (green/amber/amber/red), 1 genset, 1 live row, 1 open job card, 3 fuel fills (1 flagged emergency), 2 toll crossings.");
        $this->command?->info('Live device: DEV-TEST-0001. Never-reported device: DEV-TEST-0002.');
    }
}
