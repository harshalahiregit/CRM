<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\TripAssigned;
use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SNG-TRN-009 step 7 — the allocation API.
 *
 *   POST   /transport/trips/{trip}/assign      API-004
 *   GET    /transport/trips/{trip}/candidates  no registry row (D-12)
 *   DELETE /transport/trips/{trip}/assign      no registry row (D-12)
 *
 * All three gated on PERM-004. The property these tests pin hardest is that a
 * refusal explains itself: QA-003 requires "blocked with an actionable message",
 * so a 422 must carry the eligibility verdict, not just a string.
 */
class TransportAllocationApiTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TransportVehicleService $vehicleSvc;
    private TransportDriverService $driverSvc;
    private TransportDocumentService $docs;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha Transport', self::TENANT_B => 'Bravo Logistics'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->vehicleSvc = app(TransportVehicleService::class);
        $this->driverSvc  = app(TransportDriverService::class);
        $this->docs       = app(TransportDocumentService::class);
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function asAdmin(int $tenantId = self::TENANT_A): User
    {
        $u = $this->user($tenantId);
        Sanctum::actingAs($u);

        return $u;
    }

    private function approvedTrip(int $tenantId = self::TENANT_A, ?float $capacity = null): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
            'required_capacity_tonnes' => $capacity,
        ]);
        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        return $trip->fresh();
    }

    private function vehicle(int $tenantId = self::TENANT_A, ?float $capacity = 30, ?User $actor = null): TransportVehicle
    {
        $v = $this->vehicleSvc->create([
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => $capacity,
        ], $tenantId, $actor);

        return $this->vehicleSvc->transitionTo($v, VehicleStatus::AVAILABLE, $tenantId, $actor);
    }

    private function driver(int $tenantId = self::TENANT_A, ?User $actor = null): TransportDriver
    {
        return $this->driverSvc->create([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.random_int(100000, 999999),
            'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ], $tenantId, $actor);
    }

    /* ══════════ API-004 — success paths ══════════ */

    public function test_assigning_both_allocates_the_trip(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", [
            'vehicle_id' => $v->id, 'driver_id' => $d->id, 'reason' => 'nearest available',
        ])->assertOk()->json();

        $this->assertSame('success', $body['status']);
        $this->assertTrue($body['data']['allocated']);
        $this->assertSame(TripStatus::ALLOCATED, $body['data']['trip']['status']);
        $this->assertSame($v->id, $body['data']['assignment']['vehicle_id']);
        $this->assertSame($d->id, $body['data']['assignment']['driver_id']);
        // The verdict travels with the answer, so the client needs no second call.
        $this->assertCount(4, $body['data']['eligibility']['vehicle']['checks']);
        $this->assertCount(5, $body['data']['eligibility']['driver']['checks']);
        $this->assertNotEmpty($body['data']['audit']);
    }

    public function test_a_vehicle_only_assignment_succeeds_without_allocating(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip();

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $this->vehicle()->id])
            ->assertOk()->json('data');

        $this->assertFalse($body['allocated']);
        $this->assertSame(TripStatus::APPROVED, $body['trip']['status']);
        $this->assertNull($body['assignment']['driver_id']);
    }

    public function test_a_driver_only_assignment_succeeds_without_allocating(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip();

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['driver_id' => $this->driver()->id])
            ->assertOk()->json('data');

        $this->assertFalse($body['allocated']);
        $this->assertNull($body['assignment']['vehicle_id']);
    }

    public function test_sequential_assignment_completes_the_allocation(): void
    {
        Event::fake([TripAssigned::class]);
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id])->assertOk();
        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['driver_id' => $d->id])->assertOk()->json('data');

        $this->assertTrue($body['allocated']);
        $this->assertSame(1, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
        Event::assertDispatchedTimes(TripAssigned::class, 1);
    }

    /** No Idempotency-Key header; the row lock and unique indexes carry it. */
    public function test_a_double_submit_is_harmless(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();
        $payload = ['vehicle_id' => $v->id, 'driver_id' => $d->id];

        $first = $this->postJson("/api/transport/trips/{$trip->id}/assign", $payload)->assertOk()->json('data');
        $again = $this->postJson("/api/transport/trips/{$trip->id}/assign", $payload)->assertOk()->json('data');

        $this->assertSame($first['assignment']['id'], $again['assignment']['id'], 'the same row, not a second');
        $this->assertSame(1, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
        // The repeat writes nothing at all — no second audit row, no second event.
        $this->assertSame(
            \App\Models\Transport\TransportAuditLog::where('action', 'transport.allocation.performed')->count(),
            1
        );
    }

    /** A DIFFERENT vehicle on an allocated trip is still refused, not folded in. */
    public function test_a_different_resource_on_an_allocated_trip_is_still_refused(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v1 = $this->vehicle(); $v2 = $this->vehicle(); $d = $this->driver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v1->id, 'driver_id' => $d->id])->assertOk();

        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v2->id])->assertStatus(422);
        $this->assertSame($v1->id, (int) $trip->fresh()->vehicle_id);
    }

    /* ══════════ API-004 — refusals carry the verdict ══════════ */

    public function test_an_expired_document_returns_422_with_the_reason(): void
    {
        $admin = $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle();
        $this->docs->file($v, TransportDocumentType::INSURANCE,
            ['valid_until' => now()->subDay()->toDateString()], self::TENANT_A, $admin);

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id])
            ->assertStatus(422)->json();

        $this->assertSame('error', $body['status']);
        // QA-003 — actionable, not just "failed".
        $this->assertStringContainsString('Insurance', $body['message']);
        $this->assertStringContainsString('Renew', $body['message']);
        // Same shape as success, so one component renders either.
        $this->assertArrayHasKey('eligibility', $body);
        $row = collect($body['eligibility']['vehicles'])->firstWhere('subject.id', $v->id);
        $this->assertFalse($row['eligible']);
        $this->assertNotEmpty($row['blockers']);
        // Nothing was written.
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(0, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    public function test_an_expired_licence_returns_422_with_the_reason(): void
    {
        $admin = $this->asAdmin();
        $trip = $this->approvedTrip();
        $d = $this->driverSvc->create([
            'name' => 'Lapsed', 'licence_number' => 'MH0199',
            'licence_valid_until' => now()->subDay()->toDateString(),
        ], self::TENANT_A, $admin);

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['driver_id' => $d->id])
            ->assertStatus(422)->json();

        $this->assertStringContainsString('expired', $body['message']);
        $this->assertStringContainsString('assign another eligible driver', $body['message']);
    }

    public function test_a_non_approved_trip_is_refused(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $this->vehicle()->id])
            ->assertStatus(422);
    }

    public function test_a_request_with_neither_resource_fails_validation(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip();

        $this->postJson("/api/transport/trips/{$trip->id}/assign", [])
            ->assertStatus(422)->assertJsonValidationErrors(['vehicle_id', 'driver_id']);
    }

    /** PLN-007 is P1 — a client must not be able to slip an override in. */
    public function test_an_override_field_is_ignored(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $this->postJson("/api/transport/trips/{$trip->id}/assign", [
            'vehicle_id' => $v->id, 'driver_id' => $d->id,
            'allocation_override' => true, 'override_reason' => 'because I said so',
        ])->assertOk();

        $assignment = TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->first();
        $this->assertFalse($assignment->allocation_override);
        $this->assertNull($assignment->override_reason);
    }

    /* ══════════ Candidates — PLN-002 / PLN-003 ══════════ */

    public function test_candidates_returns_only_eligible_resources(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(capacity: 20);
        $ok = $this->vehicle(capacity: 30);
        $tooSmall = $this->vehicle(capacity: 5);
        $d = $this->driver();

        $body = $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertOk()->json('data');

        $ids = collect($body['vehicles'])->pluck('subject.id');
        $this->assertTrue($ids->contains($ok->id));
        $this->assertFalse($ids->contains($tooSmall->id));
        $this->assertTrue(collect($body['drivers'])->pluck('subject.id')->contains($d->id));
        $this->assertSame($trip->trip_number, $body['trip']['trip_number']);
    }

    /** UX §35 — a dispatcher facing an empty list needs to know why. */
    public function test_candidates_can_include_the_ineligible_with_their_blockers(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(capacity: 20);
        $tooSmall = $this->vehicle(capacity: 5);

        $body = $this->getJson("/api/transport/trips/{$trip->id}/candidates?include_ineligible=1")
            ->assertOk()->json('data');

        $row = collect($body['vehicles'])->firstWhere('subject.id', $tooSmall->id);
        $this->assertNotNull($row);
        $this->assertFalse($row['eligible']);
        $this->assertStringContainsString('below', $row['blockers'][0]);
    }

    public function test_candidates_reports_the_trips_current_assignment(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id])->assertOk();

        $body = $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertOk()->json('data');

        $this->assertNotNull($body['assignment']);
        $this->assertSame($v->id, $body['assignment']['vehicle_id']);
    }

    /* ══════════ Release ══════════ */

    public function test_release_frees_everything_and_reverts_the_trip(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id, 'driver_id' => $d->id])->assertOk();

        $body = $this->deleteJson("/api/transport/trips/{$trip->id}/assign", ['reason' => 'wrong vehicle'])
            ->assertOk()->json('data');

        $this->assertSame(TripStatus::APPROVED, $body['trip']['status']);
        $this->assertSame('released', $body['assignment']['status']);
        $this->assertSame(VehicleStatus::AVAILABLE, $v->fresh()->status);
        $this->assertSame(DriverAvailability::AVAILABLE, $d->fresh()->availability);
    }

    public function test_releasing_a_trip_with_no_assignment_is_refused(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip();

        $this->deleteJson("/api/transport/trips/{$trip->id}/assign")->assertStatus(422);
    }

    public function test_release_then_reassign_through_the_api(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v1 = $this->vehicle(); $v2 = $this->vehicle(); $d = $this->driver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v1->id, 'driver_id' => $d->id])->assertOk();
        $this->deleteJson("/api/transport/trips/{$trip->id}/assign")->assertOk();

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v2->id, 'driver_id' => $d->id])
            ->assertOk()->json('data');

        $this->assertTrue($body['allocated']);
        $this->assertSame($v2->id, $body['assignment']['vehicle_id']);
        $this->assertSame(2, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count(), 'history survives');
    }

    /* ══════════ What the trip detail screen reads (step 8) ══════════ */

    /**
     * The screen renders the crew from the trip's own payload. If this stopped
     * returning the assignment, the panel would silently show "Not assigned"
     * for an allocated trip — a wrong answer rather than a visible failure.
     */
    public function test_the_trip_detail_payload_carries_its_active_assignment(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id, 'driver_id' => $d->id])->assertOk();

        $body = $this->getJson("/api/transport/trips/{$trip->id}")->assertOk()->json('data');

        $this->assertNotNull($body['assignment']);
        // Nested so the panel shows a registration, not a bare id.
        $this->assertSame($v->registration_number, $body['assignment']['vehicle']['registration_number']);
        $this->assertSame($d->name, $body['assignment']['driver']['name']);
        $this->assertSame(TripStatus::ALLOCATED, $body['trip']['status']);
    }

    public function test_a_trip_with_no_assignment_reports_null_rather_than_failing(): void
    {
        $this->asAdmin();
        $trip = $this->approvedTrip();

        $this->getJson("/api/transport/trips/{$trip->id}")->assertOk()->assertJsonPath('data.assignment', null);
    }

    /**
     * PERM-001 grants Accounts and Approver full trip view. They must be able to
     * SEE who is crewing a trip even though PERM-004 refuses them the ability to
     * change it — which is why the assignment rides on the trip payload rather
     * than behind the assign permission.
     */
    public function test_accounts_can_see_the_crew_without_being_able_to_change_it(): void
    {
        $admin = $this->asAdmin();
        $trip = $this->approvedTrip();
        $v = $this->vehicle(self::TENANT_A, 30, $admin);
        $d = $this->driver(self::TENANT_A, $admin);
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id, 'driver_id' => $d->id])->assertOk();

        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'accounts'));

        $body = $this->getJson("/api/transport/trips/{$trip->id}")->assertOk()->json('data');
        $this->assertSame($v->registration_number, $body['assignment']['vehicle']['registration_number']);

        // But the panel's actions stay hidden, because the grant is absent.
        $grants = $this->getJson('/api/transport/permissions')->assertOk()->json('data.grants');
        $this->assertArrayNotHasKey('transport.trip.assign', $grants);
        $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertForbidden();
    }

    public function test_a_dispatcher_is_told_it_may_assign(): void
    {
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_dispatcher'));

        $grants = $this->getJson('/api/transport/permissions')->assertOk()->json('data.grants');

        $this->assertArrayHasKey('transport.trip.assign', $grants);
    }

    /* ══════════ PERM-004 across the same roles as step 3 ══════════ */

    public function test_roles_that_may_assign(): void
    {
        $trip = $this->approvedTrip();

        foreach ([['admin', null], ['staff', null], ['staff', 'transport_owner'],
                  ['staff', 'transport_operations'], ['staff', 'transport_dispatcher']] as [$role, $internal]) {
            Sanctum::actingAs($this->user(self::TENANT_A, $role, $internal));
            $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertOk();
        }
    }

    public function test_roles_that_may_not_assign(): void
    {
        $trip = $this->approvedTrip();

        // PERM-004 gives Accounts N. Client is refused at the module gate.
        foreach ([['staff', 'accounts'], ['client', null], ['third_party_vendor', null]] as [$role, $internal]) {
            Sanctum::actingAs($this->user(self::TENANT_A, $role, $internal));
            $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => 1])->assertForbidden();
            $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertForbidden();
            $this->deleteJson("/api/transport/trips/{$trip->id}/assign")->assertForbidden();
        }
    }

    public function test_accounts_may_view_the_trip_but_not_crew_it(): void
    {
        $trip = $this->approvedTrip();
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'accounts'));

        $this->getJson("/api/transport/trips/{$trip->id}")->assertOk();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => 1])->assertForbidden();
    }

    public function test_unauthenticated_requests_are_refused(): void
    {
        $trip = $this->approvedTrip();

        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => 1])->assertUnauthorized();
        $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertUnauthorized();
    }

    /* ══════════ Tenancy — 404, never 403 ══════════ */

    public function test_another_tenants_trip_reads_as_not_found(): void
    {
        $tripA = $this->approvedTrip(self::TENANT_A);
        $this->asAdmin(self::TENANT_B);

        $this->postJson("/api/transport/trips/{$tripA->id}/assign", ['vehicle_id' => 1])->assertNotFound();
        $this->getJson("/api/transport/trips/{$tripA->id}/candidates")->assertNotFound();
        $this->deleteJson("/api/transport/trips/{$tripA->id}/assign")->assertNotFound();
    }

    public function test_another_tenants_vehicle_or_driver_reads_as_not_found(): void
    {
        $adminB = $this->user(self::TENANT_B);
        $vB = $this->vehicle(self::TENANT_B, 30, $adminB);
        $dB = $this->driver(self::TENANT_B, $adminB);

        $this->asAdmin(self::TENANT_A);
        $tripA = $this->approvedTrip(self::TENANT_A);

        $this->postJson("/api/transport/trips/{$tripA->id}/assign", ['vehicle_id' => $vB->id])->assertNotFound();
        $this->postJson("/api/transport/trips/{$tripA->id}/assign", ['driver_id' => $dB->id])->assertNotFound();
    }

    public function test_candidates_never_leak_another_tenants_fleet(): void
    {
        $adminB = $this->user(self::TENANT_B);
        $vB = $this->vehicle(self::TENANT_B, 30, $adminB);

        $this->asAdmin(self::TENANT_A);
        $vA = $this->vehicle(self::TENANT_A);
        $trip = $this->approvedTrip(self::TENANT_A);

        $ids = collect($this->getJson("/api/transport/trips/{$trip->id}/candidates")->json('data.vehicles'))
            ->pluck('subject.id');

        $this->assertTrue($ids->contains($vA->id));
        $this->assertFalse($ids->contains($vB->id));
    }
}
