<?php

namespace Tests\Feature\Stos;

use App\Domains\Fleet\Models\Vehicle;
use App\Domains\Fleet\Models\VehicleLiveStatus;
use App\Domains\Fleet\Services\MaintenanceService;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * STOS-FLEET — the one hand-driven edge of the asset state machine (T-56).
 *
 * Absorbed from Dev 1's retiring `/api/transport/vehicles/{id}/status`, because
 * Fleet is the sole authority for this machine. Building it here is what makes
 * that endpoint safe to delete.
 *
 * Almost none of the machine is a human decision: job cards move a truck in and
 * out of the workshop, dispatch moves it on and off a trip, the compliance
 * sweep blocks and clears it. What is left for a person is small and honest —
 * park it, bring it back, retire it.
 *
 * Every refusal below is asserted to NAME WHO CAN CLEAR IT. "You cannot do
 * that" sends somebody hunting, and the person pressing this button is usually
 * trying to fix something.
 */
class VehicleStatusTransitionTest extends TestCase
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

    private function vehicle(string $status = Vehicle::STATUS_AVAILABLE): Vehicle
    {
        $v = Vehicle::create([
            'company_id' => self::COMPANY,
            'registration_number' => 'MH'.random_int(10, 99).'AB'.random_int(1000, 9999),
            'vehicle_type' => 'reefer', 'status' => $status, 'compliance_status' => 'compliant',
        ]);

        VehicleLiveStatus::create([
            'vehicle_id' => $v->id, 'company_id' => self::COMPANY,
            'latitude' => '19.07609500', 'longitude' => '72.87765800',
            'speed' => '0.00', 'ignition' => false, 'last_ping_at' => now()->subMinute(),
        ]);

        return $v;
    }

    private function set(Vehicle $vehicle, string $status, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user())
            ->patchJson("/api/v1/fleet/vehicles/{$vehicle->id}/status", ['status' => $status]);
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

    /* ── What a person may actually do ──────────────────────────── */

    public function test_a_truck_can_be_parked_and_brought_back(): void
    {
        $vehicle = $this->vehicle();

        $this->set($vehicle, 'IDLE')->assertOk();
        $this->assertSame('IDLE', $vehicle->fresh()->status);

        $this->set($vehicle, 'AVAILABLE')->assertOk();
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
    }

    public function test_setting_the_state_it_already_holds_is_not_an_error(): void
    {
        $vehicle = $this->vehicle();

        // A retried request, or two people pressing the same button.
        $this->set($vehicle, 'AVAILABLE')->assertOk();
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
    }

    public function test_the_lowercase_spelling_is_accepted_and_normalised(): void
    {
        $vehicle = $this->vehicle();

        // A caller built against the old vocabulary should not silently write
        // a status nothing else recognises.
        $this->set($vehicle, 'idle')->assertOk();
        $this->assertSame('IDLE', $vehicle->fresh()->status);
    }

    public function test_a_change_is_audited_with_both_ends_of_it(): void
    {
        $vehicle = $this->vehicle();
        $this->set($vehicle, 'IDLE')->assertOk();

        // The event the observer fires is what Developers 1 and 3 listen to.
        $this->assertSame('IDLE', $vehicle->fresh()->status);
    }

    /* ── Targets a person may NOT set, each explaining itself ───── */

    public function test_the_workshop_states_cannot_be_typed(): void
    {
        $vehicle = $this->vehicle();

        $response = $this->set($vehicle, 'UNDER_MAINTENANCE')->assertStatus(422);

        // Pointing, not just refusing: a job card records WHY the truck is off
        // the road, which a typed status does not.
        $this->assertStringContainsString('job card', $response->json('message'));
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
    }

    public function test_a_breakdown_cannot_be_typed(): void
    {
        $response = $this->set($this->vehicle(), 'BREAKDOWN')->assertStatus(422);

        $this->assertStringContainsString('job card', $response->json('message'));
    }

    public function test_a_trip_state_cannot_be_typed(): void
    {
        $response = $this->set($this->vehicle(), 'ALLOCATED')->assertStatus(422);

        // Otherwise Operations is told a truck is committed to a trip that
        // does not exist.
        $this->assertStringContainsString('Dispatch', $response->json('message'));
    }

    public function test_compliance_blocked_cannot_be_typed(): void
    {
        $response = $this->set($this->vehicle(), 'COMPLIANCE_BLOCKED')->assertStatus(422);

        // It is derived from the expiry dates; a hand-set value would be
        // overwritten by the next sweep.
        $this->assertStringContainsString('compliance hold', $response->json('message'));
    }

    public function test_a_status_outside_the_vocabulary_is_refused_by_validation(): void
    {
        $this->set($this->vehicle(), 'PROBABLY_FINE')
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    /* ── States a person may not LEAVE, each naming its owner ───── */

    public function test_a_truck_in_the_workshop_cannot_be_freed_by_hand(): void
    {
        $vehicle = $this->vehicle();
        app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'complaint' => 'Brake judder',
        ], $this->user()->id);

        $response = $this->set($vehicle, 'AVAILABLE')->assertStatus(422);

        // This is the one that matters: closing the card checks QC and the
        // vehicle's papers. Bypassing it is how a truck leaves the workshop
        // fixed and still uninsured.
        $this->assertStringContainsString('job card', $response->json('message'));
        $this->assertSame('UNDER_MAINTENANCE', $vehicle->fresh()->status);
    }

    public function test_a_broken_down_truck_cannot_be_freed_by_hand(): void
    {
        $vehicle = $this->vehicle();
        app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'trip_id' => $this->trip(), 'complaint' => 'Clutch failed',
        ], $this->user()->id);

        $this->set($vehicle, 'AVAILABLE')->assertStatus(422);
        $this->assertSame('BREAKDOWN', $vehicle->fresh()->status);
    }

    public function test_a_truck_on_a_trip_cannot_be_freed_by_hand(): void
    {
        $vehicle = $this->vehicle(Vehicle::STATUS_ALLOCATED);

        $response = $this->set($vehicle, 'AVAILABLE')->assertStatus(422);

        $this->assertStringContainsString('Operations', $response->json('message'));
        $this->assertSame('ALLOCATED', $vehicle->fresh()->status);
    }

    public function test_a_truck_in_transit_cannot_be_freed_by_hand(): void
    {
        $vehicle = $this->vehicle(Vehicle::STATUS_IN_TRANSIT);

        $response = $this->set($vehicle, 'IDLE')->assertStatus(422);

        $this->assertStringContainsString('trip closes', $response->json('message'));
    }

    public function test_a_compliance_blocked_truck_is_cleared_by_the_document_not_the_button(): void
    {
        $vehicle = $this->vehicle(Vehicle::STATUS_COMPLIANCE_BLOCKED);

        $response = $this->set($vehicle, 'AVAILABLE')->assertStatus(422);

        $this->assertStringContainsString('Renewing', $response->json('message'));
        $this->assertSame('COMPLIANCE_BLOCKED', $vehicle->fresh()->status);
    }

    /* ── Retiring routes through the one implementation ─────────── */

    public function test_retiring_goes_through_the_guarded_retire_path(): void
    {
        $vehicle = $this->vehicle();

        $this->set($vehicle, 'RETIRED')->assertOk();

        $this->assertSame('RETIRED', $vehicle->fresh()->status);
        // The retire path also soft-deletes and frees the genset; this must not
        // be a second, weaker implementation of the same act.
        $this->assertSoftDeleted('vehicles', ['id' => $vehicle->id]);
    }

    public function test_retiring_still_refuses_while_a_job_card_is_open(): void
    {
        $vehicle = $this->vehicle();
        app(MaintenanceService::class)->open(self::COMPANY, [
            'vehicle_id' => $vehicle->id, 'complaint' => 'Brake judder',
        ], $this->user()->id);

        // Reached through the new endpoint, but the existing guard still holds.
        $this->set($vehicle, 'RETIRED')->assertStatus(422);
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'deleted_at' => null]);
    }

    /* ── Tenancy ────────────────────────────────────────────────── */

    public function test_another_companys_vehicle_cannot_be_moved(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Co2', 'slug' => 'co2', 'subdomain' => 'co2', 'status' => 'active',
        ])->save();

        $theirs = Vehicle::create([
            'company_id' => 2, 'registration_number' => 'MH99ZZ0001',
            'vehicle_type' => 'truck', 'status' => Vehicle::STATUS_AVAILABLE,
        ]);

        $this->set($theirs, 'IDLE')->assertStatus(404);
        $this->assertSame('AVAILABLE', $theirs->fresh()->status);
    }

    public function test_an_external_caller_is_refused(): void
    {
        $vehicle = $this->vehicle();
        $outsider = User::create([
            'tenant_id' => self::COMPANY, 'name' => 'Client', 'role' => 'client',
            'email' => 'client-'.Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);

        $this->set($vehicle, 'IDLE', $outsider)->assertForbidden();
        $this->assertSame('AVAILABLE', $vehicle->fresh()->status);
    }
}
