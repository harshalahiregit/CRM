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
 *
 * ── WHAT THE SETUP HAD WRONG, AND WHY IT MATTERED ─────────────────────────
 * These tests used to point the demonstration trip at the Fleet vehicle's own
 * id with no `transport_vehicles` row behind it. That is not what the live data
 * looks like — before the repoint, `transport_trips.vehicle_id` holds a LEGACY
 * id — and it is the exact arrangement the second half of D-116 was about. So
 * eight of them passed by asking the Fleet table whether a Fleet id was that
 * Fleet vehicle, which it always is.
 *
 * The setup now builds the world as it actually is: a legacy row per truck, in
 * an id range deliberately clear of Fleet's, and the trip pointing at it.
 */
class TripTimelineTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const TOKEN = 'test-device-token';
    private const DEVICE = 'DEV-TL-0001';
    private const PLATE = 'MH12TL0001';

    /**
     * Legacy ids start here so that nothing in these tests can pass because
     * two independent sequences happened to issue the same number. The one
     * test that NEEDS them to collide arranges it explicitly.
     */
    private const LEGACY_BASE = 900;

    private Vehicle $vehicle;
    private int $legacyId;
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
            'company_id' => self::COMPANY, 'registration_number' => self::PLATE,
            'vehicle_type' => 'reefer', 'gps_device_id' => self::DEVICE,
            'status' => Vehicle::STATUS_IN_TRANSIT,
        ]);

        // Before the repoint the trip references the truck's legacy id, and the
        // Fleet row records which one that was.
        $this->legacyId = $this->legacyVehicle(self::PLATE, self::LEGACY_BASE);
        $this->vehicle->forceFill(['legacy_transport_vehicle_id' => $this->legacyId])->save();

        $this->tripId = $this->trip('in_transit', $this->legacyId);

        $this->assertNotSame($this->legacyId, $this->vehicle->id,
            'the two id spaces must not overlap, or these tests prove nothing');
    }

    private function legacyVehicle(string $plate, int $id): int
    {
        DB::table('transport_vehicles')->insert([
            'id' => $id, 'tenant_id' => self::COMPANY,
            'registration_number' => $plate,
            'registration_normalized' => preg_replace('/[^A-Z0-9]/', '', strtoupper($plate)),
            'vehicle_type' => 'REEFER', 'ownership_type' => 'OWNED', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
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

    /** What `stos:repoint-trip-fleet-refs --apply` writes down about a trip. */
    private function repointVerdict(int $tripId, int $from, ?int $to): void
    {
        DB::table('fleet_reference_repoints')->insert([
            'company_id' => self::COMPANY,
            'table_name' => 'transport_trips', 'column_name' => 'vehicle_id',
            'row_id' => $tripId, 'from_id' => $from, 'to_id' => $to,
            'applied_at' => now(),
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

    /* ── D-116: the id alone cannot be interpreted ──────── */

    /*
     * Person 1 found this twice, and constructed the failure case both times
     * rather than accepting the fix. `transport_trips.vehicle_id` holds a
     * `transport_vehicles` id today and a `vehicles` id after the repoint —
     * two independent id spaces, no foreign key, nothing saying which.
     *
     * Round one: I compared a Fleet id straight against that column and wrote
     * the mismatch off as "finds nothing, which is correct". It was not
     * correct, it was lucky — the ranges did not overlap.
     *
     * Round two: I asked the plate to decide but resolved it in BOTH masters.
     * With the legacy row absent, the only plate returned was Fleet's own, read
     * with the trip's number — so the check compared a vehicle's plate against
     * itself and could not fail.
     *
     * These are his three cases — same truck, wrong truck, dangling id — plus
     * the id-space switch that comes after them.
     */

    public function test_a_trip_on_the_same_truck_is_published_to(): void
    {
        // Case 1. The trip points at the legacy row for THIS truck. It should
        // publish, and before the fix to the candidate search it did not: the
        // stored link went stale and nothing was even considered.
        $this->ping()->assertCreated();

        $this->assertCount(1, $this->events('gps.activated'));
    }

    public function test_a_trip_pointing_at_a_different_truck_is_not_published_to(): void
    {
        // Case 2. The trip's vehicle_id matches this vehicle's id as a NUMBER,
        // but belongs to a different truck in the legacy master.
        $this->legacyVehicle('MH99OTHER9', $this->vehicle->id);
        DB::table('transport_trips')->where('id', $this->tripId)
            ->update(['vehicle_id' => $this->vehicle->id]);

        $this->ping()->assertCreated();

        // No error, no event. A timeline entry naming the wrong truck is worse
        // than a missing one, because the missing one gets noticed.
        $this->assertCount(0, $this->events());
        $this->assertSame(1, DB::table('telemetry_records')->count(), 'the reading is still kept');
    }

    public function test_a_trip_on_a_dangling_id_that_equals_a_fleet_id_is_not_published_to(): void
    {
        // Case 3, and the one that was still publishing. The trip points at a
        // legacy row that a reseed removed, and that number happens to be a
        // live Fleet id. There is no legacy row to read the plate from — which
        // is UNKNOWN, and unknown must never resolve to a match.
        DB::table('transport_trips')->where('id', $this->tripId)
            ->update(['vehicle_id' => $this->vehicle->id]);

        $this->assertFalse(
            DB::table('transport_vehicles')->where('id', $this->vehicle->id)->exists(),
            'the point of this case is that the legacy row is gone'
        );

        $this->ping()->assertCreated();

        $this->assertCount(0, $this->events());
        $this->assertSame(1, DB::table('telemetry_records')->count());
    }

    public function test_the_plate_decides_even_when_spacing_differs(): void
    {
        DB::table('transport_vehicles')->where('id', $this->legacyId)
            ->update(['registration_number' => 'MH 12 TL 0001']);

        $this->ping()->assertCreated();

        $this->assertCount(1, $this->events('gps.activated'));
    }

    public function test_a_stale_legacy_link_no_longer_hides_the_trip(): void
    {
        // The other half of what Person 1 found: `legacy_transport_vehicle_id`
        // pointed at rows 29 and 30 while the live rows for the same trucks
        // were 35 and 36. Nothing overlapped, so the RIGHT trip found no
        // candidate and the reading was dropped — indistinguishable on screen
        // from an idle truck. The plate now finds candidates too.
        $this->vehicle->forceFill(['legacy_transport_vehicle_id' => 29])->save();

        $this->assertFalse(DB::table('transport_vehicles')->where('id', 29)->exists());

        $this->ping()->assertCreated();

        $this->assertCount(1, $this->events('gps.activated'));
    }

    /* ── After the switch, the repoint's own record decides ─────── */

    public function test_a_repointed_trip_is_published_to_on_its_fleet_id(): void
    {
        DB::table('transport_trips')->where('id', $this->tripId)
            ->update(['vehicle_id' => $this->vehicle->id]);
        $this->repointVerdict($this->tripId, $this->legacyId, $this->vehicle->id);

        $this->ping()->assertCreated();

        $this->assertCount(1, $this->events('gps.activated'));
    }

    public function test_a_trip_the_repoint_could_not_map_is_never_published_to(): void
    {
        // The repoint found nothing to map this trip to, left it alone and said
        // so. That verdict is permanent: the number is still in the old space
        // and it happens to equal a live Fleet id.
        $stranded = $this->trip('in_transit', $this->vehicle->id);
        $this->repointVerdict($stranded, $this->vehicle->id, null);

        // Another trip in the same company HAS been repointed, so the switch
        // has happened — which is what makes the stranded one dangerous.
        $this->repointVerdict($this->tripId, $this->legacyId, 4242);

        $this->ping()->assertCreated();

        $this->assertSame(0, DB::table('trip_events')->where('trip_id', $stranded)->count());
    }

    public function test_a_trip_raised_after_the_switch_is_published_to(): void
    {
        // No verdict of its own — it did not exist when the repoint ran — but
        // the company has switched, so its vehicle_id is a Fleet id.
        $this->repointVerdict($this->tripId, $this->legacyId, 4242);

        $fresh = $this->trip('in_transit', $this->vehicle->id);

        $this->ping()->assertCreated();

        $this->assertSame(1, DB::table('trip_events')
            ->where('trip_id', $fresh)->where('event_type', 'gps.activated')->count());
    }

    public function test_a_trip_re_crewed_after_the_switch_follows_its_new_truck(): void
    {
        // A breakdown swap after the repoint: release clears the trip's
        // pointer, reassignment writes the replacement truck. The ledger still
        // names the truck that was replaced. The verdict used to govern the
        // trip row forever, so the replacement's telemetry was refused and the
        // trip went dark at exactly the moment somebody needs to watch it.
        $this->repointVerdict($this->tripId, $this->legacyId, 4242);

        DB::table('transport_trips')->where('id', $this->tripId)
            ->update(['vehicle_id' => $this->vehicle->id]);

        $this->ping()->assertCreated();

        $this->assertCount(1, $this->events('gps.activated'));
    }

    public function test_a_corrected_never_valid_trip_is_published_to_again(): void
    {
        // Person 1's split: never_valid is fixed by somebody correcting the
        // row. A correction has to be visible to the publisher, or the row is
        // refused forever for a value it no longer holds.
        $this->repointVerdict($this->tripId, 1212010, null);

        DB::table('transport_trips')->where('id', $this->tripId)
            ->update(['vehicle_id' => $this->vehicle->id]);

        $this->ping()->assertCreated();

        $this->assertCount(1, $this->events('gps.activated'));
    }

    public function test_an_uncorrected_never_valid_trip_is_still_refused(): void
    {
        // The other half, so the fix cannot quietly widen into "any verdict
        // can be ignored": while the row still holds the stranded value, it
        // names no truck we can vouch for, even though that number happens to
        // equal this Fleet vehicle's id.
        $this->repointVerdict($this->tripId, $this->vehicle->id, null);

        DB::table('transport_trips')->where('id', $this->tripId)
            ->update(['vehicle_id' => $this->vehicle->id]);

        $this->ping()->assertCreated();

        $this->assertCount(0, $this->events());
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
