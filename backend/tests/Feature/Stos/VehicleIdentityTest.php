<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — the identity and payload columns are reachable (T-01).
 *
 * The D-62 union brought Operations' columns onto `vehicles` — make, model,
 * year, fuel, branch and `capacity_tonnes` — and nothing could set them. Every
 * vehicle onboarded through Fleet came out blank, and a blank `capacity_tonnes`
 * is the one that reaches beyond the passport: Operations' eligibility engine
 * matches it against an order's required payload, so those vehicles were
 * invisible to capacity-based allocation.
 */
class VehicleIdentityTest extends TestCase
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

    private function payload(array $over = []): array
    {
        return array_merge([
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type'   => 'truck',
            'ownership_type' => 'owned',
        ], $over);
    }

    public function test_onboarding_stores_the_identity_and_the_payload(): void
    {
        $data = $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', $this->payload([
                'fleet_number'       => 'TRK-014',
                'manufacturer'       => 'Tata',
                'model'              => 'Signa 4825',
                'variant'            => 'TK',
                'manufacturing_year' => 2021,
                'purchase_date'      => '2021-06-15',
                'fuel_type'          => 'diesel',
                'branch'             => 'Bhiwandi',
                'capacity_tonnes'    => 25,
            ]))
            ->assertCreated()->json('data');

        $this->assertSame('TRK-014', $data['fleet_number']);
        $this->assertSame('Tata', $data['manufacturer']);
        $this->assertSame('Signa 4825', $data['model']);
        $this->assertSame(2021, $data['manufacturing_year']);
        $this->assertSame('diesel', $data['fuel_type']);
        $this->assertSame('Bhiwandi', $data['branch']);
        $this->assertEquals(25, $data['capacity_tonnes']);
    }

    public function test_a_blank_payload_is_null_and_never_zero(): void
    {
        $vehicle = Vehicle::create($this->payload(['company_id' => self::COMPANY]));

        // This is the distinction that matters. A 0-tonne truck is one the
        // eligibility engine will never match to an order, and it looks like a
        // recorded fact rather than a missing one — so the screen sends null.
        $this->assertNull($vehicle->fresh()->capacity_tonnes);
        $this->assertNotSame('0.00', (string) $vehicle->fresh()->capacity_tonnes);
    }

    public function test_the_payload_reaches_the_passport(): void
    {
        $vehicle = Vehicle::create($this->payload([
            'company_id' => self::COMPANY, 'capacity_tonnes' => 18.5, 'manufacturer' => 'Ashok Leyland',
        ]));

        $data = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()->json('data.vehicle');

        $this->assertEquals(18.5, $data['capacity_tonnes']);
        $this->assertSame('Ashok Leyland', $data['manufacturer']);
    }

    public function test_an_impossible_manufacturing_year_is_refused(): void
    {
        // A typo here silently ages the fleet in every report that uses it.
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', $this->payload(['manufacturing_year' => 1850]))
            ->assertStatus(422)->assertJsonValidationErrors('manufacturing_year');

        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', $this->payload(['manufacturing_year' => 2999]))
            ->assertStatus(422)->assertJsonValidationErrors('manufacturing_year');
    }

    public function test_a_purchase_date_in_the_future_is_refused(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', $this->payload([
                'purchase_date' => now()->addYear()->toDateString(),
            ]))
            ->assertStatus(422)->assertJsonValidationErrors('purchase_date');
    }

    public function test_an_unknown_fuel_type_is_refused(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', $this->payload(['fuel_type' => 'coal']))
            ->assertStatus(422)->assertJsonValidationErrors('fuel_type');
    }

    public function test_a_negative_payload_is_refused(): void
    {
        $this->actingAs($this->user())
            ->postJson('/api/v1/fleet/vehicles', $this->payload(['capacity_tonnes' => -5]))
            ->assertStatus(422)->assertJsonValidationErrors('capacity_tonnes');
    }

    public function test_a_migrated_row_with_an_unfamiliar_fuel_type_is_still_readable(): void
    {
        // Rows moved from Operations carry whatever that table held. The
        // vocabulary is enforced on input only — rejecting a stored value would
        // make a vehicle unopenable in the very screen used to correct it.
        $vehicle = Vehicle::create($this->payload(['company_id' => self::COMPANY]));
        DB::table('vehicles')->where('id', $vehicle->id)->update(['fuel_type' => 'bio-diesel']);

        $data = $this->actingAs($this->user())
            ->getJson("/api/v1/fleet/vehicles/{$vehicle->id}/passport")
            ->assertOk()->json('data.vehicle');

        $this->assertSame('bio-diesel', $data['fuel_type']);
    }

    public function test_the_identity_can_be_corrected_after_onboarding(): void
    {
        $vehicle = Vehicle::create($this->payload(['company_id' => self::COMPANY, 'capacity_tonnes' => 25]));

        $data = $this->actingAs($this->user())
            ->putJson("/api/v1/fleet/vehicles/{$vehicle->id}", $this->payload([
                'registration_number' => $vehicle->registration_number,
                'capacity_tonnes' => 31.5, 'manufacturer' => 'BharatBenz',
            ]))
            ->assertOk()->json('data');

        $this->assertEquals(31.5, $data['capacity_tonnes']);
        $this->assertSame('BharatBenz', $data['manufacturer']);
    }
}
