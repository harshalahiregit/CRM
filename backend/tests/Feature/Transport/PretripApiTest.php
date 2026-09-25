<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\Transport\TripPretripCheck;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use App\Support\Transport\TransportPermission;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * SNG-TRN-010 step 7 — the API surface.
 *
 *   GET   /api/transport/trips/{trip}/prechecks
 *   POST  /api/transport/trips/{trip}/prechecks            Step 5's path
 *   PATCH /api/transport/trips/{trip}/prechecks/{check}
 *   PATCH /api/transport/trips/{trip}/pass-pretrip
 *
 * Gating is defect D-21's derived matrix; cross-tenant reads must answer 404,
 * never 403, so the status code itself never confirms a record exists.
 */
class PretripApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFleetResources;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PretripService $pretrip;
    private AllocationService $alloc;
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

        $this->pretrip    = app(PretripService::class);
        $this->alloc      = app(AllocationService::class);
        $this->vehicleSvc = app(TransportVehicleService::class);
        $this->driverSvc  = app(TransportDriverService::class);
        $this->docs       = app(TransportDocumentService::class);
    }

    /* ── fixtures ── */

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function actingAdmin(int $tenantId = self::TENANT_A): User
    {
        $u = $this->user($tenantId);
        Sanctum::actingAs($u);

        return $u;
    }

    private function crewedTrip(int $tenantId = self::TENANT_A, ?User $actor = null): TransportTrip
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

        $v = $this->fleetVehicle([
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => 30,
        ], $tenantId, $actor);
        $v = $this->moveFleetVehicle($v, Vehicle::STATUS_AVAILABLE);

        $d = $this->fleetDriver([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_expiry' => now()->addYears(2)->toDateString(),
        ], $tenantId, $actor);

        $this->alloc->assign($trip->fresh(), $v->id, $d->id, $tenantId, $actor);

        return $trip->fresh();
    }

    private function url(TransportTrip $trip, string $suffix = ''): string
    {
        return '/api/transport/trips/'.$trip->id.'/'.($suffix ?: 'prechecks');
    }

    /* ══════════ GET readiness ══════════ */

    public function test_readiness_of_a_trip_with_no_checklist_reads_not_started(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $res = $this->getJson($this->url($trip))->assertOk();

        $this->assertSame(PretripReadiness::NOT_STARTED, $res->json('data.status'));
        $this->assertSame(0, $res->json('data.total'));
        $this->assertFalse($res->json('data.ready'));
        $this->assertTrue($res->json('data.generatable'));
    }

    public function test_the_get_never_generates(): void
    {
        // A page refresh must not be an act with side effects, and a viewer with
        // only pretrip.view must not be able to write.
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $this->getJson($this->url($trip))->assertOk();
        $this->getJson($this->url($trip))->assertOk();

        $this->assertSame(0, TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    public function test_readiness_carries_the_blocking_message_without_a_second_call(): void
    {
        // UX §35 — never merely show Blocked. The screen gets the sentence the
        // gate would refuse with, from the plain read.
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();

        $res = $this->getJson($this->url($trip))->assertOk();

        $this->assertSame(PretripReadiness::IN_PROGRESS, $res->json('data.status'));
        $this->assertStringContainsString('not complete', $res->json('data.blocking_message'));
        $this->assertStringContainsString('Still to confirm', $res->json('data.blocking_message'));
    }

    public function test_a_ready_checklist_has_no_blocking_message(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->patchJson($this->url($trip).'/'.$c->id)->assertOk();
        }

        $res = $this->getJson($this->url($trip))->assertOk();

        $this->assertSame(PretripReadiness::READY, $res->json('data.status'));
        $this->assertNull($res->json('data.blocking_message'));
        $this->assertTrue($res->json('data.ready'));
    }

    /* ══════════ POST — generate ══════════ */

    public function test_posting_generates_the_checklist(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $res = $this->postJson($this->url($trip))->assertOk();

        $this->assertSame(5, $res->json('data.total'));
        $this->assertCount(5, $res->json('data.checks'));
        $this->assertSame(PretripReadiness::IN_PROGRESS, $res->json('data.status'));
    }

    public function test_the_response_shape_carries_what_a_screen_needs(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $res = $this->postJson($this->url($trip))->assertOk();

        $res->assertJsonStructure(['data' => [
            'trip_id', 'trip_number', 'trip_status', 'status', 'status_label', 'ready',
            'generatable', 'total', 'completed', 'blockers', 'warnings', 'blocking_message',
            'checks' => [['id', 'key', 'label', 'category', 'category_label', 'critical',
                'result', 'result_label', 'detail', 'remarks', 'blocks', 'warning',
                'completed', 'completed_at', 'completed_by', 'evaluated_at']],
        ]]);
    }

    public function test_posting_twice_reconciles_rather_than_duplicating(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $this->postJson($this->url($trip))->assertOk();
        $res = $this->postJson($this->url($trip))->assertOk();

        $this->assertSame(5, $res->json('data.total'));
        $this->assertSame(5, TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    public function test_posting_accepts_step_5s_check_items(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        $checks = $this->pretrip->checksFor($trip, self::TENANT_A);

        $res = $this->postJson($this->url($trip), ['check_items' => [
            ['id' => $checks[0]->id, 'remarks' => 'seen'],
            ['id' => $checks[1]->id],
        ]])->assertOk();

        $this->assertSame(2, $res->json('data.completed'));
    }

    public function test_evidence_is_refused_rather_than_silently_dropped(): void
    {
        // D-22. Believing a photo was filed against a safety check when nothing
        // was stored is worse than being told it cannot be.
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $res = $this->postJson($this->url($trip), [
            'evidence' => ['photo' => 'data:image/png;base64,AAAA'],
        ])->assertStatus(422);

        $this->assertStringContainsString('no document upload', $res->json('errors.evidence.0'));
    }

    public function test_a_caller_cannot_supply_its_own_result(): void
    {
        // That would be an override, and override is P1 everywhere it appears.
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        $check = $this->pretrip->checksFor($trip, self::TENANT_A)[0];

        $this->postJson($this->url($trip), ['check_items' => [
            ['id' => $check->id, 'result' => PretripResult::PASS],
        ]])->assertStatus(422);

        $this->patchJson($this->url($trip).'/'.$check->id, ['result' => PretripResult::PASS])
            ->assertStatus(422);
    }

    public function test_generating_for_a_draft_trip_is_refused_with_the_readiness_body(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        $res = $this->postJson($this->url($trip))->assertStatus(422);

        $this->assertStringContainsString('approved or allocated', $res->json('message'));
        $this->assertSame(PretripReadiness::NOT_STARTED, $res->json('data.status'));
        $this->assertFalse($res->json('data.generatable'));
    }

    /* ══════════ PATCH — confirm one check ══════════ */

    public function test_confirming_a_check_stamps_time_and_user(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        $check = $this->pretrip->checksFor($trip, self::TENANT_A)[0];

        $res = $this->patchJson($this->url($trip).'/'.$check->id, ['remarks' => 'Walked round'])->assertOk();

        $this->assertSame($actor->id, $res->json('data.check.completed_by'));
        $this->assertNotNull($res->json('data.check.completed_at'));
        $this->assertSame('Walked round', $res->json('data.check.remarks'));
        $this->assertSame(1, $res->json('data.completed'));
    }

    public function test_confirming_returns_the_audit_trail_for_the_check(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        $check = $this->pretrip->checksFor($trip, self::TENANT_A)[0];

        $res = $this->patchJson($this->url($trip).'/'.$check->id)->assertOk();

        $this->assertNotEmpty($res->json('data.audit'));
    }

    public function test_a_check_from_another_trip_is_a_404(): void
    {
        $actor = $this->actingAdmin();
        $tripA = $this->crewedTrip(actor: $actor);
        $tripB = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($tripB))->assertOk();
        $foreign = $this->pretrip->checksFor($tripB, self::TENANT_A)[0];

        $this->postJson($this->url($tripA))->assertOk();
        $this->patchJson($this->url($tripA).'/'.$foreign->id)->assertStatus(404);
    }

    public function test_an_unknown_check_is_a_404(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();

        $this->patchJson($this->url($trip).'/999999')->assertStatus(404);
    }

    /* ══════════ PATCH — the gate, end to end ══════════ */

    public function test_the_gate_is_reachable_end_to_end_through_the_api(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $this->postJson($this->url($trip))->assertOk();
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->patchJson($this->url($trip).'/'.$c->id)->assertOk();
        }

        $res = $this->patchJson($this->url($trip, 'pass-pretrip'))->assertOk();

        $this->assertSame(TripStatus::PRETRIP_OK, $res->json('data.trip.status'));
        $this->assertTrue($res->json('data.pretrip_ok'));
        $this->assertSame(TripStatus::PRETRIP_OK, $trip->fresh()->status);
    }

    public function test_the_gate_response_states_that_it_did_not_dispatch(): void
    {
        // D-18 stays deferred, and a client must not read "pre-trip passed" as
        // "dispatched".
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->patchJson($this->url($trip).'/'.$c->id)->assertOk();
        }

        $res = $this->patchJson($this->url($trip, 'pass-pretrip'))->assertOk();

        $this->assertFalse($res->json('data.dispatched'));
        $this->assertNotSame(TripStatus::DISPATCHED, $res->json('data.trip.status'));
    }

    public function test_the_pretrip_endpoints_never_dispatch(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        // Updated 2026-09-10: a dispatch endpoint now exists, separately
        // authorised (DispatchScope). What this test guards is unchanged — the
        // PRE-TRIP endpoints must not dispatch. The trip here is `allocated`
        // and has not passed its checks, so nothing may move it at all.
        foreach (['prechecks', 'prechecks/dispatch', 'pass-dispatch'] as $path) {
            $this->postJson('/api/transport/trips/'.$trip->id.'/'.$path);
            $this->patchJson('/api/transport/trips/'.$trip->id.'/'.$path);
        }

        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);

        // And the real dispatch endpoint refuses a trip that has not passed
        // pre-trip, so the gate cannot be walked around.
        $this->patchJson('/api/transport/trips/'.$trip->id.'/dispatch')->assertStatus(422);
        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
    }

    public function test_the_gate_refuses_a_blocked_checklist_with_the_reason(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->patchJson($this->url($trip).'/'.$c->id)->assertOk();
        }

        $driver = DriverProfile::forCompany(self::TENANT_A)->firstOrFail();   // the crew is a Fleet driver
        $driver->forceFill(['licence_expiry' => now()->subDay()])->save();
        $this->postJson($this->url($trip))->assertOk();

        $res = $this->patchJson($this->url($trip, 'pass-pretrip'))->assertStatus(422);

        $this->assertStringContainsString('Dispatch blocked', $res->json('message'));
        $this->assertSame(PretripReadiness::BLOCKED, $res->json('data.status'));
        $this->assertNotEmpty($res->json('data.blockers'));
        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
    }

    public function test_the_gate_refuses_an_incomplete_checklist(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();

        $res = $this->patchJson($this->url($trip, 'pass-pretrip'))->assertStatus(422);

        $this->assertStringContainsString('Still to confirm', $res->json('message'));
        $this->assertSame(PretripReadiness::IN_PROGRESS, $res->json('data.status'));
    }

    /* ══════════ permissions — defect D-21's derived matrix ══════════ */

    public function test_the_derived_matrix_is_what_was_recorded(): void
    {
        foreach ([
            TransportPermission::ROLE_OWNER, TransportPermission::ROLE_OPERATIONS,
            TransportPermission::ROLE_DISPATCHER, TransportPermission::ROLE_ADMIN,
        ] as $role) {
            $this->assertNotNull(TransportPermission::scopeFor(TransportPermission::PRETRIP_PERFORM, $role), $role);
            $this->assertNotNull(TransportPermission::scopeFor(TransportPermission::PRETRIP_VIEW, $role), $role);
        }

        // Accounts and Approver may watch a block but not clear one — PERM-004's row.
        foreach ([TransportPermission::ROLE_ACCOUNTS, TransportPermission::ROLE_APPROVER] as $role) {
            $this->assertNotNull(TransportPermission::scopeFor(TransportPermission::PRETRIP_VIEW, $role));
            $this->assertNull(TransportPermission::scopeFor(TransportPermission::PRETRIP_PERFORM, $role));
        }

        // Customer and Supplier get nothing at all — a block names a driver.
        foreach ([TransportPermission::ROLE_CUSTOMER, TransportPermission::ROLE_SUPPLIER] as $role) {
            $this->assertNull(TransportPermission::scopeFor(TransportPermission::PRETRIP_VIEW, $role));
            $this->assertNull(TransportPermission::scopeFor(TransportPermission::PRETRIP_PERFORM, $role));
        }
    }

    public function test_the_driver_actor_is_recorded_as_unimplementable_not_granted(): void
    {
        $this->assertNull(TransportPermission::scopeFor(
            TransportPermission::PRETRIP_PERFORM, TransportPermission::ROLE_DRIVER
        ));

        $recorded = TransportPermission::UNIMPLEMENTABLE_ACTORS[TransportPermission::PRETRIP_PERFORM];
        $this->assertArrayHasKey(TransportPermission::ROLE_DRIVER, $recorded);
        $this->assertStringContainsString('no user account', $recorded[TransportPermission::ROLE_DRIVER]);
    }

    public function test_a_dispatcher_may_run_the_whole_flow(): void
    {
        $setup = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $setup);

        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_dispatcher'));

        $this->getJson($this->url($trip))->assertOk();
        $this->postJson($this->url($trip))->assertOk();
        $check = $this->pretrip->checksFor($trip, self::TENANT_A)[0];
        $this->patchJson($this->url($trip).'/'.$check->id)->assertOk();
    }

    public function test_accounts_may_read_readiness_but_not_touch_it(): void
    {
        $setup = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $setup);
        $this->postJson($this->url($trip))->assertOk();
        $check = $this->pretrip->checksFor($trip, self::TENANT_A)[0];

        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'accounts'));

        $this->getJson($this->url($trip))->assertOk();
        $this->postJson($this->url($trip))->assertStatus(403);
        $this->patchJson($this->url($trip).'/'.$check->id)->assertStatus(403);
        $this->patchJson($this->url($trip, 'pass-pretrip'))->assertStatus(403);
    }

    public function test_a_client_is_refused_at_the_role_gate(): void
    {
        $setup = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $setup);

        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        // Refused by the role:admin,staff group gate before any permission is read.
        $this->getJson($this->url($trip))->assertStatus(403);
        $this->postJson($this->url($trip))->assertStatus(403);
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        app('auth')->forgetGuards();
        $this->getJson($this->url($trip))->assertStatus(401);
    }

    public function test_the_capability_endpoint_publishes_the_new_keys(): void
    {
        // The UI must be able to tell "you may not" from "the server is broken".
        $this->actingAdmin();

        $res = $this->getJson('/api/transport/permissions')->assertOk();

        $this->assertArrayHasKey(TransportPermission::PRETRIP_VIEW, $res->json('data.grants'));
        $this->assertArrayHasKey(TransportPermission::PRETRIP_PERFORM, $res->json('data.grants'));
    }

    /* ══════════ tenancy — 404, never 403 ══════════ */

    public function test_another_tenants_trip_is_a_404_on_every_endpoint(): void
    {
        $setup = $this->user(self::TENANT_B);
        Sanctum::actingAs($setup);
        $tripB = $this->crewedTrip(self::TENANT_B, $setup);
        $this->postJson($this->url($tripB))->assertOk();
        $checkB = $this->pretrip->checksFor($tripB, self::TENANT_B)[0];

        // Now as tenant A's admin.
        $this->actingAdmin(self::TENANT_A);

        $this->getJson($this->url($tripB))->assertStatus(404);
        $this->postJson($this->url($tripB))->assertStatus(404);
        $this->patchJson($this->url($tripB).'/'.$checkB->id)->assertStatus(404);
        $this->patchJson($this->url($tripB, 'pass-pretrip'))->assertStatus(404);
    }

    public function test_a_cross_tenant_call_writes_nothing(): void
    {
        $setup = $this->user(self::TENANT_B);
        Sanctum::actingAs($setup);
        $tripB = $this->crewedTrip(self::TENANT_B, $setup);

        $this->actingAdmin(self::TENANT_A);
        $this->postJson($this->url($tripB))->assertStatus(404);

        $this->assertSame(0, TripPretripCheck::count());
        $this->assertSame(TripStatus::ALLOCATED, $tripB->fresh()->status);
    }

    public function test_two_tenants_run_their_own_checklists_over_the_api(): void
    {
        $setupB = $this->user(self::TENANT_B);
        Sanctum::actingAs($setupB);
        $tripB = $this->crewedTrip(self::TENANT_B, $setupB);
        $this->postJson($this->url($tripB))->assertOk();

        $setupA = $this->actingAdmin(self::TENANT_A);
        $tripA  = $this->crewedTrip(self::TENANT_A, $setupA);
        $this->postJson($this->url($tripA))->assertOk();

        $this->assertSame(5, TripPretripCheck::forTenant(self::TENANT_A)->count());
        $this->assertSame(5, TripPretripCheck::forTenant(self::TENANT_B)->count());
    }

    /* ══════════ release through the API invalidates ══════════ */

    public function test_releasing_over_the_api_invalidates_the_checklist(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);
        $this->postJson($this->url($trip))->assertOk();
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $c) {
            $this->patchJson($this->url($trip).'/'.$c->id)->assertOk();
        }
        $this->patchJson($this->url($trip, 'pass-pretrip'))->assertOk();

        $this->deleteJson('/api/transport/trips/'.$trip->id.'/assign')->assertOk();

        $res = $this->getJson($this->url($trip))->assertOk();
        $this->assertSame(PretripReadiness::NOT_STARTED, $res->json('data.status'));
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
    }

    public function test_the_checks_come_back_in_the_document_order(): void
    {
        $actor = $this->actingAdmin();
        $trip  = $this->crewedTrip(actor: $actor);

        $res = $this->postJson($this->url($trip))->assertOk();

        $this->assertSame(PretripCheckKey::GENERATED, array_column($res->json('data.checks'), 'key'));
    }
}
