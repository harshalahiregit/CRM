<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\TransportPermission;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * Dispatch API — PATCH /trips/{trip}/dispatch and /dispatch/amend.
 *
 * Neither path is in Step 11's API registry and no Step 12 ticket owns dispatch
 * (D-18); the owner authorised the scope on 2026-09-10.
 */
class DispatchApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFleetResources;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PretripService $pretrip;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $n) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $n, 'slug' => Str::slug($n),
                'subdomain' => Str::slug($n), 'status' => 'active',
            ])->save();
        }
        $this->pretrip = app(PretripService::class);
    }

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function readyTrip(int $tenantId = self::TENANT_A, ?User $actor = null): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);
        $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        // Fleet's rows. A legacy vehicle cannot be allocated since the
        // repoint — lockResources() refuses it (D-136) — so a fixture built
        // there would be testing a dispatch nobody can reach.
        $v = $this->fleetVehicle([
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'vehicle_type' => 'truck', 'capacity_tonnes' => 30,
        ], $tenantId, $actor);

        $d = $this->fleetDriver([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_class' => 'HMV', 'licence_expiry' => now()->addYears(2)->toDateString(),
        ], $tenantId);

        app(AllocationService::class)->assign($trip->fresh(), $v->id, $d->id, $tenantId, $actor);
        $trip = $trip->fresh();
        $this->pretrip->generate($trip, $tenantId, $actor);
        foreach ($this->pretrip->checksFor($trip, $tenantId) as $c) {
            $this->pretrip->complete($c, $tenantId, $actor);
        }
        $this->pretrip->passPretrip($trip->fresh(), $tenantId, $actor);

        return $trip->fresh();
    }

    private function url(TransportTrip $t, string $s = 'dispatch'): string
    {
        return '/api/transport/trips/'.$t->id.'/'.$s;
    }

    private function fields(): array
    {
        return [
            'planned_departure_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
            'planned_arrival_at'   => now()->addHours(14)->format('Y-m-d H:i:s'),
            'pickup_contact'       => 'Suresh 98200 11223',
            'dispatch_destination' => 'Bhiwandi Warehouse',
            'dispatch_instructions' => 'Security gate first.',
        ];
    }

    /* ══════════ happy path ══════════ */

    public function test_dispatching_moves_the_trip_and_returns_the_snapshot(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $res = $this->patchJson($this->url($trip), $this->fields())->assertOk();

        $this->assertSame(TripStatus::DISPATCHED, $res->json('data.trip.status'));
        $this->assertTrue($res->json('data.dispatched'));
        $this->assertTrue($res->json('data.frozen'));
        $this->assertSame(1, $res->json('data.version'));
        $this->assertEqualsWithDelta(12.0, $res->json('data.turnaround_hours'), 0.05);
    }

    public function test_the_response_states_it_is_released_but_not_yet_moving(): void
    {
        // WAS: "…and in_transit is blocked by SNG-TRN-013". It was not blocked
        // (D-105). The distinction the response draws is now a real one:
        // released is not moving, and the client is told it may record the
        // departure next.
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $res = $this->patchJson($this->url($trip), $this->fields())->assertOk();

        $this->assertFalse($res->json('data.in_transit'));
        $this->assertNull($res->json('data.departed_at'));
        $this->assertTrue($res->json('data.can_depart'), 'the client is told what it may do next');
    }

    public function test_recording_the_departure_moves_it_onto_the_road(): void
    {
        // STT-006 over the wire, on transport.trip.dispatch — the same
        // permission, because it is the same dispatcher's same job.
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $this->patchJson($this->url($trip), $this->fields())->assertOk();

        $res = $this->patchJson('/api/transport/trips/'.$trip->id.'/depart', [])->assertOk();

        $this->assertSame(TripStatus::IN_TRANSIT, $res->json('data.trip.status'));
        $this->assertTrue($res->json('data.in_transit'));
        $this->assertNotNull($res->json('data.departed_at'));
        $this->assertFalse($res->json('data.can_depart'), 'it cannot depart twice');
    }

    public function test_the_departure_endpoint_refuses_the_fields_q3_excluded(): void
    {
        // Prohibited, not ignored. Silently dropping an odometer reading would
        // let a client believe it had been recorded — D-9's mistake.
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);
        $this->patchJson($this->url($trip), $this->fields())->assertOk();

        $this->patchJson('/api/transport/trips/'.$trip->id.'/depart', ['odometer' => 145320])
            ->assertStatus(422)
            ->assertJsonValidationErrors('odometer');
    }

    public function test_the_response_names_the_deferred_side_effects(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $res = $this->patchJson($this->url($trip), $this->fields())->assertOk();

        $this->assertContains('Update Vehicle = In Operation', $res->json('data.deferred_effects'));
        $this->assertContains('Update Driver = On Trip', $res->json('data.deferred_effects'));
    }

    public function test_amending_over_the_api_versions_the_change(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);
        $this->patchJson($this->url($trip), $this->fields())->assertOk();

        $res = $this->patchJson($this->url($trip, 'dispatch/amend'), [
            'dispatch_destination' => 'Panvel Yard', 'reason' => 'Customer moved the drop',
        ])->assertOk();

        $this->assertSame(2, $res->json('data.version'));
        $this->assertSame('Panvel Yard', $res->json('data.dispatch.dispatch_destination'));
    }

    /* ══════════ validation ══════════ */

    public function test_amending_without_a_reason_is_refused(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);
        $this->patchJson($this->url($trip), $this->fields())->assertOk();

        $res = $this->patchJson($this->url($trip, 'dispatch/amend'), ['dispatch_destination' => 'X'])
            ->assertStatus(422);
        $this->assertStringContainsString('frozen', $res->json('errors.reason.0'));
    }

    public function test_tat_is_refused_rather_than_silently_dropped(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $res = $this->patchJson($this->url($trip), array_merge($this->fields(), ['tat' => 12]))
            ->assertStatus(422);
        $this->assertStringContainsString('not entered', $res->json('errors.tat.0'));
    }

    public function test_arrival_before_departure_is_refused(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $this->patchJson($this->url($trip), [
            'planned_departure_at' => now()->addHours(10)->format('Y-m-d H:i:s'),
            'planned_arrival_at'   => now()->addHours(2)->format('Y-m-d H:i:s'),
        ])->assertStatus(422);
    }

    public function test_dispatching_a_trip_that_has_not_passed_pretrip_is_a_422_with_the_reason(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $order = TransportOrder::create([
            'tenant_id' => self::TENANT_A, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'X',
        ]);
        $trip = TransportTrip::create([
            'tenant_id' => self::TENANT_A, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::ALLOCATED])->save();

        $res = $this->patchJson($this->url($trip), $this->fields())->assertStatus(422);
        $this->assertStringContainsString('pre-trip checks first', $res->json('message'));
        $this->assertFalse($res->json('data.dispatched'));
    }

    /* ══════════ permissions ══════════ */

    public function test_the_dispatch_permission_mirrors_perm_004(): void
    {
        foreach ([TransportPermission::ROLE_OWNER, TransportPermission::ROLE_OPERATIONS,
                  TransportPermission::ROLE_DISPATCHER, TransportPermission::ROLE_ADMIN] as $r) {
            $this->assertNotNull(TransportPermission::scopeFor(TransportPermission::TRIP_DISPATCH, $r), $r);
        }
        foreach ([TransportPermission::ROLE_ACCOUNTS, TransportPermission::ROLE_APPROVER,
                  TransportPermission::ROLE_CUSTOMER, TransportPermission::ROLE_SUPPLIER,
                  TransportPermission::ROLE_DRIVER] as $r) {
            $this->assertNull(TransportPermission::scopeFor(TransportPermission::TRIP_DISPATCH, $r), $r);
        }
    }

    public function test_a_dispatcher_may_dispatch(): void
    {
        $setup = $this->user(); Sanctum::actingAs($setup);
        $trip = $this->readyTrip(actor: $setup);

        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_dispatcher'));
        $this->patchJson($this->url($trip), $this->fields())->assertOk();
    }

    public function test_accounts_may_not_dispatch(): void
    {
        $setup = $this->user(); Sanctum::actingAs($setup);
        $trip = $this->readyTrip(actor: $setup);

        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'accounts'));
        $this->patchJson($this->url($trip), $this->fields())->assertStatus(403);
        $this->patchJson($this->url($trip, 'dispatch/amend'), ['reason' => 'x'])->assertStatus(403);
    }

    public function test_a_client_is_refused_at_the_role_gate(): void
    {
        $setup = $this->user(); Sanctum::actingAs($setup);
        $trip = $this->readyTrip(actor: $setup);

        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));
        $this->patchJson($this->url($trip), $this->fields())->assertStatus(403);
    }

    public function test_the_capability_endpoint_publishes_the_dispatch_key(): void
    {
        Sanctum::actingAs($this->user());
        $res = $this->getJson('/api/transport/permissions')->assertOk();
        $this->assertArrayHasKey(TransportPermission::TRIP_DISPATCH, $res->json('data.grants'));
    }

    /* ══════════ tenancy — 404, never 403 ══════════ */

    public function test_another_tenants_trip_is_a_404(): void
    {
        $b = $this->user(self::TENANT_B); Sanctum::actingAs($b);
        $tripB = $this->readyTrip(self::TENANT_B, $b);

        Sanctum::actingAs($this->user(self::TENANT_A));
        $this->patchJson($this->url($tripB), $this->fields())->assertStatus(404);
        // A VALID payload, so the request reaches the tenant check rather than
        // stopping at validation.
        $this->patchJson($this->url($tripB, 'dispatch/amend'), [
            'dispatch_destination' => 'X', 'reason' => 'trying it on',
        ])->assertStatus(404);

        $this->assertSame(TripStatus::PRETRIP_OK, $tripB->fresh()->status);
    }

    public function test_an_invalid_payload_leaks_no_existence(): void
    {
        // Validation runs before the tenant check, so a malformed request gets a
        // 422 either way. That is fine PROVIDED the answer is identical for a
        // trip that exists elsewhere and one that does not exist at all —
        // otherwise the status code itself becomes an existence oracle.
        $b = $this->user(self::TENANT_B); Sanctum::actingAs($b);
        $tripB = $this->readyTrip(self::TENANT_B, $b);

        Sanctum::actingAs($this->user(self::TENANT_A));

        $other   = $this->patchJson($this->url($tripB, 'dispatch/amend'), ['reason' => 'x']);
        $missing = $this->patchJson('/api/transport/trips/99999999/dispatch/amend', ['reason' => 'x']);

        $this->assertSame($other->status(), $missing->status());
        $this->assertSame($other->json('errors'), $missing->json('errors'));
    }

    public function test_no_endpoint_reaches_in_transit(): void
    {
        $paths = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn (string $u) => str_starts_with($u, 'api/transport/'));

        $this->assertTrue(
            $paths->every(fn (string $u) => ! str_contains($u, 'in-transit') && ! str_contains($u, 'transit')),
            'STT-006 belongs to SNG-TRN-013, which is blocked',
        );
    }

    /* ══════════ GET /dispatch — the panel's pre-flight read ══════════ */

    public function test_the_get_explains_a_block_before_the_user_acts(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        TransportDriver::forTenant(self::TENANT_A)->first()
            ->forceFill(['licence_expiry' => now()->subDay()->toDateString()])->save();

        $res = $this->getJson($this->url($trip))->assertOk();

        // UX §35: a screen must be able to say WHY before the user clicks.
        $this->assertFalse($res->json('data.readiness.ready'));
        $this->assertNotEmpty($res->json('data.readiness.blockers'));
        $this->assertSame(
            PretripCheckKey::DRIVER_DOCUMENTS,
            $res->json('data.readiness.lapsed.0.key'),
        );
        $this->assertNotNull($res->json('data.readiness.message'));
    }

    public function test_the_get_reads_without_dispatching(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $this->getJson($this->url($trip))->assertOk();

        $this->assertSame(TripStatus::PRETRIP_OK, $trip->fresh()->status);
        $this->assertNull($trip->fresh()->dispatched_at);
    }

    public function test_a_ready_trip_reads_as_ready(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $res = $this->getJson($this->url($trip))->assertOk();

        $this->assertTrue($res->json('data.readiness.ready'));
        $this->assertFalse($res->json('data.dispatched'));
        $this->assertSame([], $res->json('data.history'));
    }

    public function test_the_get_returns_the_version_history_after_release(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        $this->patchJson($this->url($trip), $this->fields())->assertOk();
        $this->patchJson($this->url($trip, 'dispatch/amend'), [
            'dispatch_destination' => 'Nhava Sheva', 'reason' => 'Customer changed the drop',
        ])->assertOk();

        $res = $this->getJson($this->url($trip))->assertOk();

        $this->assertSame([1, 2], array_column($res->json('data.history'), 'version'));
        $this->assertSame('Customer changed the drop', $res->json('data.history.1.reason'));
        // A dispatched trip has nothing left to re-validate.
        $this->assertNull($res->json('data.readiness'));
    }

    public function test_reading_dispatch_state_is_not_releasing_it(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        // Accounts holds transport.trip.view but not transport.trip.dispatch.
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'accounts'));

        $this->getJson($this->url($trip))->assertOk();
        $this->patchJson($this->url($trip), $this->fields())->assertForbidden();
    }

    public function test_the_get_on_another_tenants_trip_is_a_404(): void
    {
        $a = $this->user(); Sanctum::actingAs($a);
        $trip = $this->readyTrip(actor: $a);

        Sanctum::actingAs($this->user(self::TENANT_B));

        $this->getJson($this->url($trip))->assertNotFound();
    }
}
