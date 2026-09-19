<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-INT → the shared trip timeline — demonstration step 8.
 *
 * Step 8 of the 30 September script is "show GPS/temperature/generator event",
 * and it read NOT REACHABLE: telemetry shipped, but a container could not be
 * opened and the journey read. Person 1 built `trip_events` as one timeline all
 * three sections write to and registered nine types against Fleet's name.
 *
 * These cover Fleet's half, and the judgement that makes it usable: the
 * timeline is the story, not the trail. Every ping is still kept in
 * `telemetry_records`; only changes reach the timeline.
 */
class TripTimelineTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const TOKEN = 'test-device-token';
    private const DEVICE = 'DEV-TL-0001';

    private Vehicle $vehicle;
    private int $tripId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('trip_events')) {
            $this->markTestSkipped('trip_events is Person 1\'s table and is not present.');
        }

        config(['stos.ingest.token' => self::TOKEN]);

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();

        $this->vehicle = Vehicle::create([
            'company_id' => self::COMPANY, 'registration_number' => 'MH12TL0001',
            'vehicle_type' => 'reefer', 'gps_device_id' => self::DEVICE,
            'status' => Vehicle::STATUS_IN_TRANSIT,
        ]);

        $this->tripId = $this->trip('in_transit', $this->vehicle->id);
    }

    private function trip(string $status, ?int $vehicleId): int
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
            'trip_number' => 'TRP-'.Str::random(6), 'status' => $status,
            'vehicle_id' => $vehicleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ping(array $over = [], ?string $device = null)
    {
        return $this->withHeaders(['X-Device-Token' => self::TOKEN])
            ->postJson('/api/v1/telemetry/ingest', array_merge([
                'device_id'   => $device ?? self::DEVICE,
                'latitude'    => '19.07609500',
                'longitude'   => '72.87765800',
                'speed'       => 45,
                'ignition'    => true,
                'generator_status' => 'on',
                'temperature' => -18.5,
                'recorded_at' => now()->subMinutes(10)->toDateTimeString(),
            ], $over));
    }

    private function events(?string $type = null)
    {
        return DB::table('trip_events')
            ->where('trip_id', $this->tripId)
            ->when($type, fn ($q) => $q->where('event_type', $type))
            ->get();
    }

    /* ── Step 8: a reading reaches the journey ──────────────────── */

    public function test_the_first_fix_puts_tracking_on_the_trip_timeline(): void
    {
        $this->ping()->assertCreated();

        // This is step 8: open the container, read the journey, see the truck.
        $this->assertCount(1, $this->events('gps.activated'));
    }

    public function test_a_genset_stopping_reaches_the_timeline(): void
    {
        $this->ping(['generator_status' => 'on'])->assertCreated();
        $this->ping([
            'generator_status' => 'off',
            'recorded_at' => now()->subMinutes(5)->toDateTimeString(),
        ])->assertCreated();

        $this->assertCount(1, $this->events('genset.off'));
    }

    public function test_the_device_clock_is_what_orders_the_timeline(): void
    {
        $happenedAt = now()->subHours(3)->startOfMinute();

        // A unit out of a tunnel replays an hour of pings inside one second.
        // Ordered by arrival they read as a stampede at the moment we heard.
        $this->ping(['recorded_at' => $happenedAt->toDateTimeString()])->assertCreated();

        $event = $this->events('gps.activated')->first();

        $this->assertSame(
            $happenedAt->toDateTimeString(),
            \Illuminate\Support\Carbon::parse($event->occurred_at)->toDateTimeString()
        );
    }

    /* ── The timeline is the story, not the trail ───────────────── */

    public function test_a_steady_run_does_not_flood_the_timeline(): void
    {
        // Five pings, nothing changing but the position.
        foreach ([10, 8, 6, 4, 2] as $i => $minutes) {
            $this->ping([
                'recorded_at' => now()->subMinutes($minutes)->toDateTimeString(),
                'latitude' => '19.0760'.$i.'500',
            ])->assertCreated();
        }

        // One "tracking active", not five positions. Several hundred identical
        // lines would bury the four events somebody reads a journey for.
        $this->assertCount(1, $this->events('gps.activated'));
        $this->assertSame(5, DB::table('telemetry_records')->count(), 'every ping is still kept');
    }

    public function test_a_genset_running_all_day_is_logged_once(): void
    {
        foreach ([10, 8, 6] as $minutes) {
            $this->ping(['recorded_at' => now()->subMinutes($minutes)->toDateTimeString()])->assertCreated();
        }

        $this->assertCount(1, $this->events('genset.on'));
    }

    public function test_temperature_drift_is_ignored_but_a_real_move_is_not(): void
    {
        $this->ping(['temperature' => -18.5])->assertCreated();
        // A reefer drifts a few tenths constantly.
        $this->ping(['temperature' => -18.3, 'recorded_at' => now()->subMinutes(8)->toDateTimeString()])->assertCreated();

        $this->assertCount(1, $this->events('temperature.reading'));

        // Two degrees is the load actually warming.
        $this->ping(['temperature' => -16.0, 'recorded_at' => now()->subMinutes(6)->toDateTimeString()])->assertCreated();

        $this->assertCount(2, $this->events('temperature.reading'));
    }

    public function test_an_excursion_is_published_separately_so_it_is_never_filtered_as_noise(): void
    {
        // Genset off, load warmer than the limit, vehicle moving.
        $this->ping([
            'generator_status' => 'off', 'temperature' => -5.0, 'speed' => 40,
        ])->assertCreated();

        $this->assertCount(1, $this->events('temperature.excursion'));
    }

    /* ── A ping with no trip ────────────────────────────────────── */

    public function test_a_truck_idling_in_the_yard_writes_nothing_to_any_timeline(): void
    {
        $yardTruck = Vehicle::create([
            'company_id' => self::COMPANY, 'registration_number' => 'MH12YARD1',
            'vehicle_type' => 'reefer', 'gps_device_id' => 'DEV-YARD-1',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);

        $before = DB::table('trip_events')->count();

        $this->ping([], 'DEV-YARD-1')->assertCreated();

        // Dropped deliberately rather than given an invented home. The reading
        // is still in telemetry_records; a vehicle-scoped timeline is Person
        // 1's to define if he wants one.
        $this->assertSame($before, DB::table('trip_events')->count());
        $this->assertSame(1, DB::table('telemetry_records')->where('vehicle_id', $yardTruck->id)->count());
    }

    public function test_a_ping_never_fails_because_the_timeline_did(): void
    {
        // A trip that is finished: the vehicle's readings no longer belong to it.
        DB::table('transport_trips')->where('id', $this->tripId)->update(['status' => 'closed']);

        $this->ping()->assertCreated();

        // The fill is a fact about a vehicle whether or not the timeline heard.
        $this->assertSame(1, DB::table('telemetry_records')->count());
        $this->assertCount(0, $this->events());
    }
}
