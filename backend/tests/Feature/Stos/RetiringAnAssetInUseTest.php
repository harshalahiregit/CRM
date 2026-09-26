<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Integration\TripCommitmentReader;
use App\Domains\Fleet\Integration\TripCommitmentUnavailable;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * D-146 — an asset on a live trip cannot be taken out of use.
 *
 * ── WHY THIS FILE EXISTS ──────────────────────────────────────────────────
 * The legacy master refused to delete a vehicle or a driver while an active
 * assignment held it, and three tests in `TransportMasterAllocationAuditTest`
 * asserted it. When the legacy write surface became read-only those tests went
 * red, and Person 1 left them red rather than move them somewhere the rule was
 * not enforced — because `grep -rn "trip_assignments" app/Domains/Fleet/`
 * returned **0**. Fleet had no way to know.
 *
 * This is the enforcement those tests were waiting for, asserted where the
 * rule now lives. It makes the same three claims they make:
 *
 *   1. a vehicle held by an active assignment cannot be taken out of use;
 *   2. a driver held by one cannot be either;
 *   3. once the assignment is RELEASED, both may go — history does not pin a
 *      record forever.
 *
 * The refusals are asserted to NAME THE TRIP, which is the whole value of
 * them: "cannot retire" sends somebody hunting through trip boards, and
 * "on trip TRP-0001" is one click from the release button.
 */
