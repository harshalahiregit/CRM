<?php

namespace Tests\Feature\Stos;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-62 — the two vehicle/driver systems become one.
 *
 * These tests exist to protect the rows. A migration that silently attaches one
 * truck's fuel and telemetry history to a different truck is unrecoverable once
 * people stop remembering which was which, so the behaviour that matters most
 * here is the REFUSAL: when a plate is ambiguous, the machine leaves it alone.
 *
 * The migrations have already run by the time a test body executes, so each
 * case seeds the legacy tables and re-runs the mover directly.
 */
class VehicleMasterUnificationTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();
    }

    /** Re-run the data-move migration against whatever the test just seeded. */
    /**
     * Make the Fleet ids start well above the legacy ones.
     *
     * Without this the legacy row and its Fleet copy are both id 1 on a fresh
     * database, and every "did it repoint?" assertion passes by coincidence.
     */
    private function pushFleetIdsOutOfTheWay(): void
    {
        for ($i = 0; $i < 5; $i++) {
            DB::table('vehicles')->insert([
                'company_id' => self::COMPANY,
                'registration_number' => 'MH99PAD'.$i,
                'vehicle_type' => 'truck', 'ownership_type' => 'owned',
                'status' => 'AVAILABLE', 'compliance_status' => 'compliant',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('driver_profiles')->insert([
                'company_id' => self::COMPANY, 'source' => 'stos',
                'source_id' => 90000 + $i, 'status' => 'available',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function legacyDriver(array $over = []): int
    {
        return DB::table('transport_drivers')->insertGetId(array_merge([
            'tenant_id' => self::COMPANY, 'driver_code' => 'DRV-'.random_int(100, 999),
            'name' => 'Rajesh Kumar', 'mobile' => '98765'.random_int(10000, 99999),
            'licence_number' => 'MH01201100'.random_int(10000, 99999), 'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYear()->toDateString(),
            'status' => 'active', 'availability' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    private function runMover(): void
    {
        $path = database_path('migrations/2027_01_02_000002_move_transport_masters_into_fleet.php');
        $migration = require $path;
        $migration->up();
    }

    private function legacyVehicle(array $over = []): int
    {
        return DB::table('transport_vehicles')->insertGetId(array_merge([
            'tenant_id' => self::COMPANY,
            'registration_number' => 'MH12AB1234',
            'registration_normalized' => 'MH12AB1234',
            'vehicle_type' => 'REEFER',
            'ownership_type' => 'OWNED',
            'capacity_tonnes' => '25.00',
            'manufacturer' => 'Tata',
            'model' => 'Prima 4928',
            'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    private function fleetVehicle(array $over = []): int
    {
        return DB::table('vehicles')->insertGetId(array_merge([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH12AB1234',
            'vehicle_type' => 'reefer',
            'ownership_type' => 'owned',
            'status' => 'active',
            'compliance_status' => 'compliant',
            'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }


    /** A trip needs an order, and an order needs a customer and a route. */
    private function tripFor(array $over): int
    {
        $orderId = DB::table('transport_orders')->insertGetId([
            'tenant_id' => self::COMPANY, 'customer_id' => 1,
            'order_number' => 'TO-'.Str::random(6),
            // Both locations are JSON columns on the real table.
            'pickup_location'   => json_encode(['city' => 'Mumbai']),
            'delivery_location' => json_encode(['city' => 'Pune']),
            'service_type'      => 'FTL',
            'required_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('transport_trips')->insertGetId(array_merge([
            'tenant_id' => self::COMPANY, 'order_id' => $orderId, 'customer_id' => 1,
            'trip_number' => 'TRP-'.Str::random(6), 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    /* ── The schema union ───────────────────────────────────────── */

    public function test_the_fleet_master_gained_every_identity_column_operations_had(): void
    {
        foreach ([
            'registration_normalized', 'fleet_number', 'manufacturer', 'model', 'variant',
            'manufacturing_year', 'purchase_date', 'fuel_type', 'branch', 'capacity_tonnes',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('vehicles', $column),
                "vehicles.{$column} is missing — merging would lose Operations' data"
            );
        }

        // capacity_tonnes is what the eligibility engine matches an order
        // against; losing it would break PLN-001, not just a display field.
        $this->assertTrue(Schema::hasColumn('vehicles', 'capacity_tonnes'));
    }

    /* ── Moving rows ────────────────────────────────────────────── */

    public function test_a_vehicle_only_in_operations_is_carried_over_whole(): void
    {
        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);

        $this->runMover();

        $moved = DB::table('vehicles')->where('legacy_transport_vehicle_id', $legacyId)->first();
        $this->assertNotNull($moved, 'The vehicle was not carried over');
        $this->assertSame('MH20GH7799', $moved->registration_number);
        $this->assertSame('reefer', $moved->vehicle_type, 'REEFER should map to the Fleet vocabulary');
        $this->assertEquals(25.0, (float) $moved->capacity_tonnes);
        $this->assertSame('Tata', $moved->manufacturer);

        // Fleet's invariant: one live-status row per vehicle, from birth.
        $this->assertDatabaseHas('vehicle_live_status', ['vehicle_id' => $moved->id]);
    }

    public function test_a_vehicle_in_both_is_merged_without_overwriting_fleet(): void
    {
        // Fleet already knows this truck and has been running it.
        $fleetId = $this->fleetVehicle([
            'registration_number' => 'MH12AB1234',
            'chassis_number' => 'FLEET-CHASSIS',
        ]);
        $legacyId = $this->legacyVehicle([
            'registration_number' => 'MH 12 AB 1234',   // same truck, spaced
            'chassis_number' => 'OPS-CHASSIS',
        ]);

        $this->runMover();

        // One row, not two — the spaced plate is the same vehicle.
        $this->assertSame(1, DB::table('vehicles')->count());

        $merged = DB::table('vehicles')->find($fleetId);
        // Fleet's own value stands; Operations only fills what was blank.
        $this->assertSame('FLEET-CHASSIS', $merged->chassis_number);
        $this->assertEquals(25.0, (float) $merged->capacity_tonnes, 'The gap should have been filled');
        $this->assertSame($legacyId, (int) $merged->legacy_transport_vehicle_id);
    }

    public function test_an_ambiguous_plate_is_refused_not_guessed(): void
    {
        // Two Fleet rows normalise to the same plate — a data problem that a
        // person has to resolve.
        $this->fleetVehicle(['registration_number' => 'MH12AB1234']);
        DB::table('vehicles')->insert([
            'company_id' => self::COMPANY, 'registration_number' => 'MH-12-AB-1234',
            'vehicle_type' => 'truck', 'ownership_type' => 'owned',
            'status' => 'AVAILABLE', 'compliance_status' => 'compliant',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $legacyId = $this->legacyVehicle();

        $this->runMover();

        // Left alone. Attaching history to the wrong truck is unrecoverable.
        $this->assertSame(0, DB::table('vehicles')->whereNotNull('legacy_transport_vehicle_id')->count());
        $this->assertDatabaseHas('transport_vehicles', ['id' => $legacyId]);
    }

    public function test_running_the_move_twice_does_not_duplicate_anything(): void
    {
        $this->legacyVehicle(['registration_number' => 'MH20GH7799']);

        $this->runMover();
        $this->runMover();

        $this->assertSame(1, DB::table('vehicles')->count());
        $this->assertSame(1, DB::table('vehicle_live_status')->count());
    }

    public function test_a_device_already_claimed_is_not_copied_over(): void
    {
        // Two vehicles cannot share a GPS device — it would misroute telemetry.
        $this->fleetVehicle(['registration_number' => 'MH99XX1111', 'gps_device_id' => 'DEV-SHARED']);
        $this->legacyVehicle(['registration_number' => 'MH20GH7799', 'gps_device_id' => 'DEV-SHARED']);

        $this->runMover();

        $moved = DB::table('vehicles')->where('registration_number', 'MH20GH7799')->first();
        $this->assertNotNull($moved, 'The vehicle should still be carried over');
        $this->assertNull($moved->gps_device_id, 'The clashing device must be dropped, not forced');
    }

    /* ── Trips must never point at a stale id ───────────────────── */

    /**
     * D-109 — the move must NOT repoint, and this test used to prove the
     * opposite while asserting nothing.
     *
     * It read `assertSame($newVehicleId, $trip->vehicle_id)` on a fresh
     * database where the legacy row and the Fleet row were BOTH id 1. It passed
     * whether or not the repoint happened. That false green is how the orphaning
     * bug reached Person 1's machine.
     *
     * The Fleet ids are pushed out of the way first, so the two numbers cannot
     * coincide and the assertion has to mean something.
     */
    public function test_the_move_does_not_repoint_the_trip(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $tripId = $this->tripFor(['vehicle_id' => $legacyId]);

        $this->runMover();

        $newVehicleId = (int) DB::table('vehicles')->where('legacy_transport_vehicle_id', $legacyId)->value('id');
        $trip = DB::table('transport_trips')->find($tripId);

        // The ids genuinely differ now, so this is a real assertion.
        $this->assertNotSame($legacyId, $newVehicleId, 'the fixture must make the two ids differ or it proves nothing');

        // Transport still READS transport_vehicles. Repointing here would blank
        // the vehicle on every trip, silently, with no way back.
        $this->assertSame($legacyId, (int) $trip->vehicle_id);
    }

    public function test_the_repoint_command_reports_without_writing_by_default(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $tripId = $this->tripFor(['vehicle_id' => $legacyId]);
        $this->runMover();

        $this->artisan('stos:repoint-trip-fleet-refs')->assertSuccessful();

        // A dry run that writes is worse than no dry run at all.
        $this->assertSame($legacyId, (int) DB::table('transport_trips')->find($tripId)->vehicle_id);
    }

    public function test_the_repoint_command_moves_the_trip_when_asked(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $tripId = $this->tripFor(['vehicle_id' => $legacyId]);
        $this->runMover();

        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertSuccessful();

        $newVehicleId = (int) DB::table('vehicles')->where('legacy_transport_vehicle_id', $legacyId)->value('id');
        $this->assertSame($newVehicleId, (int) DB::table('transport_trips')->find($tripId)->vehicle_id);
    }

    public function test_repointing_twice_does_not_move_a_trip_a_second_time(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $tripId = $this->tripFor(['vehicle_id' => $legacyId]);
        $this->runMover();

        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertSuccessful();
        $newVehicleId = (int) DB::table('transport_trips')->find($tripId)->vehicle_id;

        // Re-running must be harmless, and it now is for a reason rather than
        // by luck. The old skip rested on "an already-repointed row no longer
        // matches any legacy id" — which is only true while the two sequences
        // happen not to overlap, the same assumption that cost us D-116. The
        // row is skipped because the ledger already holds a verdict on it.
        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertSuccessful();

        $this->assertSame($newVehicleId, (int) DB::table('transport_trips')->find($tripId)->vehicle_id);

        $this->assertSame(1, DB::table('fleet_reference_repoints')
            ->where('table_name', 'transport_trips')->where('column_name', 'vehicle_id')
            ->where('row_id', $tripId)->count(), 'one verdict per reference, not one per run');
    }

    /* ── D-116: what the repoint could not move is written down too ─── */

    public function test_the_repoint_records_what_each_reference_now_means(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $tripId = $this->tripFor(['vehicle_id' => $legacyId]);
        $this->runMover();

        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertSuccessful();

        $newVehicleId = (int) DB::table('vehicles')->where('legacy_transport_vehicle_id', $legacyId)->value('id');

        $verdict = DB::table('fleet_reference_repoints')
            ->where('table_name', 'transport_trips')->where('column_name', 'vehicle_id')
            ->where('row_id', $tripId)->first();

        // Without this, nothing in the row says which id space its number is
        // in once the switch has happened, and telemetry is back to guessing.
        $this->assertNotNull($verdict);
        $this->assertSame($legacyId, (int) $verdict->from_id);
        $this->assertSame($newVehicleId, (int) $verdict->to_id);
    }

    public function test_the_repoint_refuses_to_strand_references_unless_told_to(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        // One truck migrates; a second trip points at a legacy row that never
        // did. Repointing now leaves that trip in the old id space for good.
        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $this->tripFor(['vehicle_id' => $legacyId]);
        $this->runMover();

        $orphanTripId = $this->tripFor(['vehicle_id' => 4242]);

        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertFailed();

        // Nothing was written at all — not the move, not the verdict.
        $this->assertSame(4242, (int) DB::table('transport_trips')->find($orphanTripId)->vehicle_id);
        $this->assertSame(0, DB::table('fleet_reference_repoints')->count());
    }

    public function test_a_stranded_reference_is_recorded_as_unmatchable(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $this->tripFor(['vehicle_id' => $legacyId]);
        $this->runMover();

        $orphanTripId = $this->tripFor(['vehicle_id' => 4242]);

        $this->artisan('stos:repoint-trip-fleet-refs --apply --force')->assertSuccessful();

        $verdict = DB::table('fleet_reference_repoints')->where('row_id', $orphanTripId)
            ->where('column_name', 'vehicle_id')->first();

        // Left where it is, and marked so that nothing downstream reads its
        // number as a Fleet id once the rest of the data has moved.
        $this->assertSame(4242, (int) DB::table('transport_trips')->find($orphanTripId)->vehicle_id);
        $this->assertNotNull($verdict);
        $this->assertNull($verdict->to_id);
    }

    /* ── The mapping itself goes stale, and that reads as "done" ────── */

    public function test_reconcile_calls_one_matching_plate_repairable_rather_than_ambiguous(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $this->runMover();

        // What a reseed of the legacy table does: same truck, new legacy id,
        // and the stored link still pointing at the row that used to be there.
        DB::table('vehicles')->where('legacy_transport_vehicle_id', $legacyId)
            ->update(['legacy_transport_vehicle_id' => 999]);

        $this->artisan('stos:reconcile-fleet')
            ->expectsOutputToContain('Repairable')
            ->doesntExpectOutputToContain('AMBIGUOUS')
            ->assertSuccessful();
    }

    public function test_relink_repairs_a_stale_mapping_so_the_repoint_can_see_it(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $tripId = $this->tripFor(['vehicle_id' => $legacyId]);
        $this->runMover();

        $fleetId = (int) DB::table('vehicles')->where('legacy_transport_vehicle_id', $legacyId)->value('id');
        DB::table('vehicles')->where('id', $fleetId)->update(['legacy_transport_vehicle_id' => 999]);

        // The symptom Person 1 saw: the dry run reports nothing to do, which
        // reads as finished and actually means the map matches no live row.
        $this->artisan('stos:repoint-trip-fleet-refs')
            ->expectsOutputToContain('Nothing can be moved')
            ->assertSuccessful();

        $this->artisan('stos:reconcile-fleet --relink')->assertSuccessful();

        $this->assertSame($legacyId, (int) DB::table('vehicles')->find($fleetId)->legacy_transport_vehicle_id);

        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertSuccessful();
        $this->assertSame($fleetId, (int) DB::table('transport_trips')->find($tripId)->vehicle_id);
    }

    public function test_relink_refuses_when_two_live_legacy_rows_want_one_fleet_vehicle(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $firstId = $this->legacyVehicle(['registration_number' => 'MH20GH7799']);
        $this->runMover();

        $fleetId = (int) DB::table('vehicles')->where('legacy_transport_vehicle_id', $firstId)->value('id');

        // A second legacy row, still live, carrying the same plate.
        $secondId = DB::table('transport_vehicles')->insertGetId([
            'tenant_id' => self::COMPANY,
            'registration_number' => 'MH 20 GH 7799', 'registration_normalized' => 'MH20GH7799',
            'vehicle_type' => 'REEFER', 'ownership_type' => 'OWNED', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('stos:reconcile-fleet --relink')
            ->expectsOutputToContain('Needs a person')
            ->assertSuccessful();

        // A repair is only a repair when there is nothing to decide.
        $this->assertSame($firstId, (int) DB::table('vehicles')->find($fleetId)->legacy_transport_vehicle_id);
        $this->assertNotSame($firstId, $secondId);
    }

    public function test_a_driver_moves_without_a_name_landing_in_the_overlay(): void
    {
        $legacyId = DB::table('transport_drivers')->insertGetId([
            'tenant_id' => self::COMPANY, 'driver_code' => 'DRV-1',
            'name' => 'Rajesh Kumar', 'mobile' => '9876543210',
            'licence_number' => 'MH0120110012345', 'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYear()->toDateString(),
            'status' => 'active', 'availability' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMover();

        $profile = DB::table('driver_profiles')->where('legacy_transport_driver_id', $legacyId)->first();
        $this->assertNotNull($profile);

        // The licence is Transport's and lives on the overlay.
        $this->assertSame('MH0120110012345', $profile->licence_number);
        $this->assertSame('DRV-1', $profile->driver_code);

        // The NAME went to the directory, not the overlay — no demographic
        // column exists on driver_profiles, and none was invented.
        $this->assertSame('stos', $profile->source);
        $this->assertDatabaseHas('stos_drivers', ['id' => $profile->source_id, 'name' => 'Rajesh Kumar']);
        $this->assertFalse(Schema::hasColumn('driver_profiles', 'name'));
    }

    public function test_the_move_does_not_repoint_the_trips_driver_either(): void
    {
        $this->pushFleetIdsOutOfTheWay();

        $legacyId = $this->legacyDriver();
        $tripId = $this->tripFor(['driver_id' => $legacyId]);

        $this->runMover();

        $trip = DB::table('transport_trips')->find($tripId);

        // Same reasoning as the vehicle: Operations still reads the legacy
        // table, so the key stays put until its readers move.
        $this->assertSame($legacyId, (int) $trip->driver_id);

        $this->artisan('stos:repoint-trip-fleet-refs --apply')->assertSuccessful();

        $newDriverId = (int) DB::table('driver_profiles')->where('legacy_transport_driver_id', $legacyId)->value('id');
        $this->assertSame($newDriverId, (int) DB::table('transport_trips')->find($tripId)->driver_id);
    }

    public function test_nothing_is_deleted_by_the_move(): void
    {
        $this->legacyVehicle(['registration_number' => 'MH20GH7799']);

        $this->runMover();

        // The legacy tables stay intact until a person retires them, a clean
        // week later. Recovery is re-pointing the code, not undeleting rows.
        $this->assertSame(1, DB::table('transport_vehicles')->count());
    }
}
