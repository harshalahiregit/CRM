<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\TelemetryRecord;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Integration\Events\TelemetryExcursionDetected;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * STOS-INT — POST /api/v1/telemetry/ingest.
 *
 * The three things the endpoint promises: the live row updates instantly, the
 * history row is appended, and the excursion event fires on genset OFF above
 * the threshold. Plus the ways that path can go wrong quietly — an unsigned
 * caller, an unknown device, a replayed buffer marching the truck backwards.
 */
class TelemetryIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const TOKEN = 'test-device-token';
    private const DEVICE = 'DEV-TEST-0001';

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['stos.ingest.token' => self::TOKEN]);

        (new Tenant())->forceFill([
            'id' => self::COMPANY, 'name' => 'Co1', 'slug' => 'co1',
            'subdomain' => 'co1', 'status' => 'active',
        ])->save();

        $this->vehicle = Vehicle::create([
            'company_id'          => self::COMPANY,
            'registration_number' => 'MH12AB1234',
            'vehicle_type'        => 'reefer',
            'gps_device_id'       => self::DEVICE,
        ]);
    }

    private function ping(array $over = [], ?string $token = self::TOKEN)
    {
        $payload = array_merge([
            'device_id'        => self::DEVICE,
            'latitude'         => '19.07609500',
            'longitude'        => '72.87765800',
            'speed'            => 46.5,
            'ignition'         => true,
            'generator_status' => 'on',
            'temperature'      => -18.5,
            'recorded_at'      => now()->toDateTimeString(),
        ], $over);

        return $this->withHeaders($token === null ? [] : ['X-Device-Token' => $token])
            ->postJson('/api/v1/telemetry/ingest', $payload);
    }

    public function test_a_ping_updates_live_status_and_appends_history(): void
    {
        $response = $this->ping();

        $response->assertStatus(201)
            ->assertJsonPath('data.vehicle_id', $this->vehicle->id)
            ->assertJsonPath('data.live_status_updated', true)
            ->assertJsonPath('data.excursion_detected', false);

        // Tier 1 — one row, current.
        $live = VehicleLiveStatus::find($this->vehicle->id);
        $this->assertNotNull($live, 'vehicle_live_status was not written');
        $this->assertSame('19.07609500', (string) $live->latitude);
        $this->assertSame('46.50', (string) $live->speed);
        $this->assertTrue((bool) $live->ignition);

        // Tier 2 — appended.
        $this->assertSame(1, TelemetryRecord::where('vehicle_id', $this->vehicle->id)->count());
        $this->assertSame(self::DEVICE, TelemetryRecord::first()->device_id);
    }

    public function test_repeated_pings_keep_one_live_row_and_many_history_rows(): void
    {
        foreach ([10, 20, 30] as $i => $speed) {
            $this->ping([
                'speed'       => $speed,
                'recorded_at' => now()->addSeconds($i * 30)->toDateTimeString(),
            ])->assertStatus(201);
        }

        $this->assertSame(1, VehicleLiveStatus::where('vehicle_id', $this->vehicle->id)->count());
        $this->assertSame(3, TelemetryRecord::where('vehicle_id', $this->vehicle->id)->count());
        $this->assertSame('30.00', (string) VehicleLiveStatus::find($this->vehicle->id)->speed);
    }

    public function test_the_excursion_event_fires_when_the_genset_is_off_above_the_threshold(): void
    {
        Event::fake([TelemetryExcursionDetected::class]);

        $this->ping(['generator_status' => 'off', 'temperature' => -12.4])
            ->assertStatus(201)
            ->assertJsonPath('data.excursion_detected', true);

        Event::assertDispatched(
            TelemetryExcursionDetected::class,
            function (TelemetryExcursionDetected $e) {
                return $e->vehicle->is($this->vehicle)
                    && $e->temperature === -12.4
                    && $e->threshold === -18.0
                    && $e->wasLive === true
                    && $e->degreesOver() === 5.6;
            }
        );
    }

    public function test_a_healthy_reefer_raises_nothing(): void
    {
        Event::fake([TelemetryExcursionDetected::class]);

        // Genset running, load frozen.
        $this->ping(['generator_status' => 'on', 'temperature' => -20.0])->assertStatus(201);
        // Genset off but still at the limit — AT the threshold is not OVER it.
        $this->ping(['generator_status' => 'off', 'temperature' => -18.0])->assertStatus(201);
        // Genset off and warm, but the probe said nothing: never infer a breach
        // from a missing reading.
        $this->ping(['generator_status' => 'off', 'temperature' => null])->assertStatus(201);

        Event::assertNotDispatched(TelemetryExcursionDetected::class);
    }

    public function test_a_parked_reefer_raises_nothing_under_the_m2_motion_rule(): void
    {
        Event::fake([TelemetryExcursionDetected::class]);

        // Genset off and the body warming, but the vehicle is stationary. M2
        // requires motion, so this is deliberately silent — see the warning in
        // config/stos.php about a LOADED trailer standing in a yard.
        $this->ping(['generator_status' => 'off', 'temperature' => -5.0, 'speed' => 0])
            ->assertStatus(201)
            ->assertJsonPath('data.excursion_detected', false);

        Event::assertNotDispatched(TelemetryExcursionDetected::class);

        // Turn the motion requirement off and the same reading does raise it,
        // so the trade stays a config decision rather than a code rewrite.
        config(['stos.telemetry.excursion_requires_motion' => false]);

        $this->ping([
            'generator_status' => 'off', 'temperature' => -5.0, 'speed' => 0,
            'recorded_at' => now()->addMinute()->toDateTimeString(),
        ])->assertJsonPath('data.excursion_detected', true);

        Event::assertDispatched(TelemetryExcursionDetected::class);
    }

    public function test_a_replayed_buffer_is_kept_in_history_but_does_not_drag_the_live_row_back(): void
    {
        $this->ping(['speed' => 60, 'recorded_at' => now()->toDateTimeString()])->assertStatus(201);

        // The unit comes out of a tunnel and replays an older fix.
        $this->ping([
            'speed'       => 5,
            'recorded_at' => now()->subHour()->toDateTimeString(),
        ])->assertStatus(201)->assertJsonPath('data.live_status_updated', false);

        // History kept both; the live row still shows the newest.
        $this->assertSame(2, TelemetryRecord::where('vehicle_id', $this->vehicle->id)->count());
        $this->assertSame('60.00', (string) VehicleLiveStatus::find($this->vehicle->id)->speed);
    }

    public function test_the_endpoint_refuses_callers_without_the_device_token(): void
    {
        $this->ping([], null)->assertStatus(401);
        $this->ping([], 'wrong-token')->assertStatus(401);

        $this->assertSame(0, TelemetryRecord::count());
    }

    public function test_the_endpoint_fails_closed_when_no_token_is_configured(): void
    {
        config(['stos.ingest.token' => null]);

        $this->ping([], 'anything')->assertStatus(503);
        $this->assertSame(0, TelemetryRecord::count());
    }

    public function test_an_unknown_device_is_rejected_not_silently_dropped(): void
    {
        $this->ping(['device_id' => 'DEV-NOT-REGISTERED'])->assertStatus(404);

        $this->assertSame(0, TelemetryRecord::count());
    }

    public function test_impossible_readings_are_rejected(): void
    {
        $this->ping(['latitude' => 999])->assertStatus(422);
        $this->ping(['temperature' => 250])->assertStatus(422);
        $this->ping(['generator_status' => 'melted'])->assertStatus(422);
        // A device whose clock is wrong would otherwise top every query forever.
        $this->ping(['recorded_at' => now()->addHours(3)->toDateTimeString()])->assertStatus(422);

        $this->assertSame(0, TelemetryRecord::count());
    }

    public function test_a_ping_is_recorded_against_the_vehicles_own_company(): void
    {
        $this->ping()->assertStatus(201);

        $this->assertSame(self::COMPANY, TelemetryRecord::first()->company_id);
        $this->assertSame(self::COMPANY, VehicleLiveStatus::find($this->vehicle->id)->company_id);
    }
}
