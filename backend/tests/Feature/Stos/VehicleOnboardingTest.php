<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\FuelTransaction;
use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Models\MaintenanceJob;
use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET Step 1 — a truck joins the fleet.
 *
 * This is the record every other Developer 2 endpoint hangs off, so the things
 * that must hold: one plate is one vehicle however it is typed, one device
 * reports for one truck, the live-status row exists from the moment the vehicle
 * does, and retiring keeps the financial history.
 */
class VehicleOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 1;
    private const OTHER   = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::COMPANY, self::OTHER] as $id) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => "Co{$id}", 'slug' => "co{$id}",
                'subdomain' => "co{$id}", 'status' => 'active',
            ])->save();
        }
    }

    private function user(string $role = 'staff', int $company = self::COMPANY): User
    {
        return User::create([
            'tenant_id' => $company, 'name' => ucfirst($role), 'role' => $role,
            'email' => $role.'-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function onboard(array $over = [], string $role = 'staff')
    {
        return $this->actingAs($this->user($role))->postJson('/api/v1/fleet/vehicles', array_merge([
            'registration_number' => 'MH12AB1234',
            'vehicle_type'        => 'reefer',
            'ownership_type'      => 'owned',
            'chassis_number'      => 'MAT477050N3K12345',
            'gps_device_id'       => 'DEV-0001',
        ], $over));
    }

    public function test_onboarding_creates_the_master_record_and_its_live_row(): void
    {
        $response = $this->onboard()->assertStatus(201);

        $id = $response->json('data.id');
        $this->assertNotNull($id, 'No vehicle_id was returned — nothing downstream can attach to this');

        // Step 2: the tracker's target exists from the moment the truck does,
        // so ingestion is a pure primary-key update for the rest of its life.
        $live = VehicleLiveStatus::find($id);
        $this->assertNotNull($live, 'vehicle_live_status was not initialised');
        $this->assertNull($live->last_ping_at);
        $this->assertSame(self::COMPANY, $live->company_id);
    }

    public function test_an_initialised_live_row_is_not_mistaken_for_a_reading(): void
    {
        $id = $this->onboard()->json('data.id');

        $row = collect(
            $this->actingAs($this->user())->getJson('/api/v1/fleet/vehicles')->json('data.vehicles')
        )->firstWhere('id', $id);

        // An empty row must not paint a full set of gauges reading "—".
        $this->assertNull($row['live'], 'An un-pinged live row was reported as telemetry');
        $this->assertSame('offline', $row['health']['state']);
        $this->assertNotNull(collect($row['health']['issues'])->firstWhere('code', 'never_reported'));
    }

    public function test_a_new_vehicle_can_immediately_receive_telemetry(): void
    {
        config(['stos.ingest.token' => 'tok']);
        $id = $this->onboard(['gps_device_id' => 'DEV-NEW'])->json('data.id');

        $this->withHeaders(['X-Device-Token' => 'tok'])->postJson('/api/v1/telemetry/ingest', [
            'device_id' => 'DEV-NEW', 'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => 40, 'ignition' => true, 'generator_status' => 'on', 'temperature' => -18.5,
            'recorded_at' => now()->toDateTimeString(),
        ])->assertStatus(201)->assertJsonPath('data.vehicle_id', $id);

        // Still exactly one live row — initialising did not create a second.
        $this->assertSame(1, VehicleLiveStatus::where('vehicle_id', $id)->count());
        $this->assertNotNull(VehicleLiveStatus::find($id)->last_ping_at);
    }

    public function test_one_plate_is_one_vehicle_however_it_is_typed(): void
    {
        $this->onboard(['registration_number' => 'MH12AB1234'])->assertStatus(201);

        // Stored normalised, so the unique index actually means something.
        $this->assertSame('MH12AB1234', Vehicle::first()->registration_number);

        foreach (['MH 12 AB 1234', 'mh-12-ab-1234'] as $sameTruck) {
            $this->onboard(['registration_number' => $sameTruck, 'chassis_number' => null, 'gps_device_id' => null])
                ->assertStatus(422);
        }

        $this->assertSame(1, Vehicle::count());
    }

    public function test_two_vehicles_cannot_share_a_device_or_a_chassis(): void
    {
        $this->onboard()->assertStatus(201);

        $this->onboard(['registration_number' => 'MH99XX0001', 'chassis_number' => 'OTHER-CHASSIS'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('gps_device_id');

        $this->onboard(['registration_number' => 'MH99XX0002', 'gps_device_id' => 'DEV-OTHER'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('chassis_number');
    }

    public function test_a_retired_plate_is_explained_not_silently_rejected(): void
    {
        $id = $this->onboard()->json('data.id');
        $this->actingAs($this->user('admin'))->deleteJson("/api/v1/fleet/vehicles/{$id}")->assertOk();

        $response = $this->onboard(['chassis_number' => null, 'gps_device_id' => null])->assertStatus(422);

        // The plate is held by a soft-deleted row: say so, rather than letting
        // the unique index throw a 500 at the user.
        $this->assertStringContainsString('retired', strtolower(json_encode($response->json())));
    }

    public function test_status_cannot_be_set_by_hand(): void
    {
        $id = $this->onboard()->json('data.id');
        Vehicle::whereKey($id)->update(['status' => 'in_maintenance']);

        $this->actingAs($this->user())->putJson("/api/v1/fleet/vehicles/{$id}", [
            'registration_number' => 'MH12AB1234',
            'vehicle_type' => 'reefer', 'ownership_type' => 'owned',
            'status' => 'active',
        ])->assertOk();

        // A truck goes back on the road through its job card, never through an
        // edit form — otherwise it leaves the workshop with its brakes in pieces.
        $this->assertSame('in_maintenance', Vehicle::find($id)->status);
    }

    public function test_retiring_keeps_the_history_and_frees_the_genset(): void
    {
        $id = $this->onboard()->json('data.id');

        FuelTransaction::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $id,
            'litres' => 100, 'rate_per_litre' => 90, 'amount' => 9000,
        ]);
        $genset = Genset::create([
            'company_id' => self::COMPANY, 'serial_number' => 'GEN-1', 'vehicle_id' => $id,
        ]);

        $this->actingAs($this->user('admin'))->deleteJson("/api/v1/fleet/vehicles/{$id}")->assertOk();

        // Soft delete: fuel spend is financial history and is meaningless
        // attached to an id that no longer resolves to a number plate.
        $this->assertSoftDeleted('vehicles', ['id' => $id]);
        $this->assertSame(1, FuelTransaction::where('vehicle_id', $id)->count());
        // The power unit outlives the truck it was bolted to.
        $this->assertNull($genset->fresh()->vehicle_id);
        // No live state for a retired vehicle.
        $this->assertNull(VehicleLiveStatus::find($id));
    }

    public function test_a_vehicle_with_open_work_cannot_be_retired(): void
    {
        $id = $this->onboard()->json('data.id');
        MaintenanceJob::create([
            'company_id' => self::COMPANY, 'vehicle_id' => $id,
            'job_card_number' => 'JC-1', 'status' => 'IN_PROGRESS',
        ]);

        $this->actingAs($this->user('admin'))->deleteJson("/api/v1/fleet/vehicles/{$id}")->assertStatus(422);
        $this->assertNotSoftDeleted('vehicles', ['id' => $id]);
    }

    public function test_only_an_admin_retires_a_vehicle(): void
    {
        $id = $this->onboard()->json('data.id');

        // Staff onboard and edit — a transport manager is staff, and blocking
        // them would mean only the account owner could add a truck.
        $this->actingAs($this->user('staff'))->deleteJson("/api/v1/fleet/vehicles/{$id}")->assertForbidden();
        $this->assertNotSoftDeleted('vehicles', ['id' => $id]);
    }

    public function test_a_portal_login_cannot_onboard_a_vehicle(): void
    {
        foreach (['client', 'vendor', 'third_party_vendor'] as $role) {
            $this->onboard(['registration_number' => 'MH00XX'.random_int(1000, 9999)], $role)->assertForbidden();
        }

        $this->assertSame(0, Vehicle::count());
    }

    public function test_a_vehicle_is_onboarded_into_the_users_own_company(): void
    {
        $id = $this->onboard()->json('data.id');

        $this->assertSame(self::COMPANY, Vehicle::find($id)->company_id);

        // And another company's vehicle cannot be edited by guessing its id.
        $theirs = Vehicle::create([
            'company_id' => self::OTHER, 'registration_number' => 'MH88ZZ1111',
            'vehicle_type' => 'truck', 'ownership_type' => 'owned',
        ]);

        $this->actingAs($this->user())->putJson("/api/v1/fleet/vehicles/{$theirs->id}", [
            'registration_number' => 'MH88ZZ2222', 'vehicle_type' => 'truck', 'ownership_type' => 'owned',
        ])->assertNotFound();

        $this->assertSame('MH88ZZ1111', $theirs->fresh()->registration_number);
    }
}
