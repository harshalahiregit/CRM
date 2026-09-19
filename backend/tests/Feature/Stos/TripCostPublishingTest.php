<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Services\FuelService;
use App\Domains\Fleet\Services\MaintenanceService;
use App\Domains\Fleet\Services\UreaService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C-06 — Fleet's operating costs reach the trip's P&L.
 *
 * Person 3 built `trip_costs`, documented the call, and waited. These assert
 * Fleet's half arrives in the shape his service expects — including the two
 * rules that make it safe: a mandatory `source_ref` so retries cannot double a
 * trip's cost, and a null actor so a system measurement is never mistaken for a
 * hand-entered one.
 */
class TripCostPublishingTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();

        $orderId = DB::table('transport_orders')->insertGetId([
            'tenant_id' => self::COMPANY, 'customer_id' => 1,
            'order_number' => 'TO-'.Str::random(6),
            'pickup_location' => json_encode(['city' => 'Mumbai']),
            'delivery_location' => json_encode(['city' => 'Pune']),
            'service_type' => 'FTL', 'required_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->tripId = DB::table('transport_trips')->insertGetId([
            'tenant_id' => self::COMPANY, 'order_id' => $orderId, 'customer_id' => 1,
            'trip_number' => 'TRP-'.Str::random(6), 'status' => 'in_transit',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => 'staff',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'ownership_type' => 'owned', 'status' => 'AVAILABLE',
        ]);
    }

    private function costsForTrip(): \Illuminate\Support\Collection
    {
        return DB::table('trip_costs')->where('trip_id', $this->tripId)->get();
    }

    /* ── Each Fleet cost type lands ─────────────────────────────── */

    public function test_a_fuel_fill_on_a_trip_reaches_the_trips_costs(): void
    {
        $vehicle = $this->vehicle();

        app(FuelService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 200, 'rate_per_litre' => 96.20, 'amount' => 19240,
            'trip_id' => $this->tripId, 'station_vendor' => 'IOC Thane',
        ], $this->user()->id);

        $cost = $this->costsForTrip()->firstWhere('cost_type', 'fuel');

        $this->assertNotNull($cost, 'Finance cannot compute contribution without this');
        $this->assertEquals(19240, (float) $cost->amount);
        // Telemetry, not manual — this is the system reporting a measurement.
        $this->assertSame('telemetry', $cost->source);
        $this->assertNotNull($cost->source_ref, 'Without a ref, retries would double the cost');
    }

    public function test_a_urea_top_up_on_a_trip_reaches_the_trips_costs(): void
    {
        $vehicle = $this->vehicle();

        app(UreaService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 20, 'amount' => 1800, 'trip_id' => $this->tripId,
        ], $this->user()->id);

        $cost = $this->costsForTrip()->firstWhere('cost_type', 'urea');
        $this->assertNotNull($cost);
        $this->assertEquals(1800, (float) $cost->amount);
    }

    public function test_a_breakdown_repair_reaches_the_trip_only_when_the_card_closes(): void
    {
        $vehicle = $this->vehicle();
        $userId = $this->user()->id;

        $job = app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'trip_id' => $this->tripId,
            'complaint' => 'Clutch failed on NH-48',
        ], $userId);

        // Nothing yet — the cost is not known while the card is open, and the
        // dedupe key would refuse to correct a zero posted early.
        $this->assertNull($this->costsForTrip()->firstWhere('cost_type', 'maintenance'));

        app(MaintenanceService::class)->close($job->id, self::COMPANY, [
            'parts_cost' => 18500, 'labour_cost' => 4200,
        ], $userId);

        $cost = $this->costsForTrip()->firstWhere('cost_type', 'maintenance');
        $this->assertNotNull($cost);
        $this->assertEquals(22700, (float) $cost->amount);
    }

    /* ── The rules that make it safe ────────────────────────────── */

    public function test_re_publishing_the_same_fill_cannot_double_the_trips_cost(): void
    {
        $vehicle = $this->vehicle();
        $fuel = app(FuelService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => 9000, 'trip_id' => $this->tripId,
        ], $this->user()->id);

        // A re-delivered webhook or a re-run import.
        $publisher = app(\App\Domains\Fleet\Integration\TripCostPublisher::class);
        $publisher->publishFuel(self::COMPANY, $fuel);
        $publisher->publishFuel(self::COMPANY, $fuel);

        $this->assertSame(1, $this->costsForTrip()->where('cost_type', 'fuel')->count());
    }

    public function test_a_cost_with_no_trip_is_fleet_overhead_and_is_not_published(): void
    {
        $vehicle = $this->vehicle();

        app(FuelService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => 9000,   // no trip_id
        ], $this->user()->id);

        // Routine yard fuelling is a real cost but not THIS trip's. Spreading
        // it across trips is a costing policy for Finance, not Fleet's to invent.
        $this->assertSame(0, DB::table('trip_costs')->count());
    }

    public function test_a_failure_to_publish_never_loses_the_fuel_entry(): void
    {
        $vehicle = $this->vehicle();

        // A trip id that does not resolve — a data problem downstream.
        $fuel = app(FuelService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => 9000, 'trip_id' => 999999,
        ], $this->user()->id);

        // The fill is a fact about a vehicle whether or not Finance heard it.
        $this->assertDatabaseHas('fuel_transactions', ['id' => $fuel->id]);
        $this->assertSame(0, DB::table('trip_costs')->count());
    }

    public function test_the_whole_trip_adds_up_on_both_sides(): void
    {
        $vehicle = $this->vehicle();
        $userId = $this->user()->id;

        app(FuelService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => 9000, 'trip_id' => $this->tripId,
        ], $userId);
        app(UreaService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 10, 'amount' => 850, 'trip_id' => $this->tripId,
        ], $userId);

        // Fleet's own roll-up (the contract Person 3 also calls)…
        $ours = app(\App\Domains\Fleet\Services\FleetService::class)
            ->getTripOperatingCosts($this->tripId, self::COMPANY);

        // …and what landed in his table. The two must agree, or the trip's P&L
        // disagrees with the fleet's own report of the same journey.
        $theirs = $this->costsForTrip()->sum(fn ($c) => (float) $c->amount);

        $this->assertEquals(9850.0, (float) $ours['total']);
        $this->assertEquals(9850.0, $theirs);
    }
}