class RetiringAnAssetInUseTest extends TestCase
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

    private function user(string $role = 'admin'): User
    {
        return User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Ops', 'role' => $role,
            'email' => 'ops-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function vehicle(): Vehicle
    {
        return Vehicle::create([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH'.random_int(10, 99).'AB'.random_int(1000, 9999),
            'vehicle_type' => 'truck', 'status' => Vehicle::STATUS_AVAILABLE,
            'compliance_status' => 'compliant',
        ]);
    }

    /** A person in the standalone register, with a Fleet profile over them. */
    private function driver(): DriverProfile
    {
        $personId = DB::table('stos_drivers')->insertGetId([
            'company_id' => self::COMPANY, 'name' => 'Ramesh Kumar',
            'phone' => '98765'.random_int(10000, 99999), 'designation' => 'Driver',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DriverProfile::create([
            'company_id' => self::COMPANY, 'source' => 'stos', 'source_id' => $personId,
            'status' => DriverProfile::AVAILABLE,
        ]);
    }

    /** A trip holding a vehicle and a driver, exactly as dispatch records it. */
    private function tripHolding(?int $vehicleId, ?int $driverId, string $status = 'assigned'): string
    {
        $number = 'TRP-'.random_int(1000, 9999);

        $orderId = DB::table('transport_orders')->insertGetId([
            'tenant_id' => self::COMPANY, 'customer_id' => 1,
            'order_number' => 'TO-'.Str::random(6),
            'pickup_location' => json_encode(['city' => 'Mumbai']),
            'delivery_location' => json_encode(['city' => 'Pune']),
            'service_type' => 'FTL', 'required_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tripId = DB::table('transport_trips')->insertGetId([
            'tenant_id' => self::COMPANY, 'order_id' => $orderId, 'customer_id' => 1,
            'trip_number' => $number,
            'vehicle_id' => $vehicleId, 'driver_id' => $driverId,
            'status' => 'in_transit',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('trip_assignments')->insert([
            'tenant_id' => self::COMPANY, 'trip_id' => $tripId,
            'vehicle_id' => $vehicleId, 'driver_id' => $driverId,
            'status' => $status, 'assigned_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $number;
    }

    /* ── 1 · the vehicle ─────────────────────────────────────────── */

    public function test_a_vehicle_on_a_live_trip_cannot_be_retired(): void
    {
        $vehicle = $this->vehicle();
        $number  = $this->tripHolding($vehicle->id, null);

        $body = $this->actingAs($this->user())
            ->deleteJson('/api/v1/fleet/vehicles/'.$vehicle->id)
            ->assertStatus(422)->json();

        $this->assertStringContainsString($number, $body['message'], 'the refusal must name the trip');

        // Nothing was destroyed and nothing was orphaned: the assignment still
        // points at a row an ordinary query can find.
        $this->assertNotNull(Vehicle::forCompany(self::COMPANY)->find($vehicle->id));
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()->status);
    }

    /* ── 2 · the driver ──────────────────────────────────────────── */

    public function test_a_driver_on_a_live_trip_cannot_be_stood_down(): void
    {
        $driver = $this->driver();
        $number = $this->tripHolding(null, $driver->id);

        foreach ([DriverProfile::INACTIVE, DriverProfile::SUSPENDED, DriverProfile::ON_LEAVE] as $standDown) {
            $body = $this->actingAs($this->user())
                ->putJson('/api/v1/fleet/drivers/stos/'.$driver->source_id, ['status' => $standDown])
                ->assertStatus(422)->json();

            $this->assertStringContainsString($number, $body['message']);
        }

        $this->assertSame(DriverProfile::AVAILABLE, $driver->fresh()->status);
    }

    public function test_a_driver_on_a_trip_may_still_have_their_paperwork_updated(): void
    {
        // The guard is on standing somebody down, not on the profile. A licence
        // renewal arriving while the driver is on the road is ordinary, and
        // refusing it would mean the expiry that blocks the NEXT dispatch could
        // not be cleared until they got back.
        $driver = $this->driver();
        $this->tripHolding(null, $driver->id);

        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/stos/'.$driver->source_id, [
                'licence_number' => 'MH1220110099887',
                'licence_expiry' => now()->addYear()->toDateString(),
            ])->assertOk();

        $this->assertSame('MH1220110099887', $driver->fresh()->licence_number);
    }

    /* ── 3 · release lets both go ────────────────────────────────── */

    public function test_both_may_be_taken_out_of_use_once_the_assignment_is_released(): void
    {
        $vehicle = $this->vehicle();
        $driver  = $this->driver();
        $this->tripHolding($vehicle->id, $driver->id);

        $this->actingAs($this->user())->deleteJson('/api/v1/fleet/vehicles/'.$vehicle->id)->assertStatus(422);

        // Released assignments are history. They must not pin a record forever.
        DB::table('trip_assignments')->update(['status' => 'released', 'released_at' => now()]);

        $this->actingAs($this->user())->deleteJson('/api/v1/fleet/vehicles/'.$vehicle->id)->assertOk();
        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/stos/'.$driver->source_id, ['status' => DriverProfile::INACTIVE])
            ->assertOk();

        $this->assertSame(DriverProfile::INACTIVE, $driver->fresh()->status);

        // The history survives the masters it describes.
        $assignment = DB::table('trip_assignments')->first();
        $this->assertSame($vehicle->id, (int) $assignment->vehicle_id);
        $this->assertSame($driver->id, (int) $assignment->driver_id);
    }

    /* ── 4 · it degrades ─────────────────────────────────────────── */

    public function test_fleet_retires_normally_with_no_trip_module_installed(): void
    {
        // Fleet runs as its own application with no Ops around it — the driver
        // directory already has a standalone mode for the same reason. With no
        // trips there is nothing to be committed to, and that is a true answer
        // rather than a crash on every retirement.
        $vehicle = $this->vehicle();

        Schema::dropIfExists('trip_assignments');

        $this->actingAs($this->user())->deleteJson('/api/v1/fleet/vehicles/'.$vehicle->id)->assertOk();
    }

    public function test_a_vehicle_with_no_trip_is_retired_as_before(): void
    {
        // The guard must not have made retirement harder for the ordinary case.
        $vehicle = $this->vehicle();
        $this->tripHolding(null, null);

        $this->actingAs($this->user())->deleteJson('/api/v1/fleet/vehicles/'.$vehicle->id)->assertOk();
        $this->assertNull(Vehicle::forCompany(self::COMPANY)->find($vehicle->id));
    }

    /* ── 5 · D-204 — the check failing is not "free" ──────────────── */

    /** A reader whose reads throw as if the query errored, table present. */
    private function bindFailingReader(): void
    {
        $this->app->bind(TripCommitmentReader::class, fn () => new class extends TripCommitmentReader {
            public function forVehicle(int $vehicleId, int $companyId): ?array
            {
                throw new TripCommitmentUnavailable('simulated read failure');
            }

            public function forDriver(int $driverId, int $companyId): ?array
            {
                throw new TripCommitmentUnavailable('simulated read failure');
            }
        });
    }

    public function test_a_read_error_refuses_the_retirement_rather_than_allowing_it(): void
    {
        // Table absent degrades open (standalone Fleet); a read that ERRORS must
        // not. "Could not tell" is not "not on a trip" — refuse and keep the
        // vehicle, so a lookup failure cannot strand a live trip.
        $vehicle = $this->vehicle();
        $this->bindFailingReader();

        $this->actingAs($this->user())
            ->deleteJson('/api/v1/fleet/vehicles/'.$vehicle->id)
            ->assertStatus(422);

        $this->assertNotNull(Vehicle::forCompany(self::COMPANY)->find($vehicle->id));
    }

    public function test_a_read_error_refuses_standing_a_driver_down(): void
    {
        $driver = $this->driver();
        $this->bindFailingReader();

        $this->actingAs($this->user())
            ->putJson('/api/v1/fleet/drivers/stos/'.$driver->source_id, ['status' => DriverProfile::INACTIVE])
            ->assertStatus(422);

        $this->assertSame(DriverProfile::AVAILABLE, $driver->fresh()->status);
    }
}
