<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Fleet\Services\UreaService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-COST — the urea consumption check becomes visible (T-22, T-23).
 *
 * `UreaService` has logged an out-of-band top-up since M2 and no screen ever
 * showed it, so the check existed only for whoever reads the log — which is
 * nobody, in a workshop. These assert the verdict reaches the passport, and
 * that it stays a judgement rather than becoming a stored fact.
 */
class UreaConsumptionBandTest extends TestCase
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
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => 'staff',
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(): Vehicle
    {
        $v = Vehicle::create([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'ownership_type' => 'owned',
            'status' => 'active', 'compliance_status' => 'compliant',
        ]);

        VehicleLiveStatus::create([
            'vehicle_id' => $v->id, 'company_id' => self::COMPANY,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        return $v;
    }

    /** Two top-ups, so the second has a previous reading to measure against. */
    private function twoTopUps(Vehicle $vehicle, float $secondLitres, float $kmApart)
    {
        $userId = $this->user()->id;
        $urea = app(UreaService::class);

        $urea->record($vehicle->id, self::COMPANY, [
            'litres' => 20, 'amount' => 1800, 'odometer' => 100000,
        ], $userId);

        return $urea->record($vehicle->id, self::COMPANY, [
            'litres' => $secondLitres, 'amount' => 1800, 'odometer' => 100000 + $kmApart,
        ], $userId);
    }

    public function test_a_normal_top_up_is_not_flagged(): void
    {
        // 15 L over 1,000 km = 1.5 L/100km — the textbook figure for a truck.
        $row = $this->twoTopUps($this->vehicle(), 15, 1000);

        $this->assertSame('1.50', (string) $row->litres_per_100km);
        $this->assertFalse($row->outside_band);
    }

    public function test_a_top_up_above_the_band_is_flagged(): void
    {
        // 60 L over 1,000 km = 6.0 L/100km. Either a leak, a faulty dosing
        // unit, or urea going somewhere other than the tank.
        $row = $this->twoTopUps($this->vehicle(), 60, 1000);

        $this->assertTrue($row->outside_band);
        $this->assertGreaterThan(UreaService::EXPECTED_MAX, (float) $row->litres_per_100km);
    }

    public function test_a_top_up_below_the_band_is_flagged_too(): void
    {
        // 2 L over 1,000 km = 0.2 L/100km. Suspiciously low usually means the
        // dosing system is not dosing, which is an emissions fault, not a saving.
        $row = $this->twoTopUps($this->vehicle(), 2, 1000);

        $this->assertTrue($row->outside_band);
    }

    public function test_an_unmeasured_top_up_has_no_verdict_at_all(): void
    {
        $row = app(UreaService::class)->record($this->vehicle()->id, self::COMPANY, [
            'litres' => 20, 'amount' => 1800,   // no odometer
        ], $this->user()->id);

        // Null, not false. The first top-up on a vehicle is not "within band";
        // it is unmeasured, and reporting it as normal hides a missing reading.
        $this->assertNull($row->litres_per_100km);
        $this->assertNull($row->outside_band);
    }

    public function test_the_verdict_is_never_stored_on_the_row(): void
    {
        // The band is a policy that can be retuned. A stored flag would freeze
        // every historic row at the threshold in force the day it was entered,
        // so re-tuning the band would leave the history disagreeing with itself.
        $this->assertFalse(Schema::hasColumn('urea_transactions', 'outside_band'));
    }

    public function test_the_passport_carries_the_band_and_counts_the_exceptions(): void
    {
        $vehicle = $this->vehicle();
        $this->twoTopUps($vehicle, 60, 1000);   // one exception

        $urea = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()->json('data.urea');

        // The screen must not hardcode the thresholds, or the number it explains
        // and the number the service judges by can drift apart.
        // assertEquals, not assertSame: JSON has one number type, so a 4.0
        // arrives as 4. The value is the contract here, not its PHP type.
        $this->assertEquals(UreaService::EXPECTED_MIN, $urea['band']['min']);
        $this->assertEquals(UreaService::EXPECTED_MAX, $urea['band']['max']);
        $this->assertSame('L/100km', $urea['band']['unit']);
        $this->assertSame(1, $urea['exceptions']);

        $flagged = collect($urea['recent'])->firstWhere('outside_band', true);
        $this->assertNotNull($flagged, 'The row itself has to carry its own verdict');
    }

    public function test_the_entry_endpoint_the_screen_posts_to_accepts_what_it_sends(): void
    {
        $vehicle = $this->vehicle();

        // Exactly the payload UreaTopUpModal builds, nulls included.
        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/vehicles/{$vehicle->id}/urea", [
                'litres' => 20, 'rate_per_litre' => 90, 'amount' => 1800,
                'odometer' => null, 'station_vendor' => null,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('urea_transactions', [
            'vehicle_id' => $vehicle->id, 'company_id' => self::COMPANY,
        ]);
    }

    public function test_an_odometer_that_goes_backwards_is_refused(): void
    {
        $vehicle = $this->vehicle();
        $userId = $this->user()->id;

        app(UreaService::class)->record($vehicle->id, self::COMPANY, [
            'litres' => 20, 'amount' => 1800, 'odometer' => 100000,
        ], $userId);

        // A reading below the last one is a typo or the wrong vehicle. Accepting
        // it produces a negative interval and a consumption figure that is noise.
        $this->actingAs($this->user())
            ->postJson("/api/v1/fleet/vehicles/{$vehicle->id}/urea", [
                'litres' => 20, 'amount' => 1800, 'odometer' => 99000,
            ])
            ->assertStatus(422);
    }
}
