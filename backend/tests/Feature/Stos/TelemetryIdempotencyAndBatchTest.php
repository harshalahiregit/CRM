<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\TelemetryRecord;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STOS-INT — the same ping twice, and an hour of pings at once (T-12, T-13).
 *
 * Both exist for the same reason: a unit that loses signal keeps its readings
 * and sends them when the link returns. Until now that replay appended a second
 * copy of every ping it had already delivered, and it had to make one request
 * per reading over the link that just failed.
 */
class TelemetryIdempotencyAndBatchTest extends TestCase
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

    private function reading(array $over = []): array
    {
        return array_merge([
            'device_id'        => self::DEVICE,
            'latitude'         => '19.07609500',
            'longitude'        => '72.87765800',
            'speed'            => 46.5,
            'ignition'         => true,
            'generator_status' => 'on',
            'temperature'      => -18.5,
            'recorded_at'      => now()->subMinutes(5)->toDateTimeString(),
        ], $over);
    }

    private function ping(array $over = [])
    {
        return $this->withHeaders(['X-Device-Token' => self::TOKEN])
            ->postJson('/api/v1/telemetry/ingest', $this->reading($over));
    }

    private function batch(array $readings, ?string $token = self::TOKEN)
    {
        return $this->withHeaders($token === null ? [] : ['X-Device-Token' => $token])
            ->postJson('/api/v1/telemetry/ingest/batch', ['readings' => $readings]);
    }

    /* ── T-12: the same ping twice ──────────────────────────────── */

    public function test_the_same_ping_twice_is_stored_once(): void
    {
        $at = now()->subMinutes(5)->toDateTimeString();

        $first = $this->ping(['recorded_at' => $at])->assertCreated();
        $second = $this->ping(['recorded_at' => $at])->assertCreated();

        // One device has one clock: the same instant is the same reading, not
        // a second fact about the world.
        $this->assertSame(1, TelemetryRecord::where('device_id', self::DEVICE)->count());
        $this->assertSame(
            $first->json('data.telemetry_record_id'),
            $second->json('data.telemetry_record_id'),
            'The retry must be answered with the record it already stored'
        );
    }

    public function test_a_retry_is_answered_as_success_not_as_a_conflict(): void
    {
        $at = now()->subMinutes(5)->toDateTimeString();
        $this->ping(['recorded_at' => $at]);

        $response = $this->ping(['recorded_at' => $at])->assertCreated();

        // A device told 409 by a retry it could not avoid either retries forever
        // or drops its buffer. Both lose trail the server already holds.
        $this->assertTrue($response->json('data.duplicate'));
    }

    public function test_two_readings_a_second_apart_are_both_kept(): void
    {
        $this->ping(['recorded_at' => now()->subMinutes(5)->toDateTimeString()]);
        $this->ping(['recorded_at' => now()->subMinutes(5)->addSecond()->toDateTimeString()]);

        // Dedupe is on the instant, not on the device. Two genuine readings a
        // second apart are two facts.
        $this->assertSame(2, TelemetryRecord::where('device_id', self::DEVICE)->count());
    }

    public function test_a_device_id_claimed_by_two_companies_is_refused_not_guessed(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        // `gps_device_id` is unique per COMPANY, so two workspaces can each
        // claim the same id — device ids are chosen by whoever fits the
        // hardware, and units come from the same batches.
        Vehicle::create([
            'company_id' => self::COMPANY, 'registration_number' => 'MH12CD5678',
            'vehicle_type' => 'truck', 'gps_device_id' => 'DEV-SHARED-ID',
        ]);
        Vehicle::create([
            'company_id' => 2, 'registration_number' => 'MH99ZZ0001',
            'vehicle_type' => 'truck', 'gps_device_id' => 'DEV-SHARED-ID',
        ]);

        $response = $this->withHeaders(['X-Device-Token' => self::TOKEN])
            ->postJson('/api/v1/telemetry/ingest', $this->reading(['device_id' => 'DEV-SHARED-ID']));

        // Refused, not guessed. With one fleet-wide secret there is no way to
        // tell which company is calling, and picking one writes a position and
        // a temperature onto another company's truck. That is the case T-07's
        // per-device tokens exist to resolve.
        $response->assertStatus(409);
        $this->assertSame(0, TelemetryRecord::where('device_id', 'DEV-SHARED-ID')->count());
    }

    public function test_an_ambiguous_device_in_a_batch_rejects_only_its_own_readings(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        foreach ([[self::COMPANY, 'MH12CD5678'], [2, 'MH99ZZ0001']] as [$company, $plate]) {
            Vehicle::create([
                'company_id' => $company, 'registration_number' => $plate,
                'vehicle_type' => 'truck', 'gps_device_id' => 'DEV-SHARED-ID',
            ]);
        }

        $data = $this->batch([
            $this->reading(['recorded_at' => now()->subMinutes(3)->toDateTimeString()]),
            $this->reading(['device_id' => 'DEV-SHARED-ID', 'recorded_at' => now()->subMinutes(2)->toDateTimeString()]),
        ])->assertCreated()->json('data');

        $this->assertSame(1, $data['accepted']);
        $this->assertCount(1, $data['rejected']);
        $this->assertSame('DEV-SHARED-ID', $data['rejected'][0]['device']);
    }

    public function test_a_replayed_duplicate_does_not_disturb_the_live_row(): void
    {
        $old = now()->subMinutes(30)->toDateTimeString();
        $this->ping(['recorded_at' => $old, 'speed' => 10]);

        // A newer ping moves the live row forward.
        $this->ping(['recorded_at' => now()->subMinute()->toDateTimeString(), 'speed' => 80]);

        // Now the unit replays the old one. It must not drag "now" backwards.
        $this->ping(['recorded_at' => $old, 'speed' => 10])->assertCreated();

        $live = VehicleLiveStatus::where('vehicle_id', $this->vehicle->id)->first();
        $this->assertSame('80.00', (string) $live->speed);
    }

    /* ── T-13: a buffered hour in one request ───────────────────── */

    public function test_a_buffer_is_accepted_in_one_request(): void
    {
        $readings = [];
        for ($i = 10; $i >= 1; $i--) {
            $readings[] = $this->reading([
                'recorded_at' => now()->subMinutes($i)->toDateTimeString(),
                'speed' => $i * 5,
            ]);
        }

        $data = $this->batch($readings)->assertCreated()->json('data');

        $this->assertSame(10, $data['received']);
        $this->assertSame(10, $data['accepted']);
        $this->assertSame(0, $data['duplicates']);
        $this->assertSame([], $data['rejected']);
        $this->assertSame(10, TelemetryRecord::where('device_id', self::DEVICE)->count());
    }

    public function test_a_batch_delivered_newest_first_still_leaves_the_live_row_correct(): void
    {
        // Devices do not promise an order. The service sorts by the device's own
        // clock before writing, because the live row only moves forward — an
        // unsorted batch would leave "now" showing the oldest ping in it.
        $newest = $this->reading(['recorded_at' => now()->subMinute()->toDateTimeString(), 'speed' => 88]);
        $oldest = $this->reading(['recorded_at' => now()->subMinutes(45)->toDateTimeString(), 'speed' => 12]);
        $middle = $this->reading(['recorded_at' => now()->subMinutes(20)->toDateTimeString(), 'speed' => 40]);

        $this->batch([$newest, $oldest, $middle])->assertCreated();

        $live = VehicleLiveStatus::where('vehicle_id', $this->vehicle->id)->first();
        $this->assertSame('88.00', (string) $live->speed, 'The newest reading must win regardless of arrival order');
    }

    public function test_one_bad_reading_does_not_lose_the_rest_of_the_hour(): void
    {
        $readings = [
            $this->reading(['recorded_at' => now()->subMinutes(3)->toDateTimeString()]),
            // An unknown device — the trail belongs to a unit we do not have.
            $this->reading(['device_id' => 'DEV-NOT-FITTED', 'recorded_at' => now()->subMinutes(2)->toDateTimeString()]),
            $this->reading(['recorded_at' => now()->subMinute()->toDateTimeString()]),
        ];

        $data = $this->batch($readings)->assertCreated()->json('data');

        // Failing the whole batch would throw away two good positions the device
        // has no way to resend on their own.
        $this->assertSame(2, $data['accepted']);
        $this->assertCount(1, $data['rejected']);
        $this->assertSame('DEV-NOT-FITTED', $data['rejected'][0]['device']);
    }

    public function test_a_replayed_batch_adds_nothing_and_says_so(): void
    {
        $readings = [
            $this->reading(['recorded_at' => now()->subMinutes(3)->toDateTimeString()]),
            $this->reading(['recorded_at' => now()->subMinutes(2)->toDateTimeString()]),
        ];

        $this->batch($readings)->assertCreated();
        $second = $this->batch($readings)->assertCreated()->json('data');

        // The common case after a flaky link: the device is not sure the first
        // batch landed, so it sends it again.
        $this->assertSame(0, $second['accepted']);
        $this->assertSame(2, $second['duplicates']);
        $this->assertSame(2, TelemetryRecord::where('device_id', self::DEVICE)->count());
    }

    public function test_an_unsigned_batch_is_refused(): void
    {
        // Same door, same lock. A batch endpoint that forgot its auth would be
        // an open write endpoint for forged temperature trails.
        $this->batch([$this->reading()], null)->assertStatus(401);
    }

    public function test_an_empty_batch_is_refused(): void
    {
        $this->batch([])->assertStatus(422)->assertJsonValidationErrors('readings');
    }

    public function test_an_oversized_batch_is_refused(): void
    {
        $readings = [];
        for ($i = 0; $i < 501; $i++) {
            $readings[] = $this->reading(['recorded_at' => now()->subMinutes($i + 1)->toDateTimeString()]);
        }

        // A malfunctioning unit must not be able to post a week of history in
        // one request and hold a worker for the length of it.
        $this->batch($readings)->assertStatus(422)->assertJsonValidationErrors('readings');
    }

    public function test_a_future_dated_reading_in_a_batch_is_refused(): void
    {
        $readings = [
            $this->reading(),
            $this->reading(['recorded_at' => now()->addHours(2)->toDateTimeString()]),
        ];

        // A misconfigured clock would otherwise sit at the top of every
        // "latest" query forever.
        $this->batch($readings)->assertStatus(422);
    }
}
