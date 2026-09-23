<?php

namespace Tests\Feature\Stos;

use App\Console\Commands\RepointTripFleetReferences;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

/**
 * D-120 — the repoint has to move EVERY reference, not the ones we remembered.
 *
 * `stos:repoint-trip-fleet-refs` listed four columns inline. There are seven.
 * Person 1 found the three that were missing — `trip_exceptions.vehicle_id`,
 * `trip_exceptions.driver_id`, `trip_advances.driver_id` — and the reason it
 * mattered is the reason it was hard to see: after `--apply`, `vehicle_id`
 * would have meant Fleet in two tables and the legacy master in two others, in
 * one schema, with nothing recording which. It would have passed silently,
 * because nothing loads `$exception->vehicle` today.
 *
 * A list written by hand gets out of date the first time somebody adds a table.
 * This is the test that makes that a failure instead of a surprise: it derives
 * the truth from the SCHEMA and compares it to the declared list.
 */
class RepointCoversEveryReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'T1', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();
    }

    /** The command's declared list. */
    private function declared(): array
    {
        $constants = (new ReflectionClass(RepointTripFleetReferences::class))->getConstants();

        return array_map(
            fn ($ref) => $ref[0].'.'.$ref[1],
            $constants['REFERENCES']
        );
    }

    /**
     * Every tenant-scoped `vehicle_id` / `driver_id` column in the database.
     *
     * Tenant-scoped is the discriminator that matters. Fleet's own tables —
     * `fuel_transactions`, `maintenance_jobs`, `telemetry_records` and the rest
     * — carry `company_id` and already hold Fleet ids, so they are not
     * references INTO the legacy masters and must not be repointed.
     */
    private function inTheSchema(): array
    {
        $found = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if (! Schema::hasColumn($name, 'tenant_id')) {
                continue;
            }

            foreach (['vehicle_id', 'driver_id'] as $column) {
                if (Schema::hasColumn($name, $column)) {
                    $found[] = $name.'.'.$column;
                }
            }
        }

        return $found;
    }

    public function test_every_reference_in_the_schema_is_in_the_commands_list(): void
    {
        $missing = array_diff($this->inTheSchema(), $this->declared());

        $this->assertSame([], array_values($missing),
            'These columns hold a legacy vehicle or driver id and the repoint would leave them '
            .'behind, so one schema would carry two id spaces with nothing marking which. '
            .'Add them to RepointTripFleetReferences::REFERENCES.');
    }

    public function test_the_commands_list_does_not_name_anything_that_is_not_there(): void
    {
        // The other direction. A stale entry is harmless at runtime — the plan
        // loop skips a missing table — but it is a claim about the schema that
        // has stopped being true, and this file is where that gets noticed.
        $extra = array_diff($this->declared(), $this->inTheSchema());

        $this->assertSame([], array_values($extra));
    }

    public function test_the_three_columns_person_1_found_are_actually_moved(): void
    {
        $legacyId = DB::table('transport_vehicles')->insertGetId([
            'tenant_id' => self::COMPANY,
            'registration_number' => 'MH20GH7799', 'registration_normalized' => 'MH20GH7799',
            'vehicle_type' => 'REEFER', 'ownership_type' => 'OWNED', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $legacyDriverId = DB::table('transport_drivers')->insertGetId([
            'tenant_id' => self::COMPANY, 'driver_code' => 'DRV-120',
            'name' => 'Rajesh Kumar', 'mobile' => '9876543210',
            'licence_number' => 'MH0120110012345', 'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYear()->toDateString(),
            'status' => 'active', 'availability' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tripId = $this->trip($legacyId, $legacyDriverId);

        $exceptionId = DB::table('trip_exceptions')->insertGetId([
            'tenant_id' => self::COMPANY, 'trip_id' => $tripId,
            'vehicle_id' => $legacyId, 'driver_id' => $legacyDriverId,
            'exception_number' => 'EX-'.Str::random(6),
            'category' => 'breakdown', 'severity' => 'high', 'status' => 'open',
            'cause' => 'Coolant leak', 'raised_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $advanceId = DB::table('trip_advances')->insertGetId([
            'tenant_id' => self::COMPANY, 'trip_id' => $tripId,
            'driver_id' => $legacyDriverId, 'amount_requested' => 5000,
            'currency' => 'INR', 'purpose' => 'Fuel', 'status' => 'requested',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMover();

        $newVehicleId = (int) DB::table('vehicles')->where('legacy_transport_vehicle_id', $legacyId)->value('id');
        $newDriverId = (int) DB::table('driver_profiles')->where('legacy_transport_driver_id', $legacyDriverId)->value('id');

        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertSuccessful();

        $exception = DB::table('trip_exceptions')->find($exceptionId);
        $advance = DB::table('trip_advances')->find($advanceId);

        $this->assertSame($newVehicleId, (int) $exception->vehicle_id, 'trip_exceptions.vehicle_id');
        $this->assertSame($newDriverId, (int) $exception->driver_id, 'trip_exceptions.driver_id');
        $this->assertSame($newDriverId, (int) $advance->driver_id, 'trip_advances.driver_id');

        // And each one got its own verdict, so telemetry and anything else can
        // tell which id space these rows are in afterwards.
        $this->assertSame(3, DB::table('fleet_reference_repoints')
            ->whereIn('table_name', ['trip_exceptions', 'trip_advances'])->count());
    }

    public function test_a_contested_mapping_stops_the_run_before_it_reports_anything(): void
    {
        $legacyId = DB::table('transport_vehicles')->insertGetId([
            'tenant_id' => self::COMPANY,
            'registration_number' => 'MH20GH7799', 'registration_normalized' => 'MH20GH7799',
            'vehicle_type' => 'REEFER', 'ownership_type' => 'OWNED', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMover();

        // Two Fleet rows claiming one legacy row. `pluck('id', $link)` keys by
        // the legacy id, so the second would silently overwrite the first and
        // the whole repoint would run against a mapping nobody chose.
        DB::table('vehicles')->insert([
            'company_id' => self::COMPANY, 'registration_number' => 'MH20GH7799-DUP',
            'vehicle_type' => 'truck', 'ownership_type' => 'owned',
            'status' => 'AVAILABLE', 'compliance_status' => 'compliant',
            'legacy_transport_vehicle_id' => $legacyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The DRY RUN fails too — numbers computed from a guessed map are worse
        // than no numbers.
        $this->artisan('stos:repoint-trip-fleet-refs')
            ->expectsOutputToContain('The mapping is contested')
            ->assertFailed();
    }

    public function test_two_active_assignments_landing_on_one_vehicle_stop_the_run(): void
    {
        // Without this the legacy row and its Fleet copy are both id 1, the two
        // assignments below are the same assignment, and the fixture trips the
        // index it is supposed to be testing the guard against.
        $this->pushFleetIdsOutOfTheWay();

        $firstLegacy = DB::table('transport_vehicles')->insertGetId([
            'tenant_id' => self::COMPANY,
            'registration_number' => 'MH20GH7799', 'registration_normalized' => 'MH20GH7799',
            'vehicle_type' => 'REEFER', 'ownership_type' => 'OWNED', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMover();

        $fleetId = (int) DB::table('vehicles')->where('legacy_transport_vehicle_id', $firstLegacy)->value('id');

        // One assignment still on the legacy id, one already holding the Fleet
        // id — the partial state the switch itself creates. Both active.
        $this->assignment($this->trip($firstLegacy, null), $firstLegacy);
        $this->assignment($this->trip($fleetId, null), $fleetId);

        // trip_assignments allows one active assignment per vehicle per tenant,
        // through a unique index on a stored generated column. Repointing the
        // first onto the Fleet id trips it partway through the run.
        $this->artisan('stos:repoint-trip-fleet-refs --apply')
            ->expectsOutputToContain('two ACTIVE assignments')
            ->assertFailed();
    }

    /* ── fixtures ───────────────────────────────────────────────── */

    /** Make Fleet ids start well above the legacy ones. */
    private function pushFleetIdsOutOfTheWay(): void
    {
        for ($i = 0; $i < 5; $i++) {
            DB::table('vehicles')->insert([
                'company_id' => self::COMPANY, 'registration_number' => 'MH99PAD'.$i,
                'vehicle_type' => 'truck', 'ownership_type' => 'owned',
                'status' => 'AVAILABLE', 'compliance_status' => 'compliant',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function runMover(): void
    {
        $migration = require database_path('migrations/2027_01_02_000002_move_transport_masters_into_fleet.php');
        $migration->up();
    }

    private function trip(?int $vehicleId, ?int $driverId): int
    {
        $orderId = DB::table('transport_orders')->insertGetId([
            'tenant_id' => self::COMPANY, 'customer_id' => 1,
            'order_number' => 'TO-'.Str::random(6),
            'pickup_location' => json_encode(['city' => 'Mumbai']),
            'delivery_location' => json_encode(['city' => 'Pune']),
            'service_type' => 'FTL', 'required_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('transport_trips')->insertGetId([
            'tenant_id' => self::COMPANY, 'order_id' => $orderId, 'customer_id' => 1,
            'trip_number' => 'TRP-'.Str::random(6), 'status' => 'in_transit',
            'vehicle_id' => $vehicleId, 'driver_id' => $driverId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assignment(int $tripId, int $vehicleId): int
    {
        return DB::table('trip_assignments')->insertGetId([
            'tenant_id' => self::COMPANY, 'trip_id' => $tripId,
            'vehicle_id' => $vehicleId, 'status' => 'assigned',
            'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
