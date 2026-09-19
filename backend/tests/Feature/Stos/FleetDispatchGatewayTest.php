<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Models\Tenant;
use App\Models\Transport\TransportTrip;
use App\Services\Transport\Contracts\FleetResourceGateway;
use App\Services\Transport\PendingFleetResourceGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C-05 — Fleet's side of the dispatch seam (BRW-050).
 *
 * Person 1 left `PendingFleetResourceGateway` holding a place with a TODO
 * addressed to Person 2, and wrote the contract an implementation owes into the
 * interface docblock. These tests hold Fleet to all four clauses of it:
 * idempotent, never throws, never forces a transition, and honest about whether
 * it actually applied anything.
 */
class FleetDispatchGatewayTest extends TestCase
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

    private function gateway(): FleetResourceGateway
    {
        return app(FleetResourceGateway::class);
    }

    private function vehicle(string $status = 'AVAILABLE'): Vehicle
    {
        return Vehicle::create([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'ownership_type' => 'owned',
            'status' => $status, 'compliance_status' => 'compliant',
        ]);
    }

    private function driver(string $status = 'available'): DriverProfile
    {
        return DriverProfile::create([
            'company_id' => self::COMPANY, 'source' => 'stos',
            'source_id' => random_int(1, 99999), 'status' => $status,
        ]);
    }

    private function trip(): TransportTrip
    {
        $orderId = DB::table('transport_orders')->insertGetId([
            'tenant_id' => self::COMPANY, 'customer_id' => 1,
            'order_number' => 'TO-'.Str::random(6),
            'pickup_location' => json_encode(['city' => 'Mumbai']),
            'delivery_location' => json_encode(['city' => 'Pune']),
            'service_type' => 'FTL', 'required_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $id = DB::table('transport_trips')->insertGetId([
            'tenant_id' => self::COMPANY, 'order_id' => $orderId, 'customer_id' => 1,
            'trip_number' => 'TRP-'.Str::random(6), 'status' => 'planned',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return TransportTrip::find($id);
    }

    /* ── The seam is answered at all ────────────────────────────── */

    public function test_fleet_now_answers_the_gateway_instead_of_the_placeholder(): void
    {
        // The whole of Trip side's change was meant to be this binding.
        $this->assertNotInstanceOf(PendingFleetResourceGateway::class, $this->gateway());
    }

    /* ── BRW-050: the crew departed ─────────────────────────────── */

    public function test_a_departure_puts_the_vehicle_in_operation_and_the_driver_on_trip(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver();

        $applied = $this->gateway()->markDispatched($this->trip(), $vehicle->id, $driver->id, self::COMPANY);

        $this->assertTrue($applied);
        $this->assertSame('IN_TRANSIT', $vehicle->fresh()->status);
        $this->assertSame('on_trip', $driver->fresh()->status);
    }

    public function test_applying_the_same_departure_twice_is_a_no_op_that_still_succeeds(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver();
        $trip = $this->trip();

        $this->gateway()->markDispatched($trip, $vehicle->id, $driver->id, self::COMPANY);
        $second = $this->gateway()->markDispatched($trip, $vehicle->id, $driver->id, self::COMPANY);

        // Dispatch is confirmed once and amended repeatedly — the caller asked
        // for a state, and the state holds.
        $this->assertTrue($second);
        $this->assertSame('IN_TRANSIT', $vehicle->fresh()->status);
    }

    public function test_a_trip_with_nothing_assigned_is_not_a_failure(): void
    {
        $this->assertTrue($this->gateway()->markDispatched($this->trip(), null, null, self::COMPANY));
    }

    /* ── It must never force a transition ───────────────────────── */

    public function test_a_vehicle_in_the_workshop_is_not_dragged_onto_the_road(): void
    {
        $vehicle = $this->vehicle('UNDER_MAINTENANCE');

        $applied = $this->gateway()->markDispatched($this->trip(), $vehicle->id, null, self::COMPANY);

        // A workshop release — which checks QC and compliance — is the only
        // thing that puts this vehicle back on the road.
        $this->assertFalse($applied, 'The gateway must report that it could not apply this');
        $this->assertSame('UNDER_MAINTENANCE', $vehicle->fresh()->status);
    }

    public function test_a_retired_vehicle_is_left_alone(): void
    {
        $vehicle = $this->vehicle('RETIRED');

        $this->assertFalse($this->gateway()->markDispatched($this->trip(), $vehicle->id, null, self::COMPANY));
        $this->assertSame('RETIRED', $vehicle->fresh()->status);
    }

    public function test_a_suspended_driver_is_not_put_on_a_trip(): void
    {
        $driver = $this->driver('suspended');

        $this->assertFalse($this->gateway()->markDispatched($this->trip(), null, $driver->id, self::COMPANY));
        $this->assertSame('suspended', $driver->fresh()->status);
    }

    /* ── It must never throw, and must be honest ────────────────── */

    public function test_an_unknown_vehicle_is_reported_not_thrown(): void
    {
        // Trip side treats a gateway failure as non-fatal; throwing here would
        // block a real dispatch over a bookkeeping mismatch.
        $this->assertFalse($this->gateway()->markDispatched($this->trip(), 999999, null, self::COMPANY));
    }

    public function test_another_companys_vehicle_is_never_touched(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        $theirs = Vehicle::create([
            'company_id' => 2, 'registration_number' => 'MH99ZZ0001',
            'vehicle_type' => 'truck', 'ownership_type' => 'owned', 'status' => 'AVAILABLE',
        ]);

        $this->assertFalse($this->gateway()->markDispatched($this->trip(), $theirs->id, null, self::COMPANY));
        $this->assertSame('AVAILABLE', $theirs->fresh()->status);
    }

    public function test_a_partial_application_is_reported_as_failure(): void
    {
        $vehicle = $this->vehicle();
        $suspended = $this->driver('suspended');

        $applied = $this->gateway()->markDispatched($this->trip(), $vehicle->id, $suspended->id, self::COMPANY);

        // The vehicle moved, the driver did not — so the answer is false, and
        // the discrepancy is discoverable rather than assumed away.
        $this->assertFalse($applied);
        $this->assertSame('IN_TRANSIT', $vehicle->fresh()->status);
    }

    /* ── What the new state means for allocation ────────────────── */

    public function test_a_vehicle_out_on_a_trip_cannot_be_allocated_again(): void
    {
        $vehicle = $this->vehicle();
        \App\Domains\Fleet\Models\VehicleLiveStatus::create([
            'vehicle_id' => $vehicle->id, 'company_id' => self::COMPANY,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        $before = app(\App\Domains\Fleet\Services\FleetService::class)
            ->getEligibleVehicles(self::COMPANY, 'REEFER');
        $this->assertCount(1, $before['eligible']);

        $this->gateway()->markDispatched($this->trip(), $vehicle->id, null, self::COMPANY);

        $after = app(\App\Domains\Fleet\Services\FleetService::class)
            ->getEligibleVehicles(self::COMPANY, 'REEFER');

        // PLN-006 — prevent double allocation.
        $this->assertCount(0, $after['eligible']);
        $blocked = collect($after['excluded'])->firstWhere('id', $vehicle->id);
        $this->assertSame('on_another_trip', $blocked['blockers'][0]['code']);
    }

    public function test_closing_a_trip_gives_the_vehicle_and_driver_back(): void
    {
        $vehicle = $this->vehicle();
        $driver = $this->driver();
        $gateway = $this->gateway();

        $gateway->markDispatched($this->trip(), $vehicle->id, $driver->id, self::COMPANY);
        $gateway->markReleased($vehicle->id, $driver->id, self::COMPANY);

        // Without this half, every vehicle is permanently "in operation" and
        // the fleet has no availability at all.
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
        $this->assertSame('available', $driver->fresh()->status);
    }
}
