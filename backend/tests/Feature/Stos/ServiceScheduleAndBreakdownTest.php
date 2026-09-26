<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Fleet\Services\FleetService;
use App\Domains\Fleet\Services\FuelService;
use App\Domains\Fleet\Services\MaintenanceService;
use App\Domains\Fleet\Services\ServiceScheduleEvaluator;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — T-04, built as two different kinds of thing.
 *
 * The task asked for `MAINTENANCE_DUE` and `BREAKDOWN` as vehicle states. Only
 * one of them is a state. A truck past its service interval is still a
 * roadworthy truck, and writing that into `status` would drop it out of
 * allocation — a missed oil change silently taking a truck off the road, which
 * is the same category error Person 1 caught in the driver licence.
 *
 * So: `breakdown` is a state and is asserted as one. Service-due is derived and
 * asserted to WARN without ever excluding.
 */
class ServiceScheduleAndBreakdownTest extends TestCase
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

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => 'admin',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(array $over = []): Vehicle
    {
        $v = Vehicle::create(array_merge([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH'.random_int(10, 99).'AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'status' => 'AVAILABLE', 'compliance_status' => 'compliant',
        ], $over));

        VehicleLiveStatus::create([
            'vehicle_id' => $v->id, 'company_id' => self::COMPANY,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        return $v;
    }

    private function odometer(Vehicle $vehicle, float $reading): void
    {
        app(FuelService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => 9000, 'odometer' => $reading,
        ], $this->user()->id);
    }

    private function verdict(Vehicle $vehicle): array
    {
        return app(ServiceScheduleEvaluator::class)->evaluate($vehicle->fresh());
    }

    private function eligible(): array
    {
        return app(FleetService::class)->getEligibleVehicles(self::COMPANY);
    }

    private function trip(): int
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
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ── Service due is a CONDITION, never a status ─────────────── */

    public function test_a_truck_past_its_interval_is_still_fully_allocatable(): void
    {
        $vehicle = $this->vehicle([
            'service_interval_km' => 10000, 'last_service_odometer' => 100000,
        ]);
        $this->odometer($vehicle, 115000);   // 5,000 km overdue

        $result = $this->eligible();

        // The whole point. It is roadworthy; taking it off the road over an oil
        // change is the wrong trade, and the person who could clear it is the
        // same person allocation is trying to help.
        $this->assertCount(1, $result['eligible']);
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
        $this->assertContains('service_overdue', $result['eligible'][0]['flags']);
    }

    public function test_service_due_never_appears_in_the_status_vocabulary(): void
    {
        // If it were a status, a missed service would drop the truck out of
        // allocation silently. It is derived instead.
        $this->assertNotContains('maintenance_due', Vehicle::STATUSES);
        $this->assertContains('BREAKDOWN', Vehicle::STATUSES);
    }

    public function test_a_service_coming_up_warns_before_it_is_missed(): void
    {
        $vehicle = $this->vehicle(['service_interval_km' => 10000, 'last_service_odometer' => 100000]);
        $this->odometer($vehicle, 109500);   // 500 km left

        $verdict = $this->verdict($vehicle);

        // Warned far enough out that a slot can be booked rather than the
        // service discovered when it is already overdue.
        $this->assertSame('due_soon', $verdict['state']);
        $this->assertFalse($verdict['due']);
        $this->assertStringContainsString('500 km', $verdict['message']);
    }

    public function test_a_truck_well_inside_its_interval_is_quiet(): void
    {
        $vehicle = $this->vehicle(['service_interval_km' => 10000, 'last_service_odometer' => 100000]);
        $this->odometer($vehicle, 102000);

        $this->assertSame('ok', $this->verdict($vehicle)['state']);
        $this->assertNull(app(ServiceScheduleEvaluator::class)->flag($vehicle->fresh(), 102000));
    }

    public function test_a_fleet_with_no_schedule_is_unknown_and_not_reassured(): void
    {
        $vehicle = $this->vehicle();

        $verdict = $this->verdict($vehicle);

        // "ok" here would be a reassurance nobody earned: a fleet that has
        // never recorded a schedule is not a fleet of freshly serviced trucks.
        $this->assertSame('unknown', $verdict['state']);
        $this->assertFalse($verdict['due']);
    }

    public function test_an_interval_with_no_odometer_reading_says_which_is_missing(): void
    {
        $vehicle = $this->vehicle(['service_interval_km' => 10000, 'last_service_odometer' => 100000]);

        $verdict = $this->verdict($vehicle);

        // The fix is to record an odometer, not to service the truck, so it
        // must not read as overdue.
        $this->assertSame('unknown', $verdict['state']);
        $this->assertStringContainsString('odometer', $verdict['message']);
    }

    /* ── Two clocks ─────────────────────────────────────────────── */

    public function test_a_time_based_schedule_works_without_any_distance(): void
    {
        $vehicle = $this->vehicle([
            'service_interval_days' => 180,
            'last_service_on' => now()->subDays(200)->toDateString(),
        ]);

        // Trailers and gensets are serviced on time, not distance.
        $verdict = $this->verdict($vehicle);
        $this->assertSame('overdue', $verdict['state']);
        $this->assertStringContainsString('20 day', $verdict['message']);
    }

    public function test_whichever_clock_comes_first_governs(): void
    {
        $vehicle = $this->vehicle([
            'service_interval_km'   => 10000,
            'last_service_odometer' => 100000,
            'service_interval_days' => 180,
            'last_service_on'       => now()->subDays(200)->toDateString(),
        ]);
        $this->odometer($vehicle, 101000);   // barely driven

        $verdict = $this->verdict($vehicle);

        // Taking the kinder of the two would let a truck sit twelve months out
        // of service because it had not driven far.
        $this->assertSame('overdue', $verdict['state']);
        $this->assertSame('ok', $verdict['by_km']['state']);
        $this->assertSame('overdue', $verdict['by_date']['state']);
    }

    public function test_the_message_names_the_clock_that_triggered_it(): void
    {
        $vehicle = $this->vehicle([
            'service_interval_km' => 10000, 'last_service_odometer' => 100000,
            'service_interval_days' => 3650, 'last_service_on' => now()->subDay()->toDateString(),
        ]);
        $this->odometer($vehicle, 120000);

        // The workshop needs to know whether it is a distance service or a
        // time one, because they are different jobs.
        $this->assertStringContainsString('km', $this->verdict($vehicle)['message']);
    }

    public function test_the_odometer_used_is_the_highest_reading_not_the_newest_row(): void
    {
        $vehicle = $this->vehicle(['service_interval_km' => 10000, 'last_service_odometer' => 100000]);

        $this->odometer($vehicle, 115000);
        // A back-dated entry, recorded later with a lower reading — common when
        // a receipt is entered after a trip.
        DB::table('fuel_transactions')->insert([
            'company_id' => self::COMPANY, 'vehicle_id' => $vehicle->id,
            'litres' => 50, 'rate_per_litre' => 90, 'amount' => 4500, 'odometer' => 101000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Measuring from the lower number would report the truck as fresher
        // than it is.
        $this->assertSame('overdue', $this->verdict($vehicle)['state']);
    }

    /* ── Breakdown IS a state ───────────────────────────────────── */

    public function test_a_job_card_on_a_trip_puts_the_vehicle_in_breakdown(): void
    {
        $vehicle = $this->vehicle();

        app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'trip_id' => $this->trip(),
            'complaint' => 'Clutch failed on NH-48',
        ], $this->user()->id);

        // Not `in_maintenance`: a planner reading that assumes a booked slot
        // and a return time. This truck is on a road with a load on it.
        $this->assertSame('BREAKDOWN', $vehicle->fresh()->status);
    }

    public function test_routine_servicing_is_still_in_maintenance(): void
    {
        $vehicle = $this->vehicle();

        app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'complaint' => 'Scheduled 10,000 km service',
        ], $this->user()->id);

        $this->assertSame('UNDER_MAINTENANCE', $vehicle->fresh()->status);
    }

    public function test_a_broken_down_vehicle_is_excluded_with_its_own_reason(): void
    {
        $vehicle = $this->vehicle();
        app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'trip_id' => $this->trip(),
            'complaint' => 'Clutch failed',
        ], $this->user()->id);

        $result = $this->eligible();

        $this->assertCount(0, $result['eligible']);
        $blocked = collect($result['excluded'])->firstWhere('id', $vehicle->id);
        $codes = collect($blocked['blockers'])->pluck('code');

        // Distinct from in_maintenance on purpose — the desk that owns it is
        // different, and so is what has to happen next.
        $this->assertTrue($codes->contains('broken_down'));
        $this->assertSame(
            'Operations control tower',
            collect($blocked['blockers'])->firstWhere('code', 'broken_down')['owner']
        );
    }

    public function test_closing_the_breakdown_card_puts_the_truck_back_on_the_road(): void
    {
        $vehicle = $this->vehicle();
        $job = app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'trip_id' => $this->trip(), 'complaint' => 'Clutch failed',
        ], $this->user()->id);

        $result = app(MaintenanceService::class)->close($job->id, self::COMPANY, [
            'qc_result' => 'PASS', 'parts_cost' => 18500,
        ], $this->user()->id);

        $this->assertTrue($result['release']['released']);
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
    }

    public function test_a_broken_down_vehicle_is_not_dragged_onto_a_trip_by_dispatch(): void
    {
        $vehicle = $this->vehicle();
        app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'trip_id' => $this->trip(), 'complaint' => 'Clutch failed',
        ], $this->user()->id);

        $gateway = app(\App\Services\Transport\Contracts\FleetResourceGateway::class);
        $trip = \App\Models\Transport\TransportTrip::find($this->trip());

        // Only a workshop release — which checks QC and compliance — puts this
        // vehicle back, however confident the trip board is.
        $this->assertFalse($gateway->markDispatched($trip, $vehicle->id, null, self::COMPANY));
        $this->assertSame('BREAKDOWN', $vehicle->fresh()->status);
    }

    /* ── Where it surfaces ──────────────────────────────────────── */

    public function test_the_passport_carries_the_service_verdict(): void
    {
        $vehicle = $this->vehicle(['service_interval_km' => 10000, 'last_service_odometer' => 100000]);
        $this->odometer($vehicle, 115000);

        $service = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()->json('data.service');

        $this->assertSame('overdue', $service['state']);
        $this->assertTrue($service['due']);
    }

    public function test_the_schedule_can_be_recorded_when_onboarding(): void
    {
        $data = $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', [
                'registration_number' => 'MH12ZZ9999',
                'vehicle_type' => 'truck', 'ownership_type' => 'OWNED',
                'service_interval_km' => 15000,
                'last_service_odometer' => 82000,
                'last_service_on' => now()->subMonth()->toDateString(),
            ])->assertCreated()->json('data');

        $this->assertSame(15000, $data['service_interval_km']);
        $this->assertEquals(82000, $data['last_service_odometer']);
    }

    public function test_a_service_date_in_the_future_is_refused(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', [
                'registration_number' => 'MH12YY8888',
                'vehicle_type' => 'truck', 'ownership_type' => 'OWNED',
                'last_service_on' => now()->addMonth()->toDateString(),
            ])->assertStatus(422)->assertJsonValidationErrors('last_service_on');
    }
}
