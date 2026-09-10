<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\Contracts\FleetResourceGateway;
use App\Services\Transport\DispatchService;
use App\Services\Transport\PendingFleetResourceGateway;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\DispatchScope;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Record dispatch — RTM STOS-REQ-OPS-008, FRS TRP-P0-006.
 *
 * No Step 12 ticket owns this (D-18); the owner authorised the bounded scope in
 * writing on 2026-09-10. See DispatchScope::AUTHORIZATION.
 */
class DispatchTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private DispatchService $dispatch;
    private PretripService $pretrip;
    private AllocationService $alloc;
    private TransportVehicleService $vehicleSvc;
    private TransportDriverService $driverSvc;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha', self::TENANT_B => 'Bravo'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->dispatch   = app(DispatchService::class);
        $this->pretrip    = app(PretripService::class);
        $this->alloc      = app(AllocationService::class);
        $this->vehicleSvc = app(TransportVehicleService::class);
        $this->driverSvc  = app(TransportDriverService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Dispatcher', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'd-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** A trip at pretrip_ok, ready to release. */
    private function readyTrip(int $tenantId = self::TENANT_A): array
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

        $v = $this->vehicleSvc->create([
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => 30,
        ], $tenantId, $this->actor);
        $v = $this->vehicleSvc->transitionTo($v, VehicleStatus::AVAILABLE, $tenantId, $this->actor);

        $d = $this->driverSvc->create([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.random_int(100000, 999999),
            'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ], $tenantId, $this->actor);

        $this->alloc->assign($trip->fresh(), $v->id, $d->id, $tenantId, $this->actor);
        $trip = $trip->fresh();

        $this->pretrip->generate($trip, $tenantId, $this->actor);
        foreach ($this->pretrip->checksFor($trip, $tenantId) as $c) {
            $this->pretrip->complete($c, $tenantId, $this->actor);
        }
        $this->pretrip->passPretrip($trip->fresh(), $tenantId, $this->actor);

        return [$trip->fresh(), $v->fresh(), $d->fresh()];
    }

    /** A trip with an order but no crew. order_id is NOT NULL by design (CTR-004). */
    private function bareTrip(string $status): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => self::TENANT_A, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        $trip = TransportTrip::create([
            'tenant_id' => self::TENANT_A, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => $status])->save();

        return $trip->fresh();
    }

    private function fields(): array
    {
        return [
            'planned_departure_at'  => now()->addHours(2)->format('Y-m-d H:i:s'),
            'planned_arrival_at'    => now()->addHours(14)->format('Y-m-d H:i:s'),
            'pickup_contact'        => 'Suresh · 98200 11223',
            'dispatch_destination'  => 'Bhiwandi Warehouse, Gate 3',
            'dispatch_instructions' => 'Report to security first. Seal number on the LR.',
        ];
    }

    /* ══════════ the edge ══════════ */

    public function test_the_dispatch_edge_is_now_wired(): void
    {
        $this->assertTrue(TripStatus::canTransition(TripStatus::PRETRIP_OK, TripStatus::DISPATCHED));
        $this->assertSame(DispatchScope::STATE_EDGE_OWNED, TripStatus::PRETRIP_OK.'->'.TripStatus::DISPATCHED);
    }

    public function test_in_transit_stays_unreachable(): void
    {
        // STT-006 is SNG-TRN-013's Transit half, blocked on Q1/Q3.
        $this->assertFalse(TripStatus::canTransition(TripStatus::DISPATCHED, TripStatus::IN_TRANSIT));
        $this->assertSame(DispatchScope::STATE_EDGE_DEFERRED, TripStatus::DISPATCHED.'->'.TripStatus::IN_TRANSIT);
    }

    public function test_allocation_still_cannot_jump_to_dispatched(): void
    {
        $this->assertFalse(TripStatus::canTransition(TripStatus::ALLOCATED, TripStatus::DISPATCHED));
    }

    /* ══════════ OPS-008 — "Dispatch timestamp/status recorded" ══════════ */

    public function test_confirming_records_status_and_timestamp(): void
    {
        [$trip] = $this->readyTrip();

        $moved = $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::DISPATCHED, $moved->status);
        $this->assertNotNull($moved->dispatched_at, 'OPS-008 requires the dispatch timestamp');
        $this->assertSame($this->actor->id, $moved->dispatched_by);
        $this->assertTrue($moved->isDispatched());
    }

    public function test_all_five_frs_fields_are_stored(): void
    {
        [$trip] = $this->readyTrip();
        $f = $this->fields();

        $moved = $this->dispatch->confirm($trip, $f, self::TENANT_A, $this->actor);

        $this->assertSame($f['pickup_contact'], $moved->pickup_contact);
        $this->assertSame($f['dispatch_destination'], $moved->dispatch_destination);
        $this->assertSame($f['dispatch_instructions'], $moved->dispatch_instructions);
        $this->assertSame($f['planned_departure_at'], $moved->planned_departure_at->format('Y-m-d H:i:s'));
        $this->assertSame($f['planned_arrival_at'], $moved->planned_arrival_at->format('Y-m-d H:i:s'));
    }

    public function test_the_five_field_map_matches_the_frs(): void
    {
        $this->assertSame(
            ['ETD', 'ETA', 'pickup contact', 'destination', 'instructions'],
            array_keys(DispatchScope::FIELD_MAP),
        );
        $this->assertSame(
            DispatchService::DISPATCH_FIELDS,
            array_values(DispatchScope::FIELD_MAP),
        );
    }

    public function test_turnaround_is_derived_not_stored(): void
    {
        // TRP-P0-006 writes "ETA/TAT" as one field and defines TAT nowhere.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('transport_trips', 'tat'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('transport_trips', 'turnaround_hours'));

        [$trip] = $this->readyTrip();
        $moved = $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertEqualsWithDelta(12.0, $moved->turnaroundHours(), 0.02);
    }

    public function test_dispatch_can_be_confirmed_with_no_planning_fields(): void
    {
        // OPS-008's acceptance is only "timestamp/status recorded". Requiring
        // all five would block a real departure over paperwork.
        [$trip] = $this->readyTrip();

        $moved = $this->dispatch->confirm($trip, [], self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::DISPATCHED, $moved->status);
        $this->assertNotNull($moved->dispatched_at);
        $this->assertNull($moved->turnaroundHours());
    }

    /* ══════════ the gate ══════════ */

    public function test_a_trip_that_has_not_passed_pretrip_cannot_dispatch(): void
    {
        $trip = $this->bareTrip(TripStatus::ALLOCATED);

        try {
            $this->dispatch->confirm($trip->fresh(), $this->fields(), self::TENANT_A, $this->actor);
            $this->fail('only a pretrip_ok trip may dispatch');
        } catch (BusinessException $e) {
            // BRW-048 — the exact reason.
            $this->assertStringContainsString('Allocated', $e->getMessage());
            $this->assertStringContainsString('pre-trip checks first', $e->getMessage());
        }

        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
    }

    public function test_dispatching_twice_is_refused(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $before = $trip->fresh()->auditTrail()->count();

        try {
            $this->dispatch->confirm($trip->fresh(), $this->fields(), self::TENANT_A, $this->actor);
            $this->fail('a second dispatch must be refused');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('already been dispatched', $e->getMessage());
        }

        $this->assertSame($before, $trip->fresh()->auditTrail()->count(), 'nothing may re-audit');
    }

    public function test_a_refusal_changes_nothing(): void
    {
        $trip = $this->bareTrip(TripStatus::DRAFT);

        try { $this->dispatch->confirm($trip->fresh(), $this->fields(), self::TENANT_A, $this->actor); } catch (BusinessException) {}

        $fresh = $trip->fresh();
        $this->assertSame(TripStatus::DRAFT, $fresh->status);
        $this->assertNull($fresh->dispatched_at);
        $this->assertSame(0, (int) $fresh->dispatch_version);
    }

    /* ══════════ freeze and versioning — TRP-P0-006 ══════════ */

    public function test_release_sets_version_one_and_freezes(): void
    {
        [$trip] = $this->readyTrip();
        $this->assertFalse($trip->dispatchIsFrozen());

        $moved = $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertSame(1, (int) $moved->dispatch_version);
        $this->assertTrue($moved->dispatchIsFrozen());
        $this->assertTrue($this->dispatch->isFrozen($moved));
    }

    public function test_dispatch_fields_are_not_mass_assignable(): void
    {
        // The freeze must be a rule, not a convention.
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $trip->fresh()->fill([
            'pickup_contact'       => 'HACKED',
            'dispatch_destination' => 'HACKED',
            'planned_departure_at' => now()->addYear(),
        ])->save();

        $after = $trip->fresh();
        $this->assertNotSame('HACKED', $after->pickup_contact);
        $this->assertNotSame('HACKED', $after->dispatch_destination);
    }

    public function test_amending_bumps_the_version_and_records_before_and_after(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $amended = $this->dispatch->amend(
            $trip->fresh(), ['dispatch_destination' => 'Panvel Yard'], 'Customer changed the drop point',
            self::TENANT_A, $this->actor,
        );

        $this->assertSame(2, (int) $amended->dispatch_version);
        $this->assertSame('Panvel Yard', $amended->dispatch_destination);

        $entry = $trip->auditTrail()->where('action', 'transport.dispatch.amended')->first();
        $this->assertNotNull($entry);
        $this->assertSame('Bhiwandi Warehouse, Gate 3', $entry->old_values['dispatch_destination']);
        $this->assertSame('Panvel Yard', $entry->new_values['dispatch_destination']);
        $this->assertSame('Customer changed the drop point', $entry->context['reason']);
        $this->assertSame(2, $entry->context['version']);
    }

    public function test_each_amendment_creates_a_new_version(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        foreach (['A', 'B', 'C'] as $i => $dest) {
            $this->dispatch->amend($trip->fresh(), ['dispatch_destination' => $dest], 'change '.$i, self::TENANT_A, $this->actor);
        }

        $this->assertSame(4, (int) $trip->fresh()->dispatch_version);
        $this->assertSame(3, $trip->auditTrail()->where('action', 'transport.dispatch.amended')->count());
    }

    public function test_amending_requires_a_reason(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        try {
            $this->dispatch->amend($trip->fresh(), ['dispatch_destination' => 'X'], '  ', self::TENANT_A, $this->actor);
            $this->fail('a frozen field cannot change silently');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
            $this->assertStringContainsString('reason', $e->getMessage());
        }
    }

    public function test_amending_before_release_is_refused(): void
    {
        [$trip] = $this->readyTrip();

        $this->expectException(BusinessException::class);
        $this->dispatch->amend($trip, ['dispatch_destination' => 'X'], 'why not', self::TENANT_A, $this->actor);
    }

    public function test_a_no_op_amendment_creates_no_version(): void
    {
        // Otherwise the history fills with noise and a real change is hard to find.
        [$trip] = $this->readyTrip();
        $f = $this->fields();
        $this->dispatch->confirm($trip, $f, self::TENANT_A, $this->actor);

        $this->dispatch->amend($trip->fresh(), ['dispatch_destination' => $f['dispatch_destination']], 'no change', self::TENANT_A, $this->actor);

        $this->assertSame(1, (int) $trip->fresh()->dispatch_version);
        $this->assertSame(0, $trip->auditTrail()->where('action', 'transport.dispatch.amended')->count());
    }

    public function test_the_amendment_states_that_no_approval_was_obtained(): void
    {
        // TRP-P0-006 wants "Change approval after release". None exists, and the
        // absence must not read as an approval.
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);
        $this->dispatch->amend($trip->fresh(), ['pickup_contact' => 'Nitin'], 'contact changed', self::TENANT_A, $this->actor);

        $ctx = $trip->auditTrail()->where('action', 'transport.dispatch.amended')->first()->context;
        $this->assertNull($ctx['approval']);
        $this->assertStringContainsString('no approval entity exists', $ctx['approval_note']);
    }

    /* ══════════ the Fleet boundary — owner's ruling ══════════ */

    public function test_dispatch_does_not_touch_fleet_tables(): void
    {
        [$trip, $vehicle, $driver] = $this->readyTrip();

        $vBefore = $vehicle->fresh()->status;
        $dBefore = $driver->fresh()->availability;

        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertSame($vBefore, $vehicle->fresh()->status, 'transport_vehicles is Developer A territory');
        $this->assertSame($dBefore, $driver->fresh()->availability, 'transport_drivers is Developer A territory');
        // Specifically: still allocated/assigned, NOT in-operation/on-trip.
        $this->assertSame(VehicleStatus::ALLOCATED, $vehicle->fresh()->status);
        $this->assertSame(DriverAvailability::ASSIGNED, $driver->fresh()->availability);
    }

    public function test_the_shipped_gateway_declines_and_says_so(): void
    {
        $this->assertInstanceOf(PendingFleetResourceGateway::class, app(FleetResourceGateway::class));

        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.trip.status_changed')->get()
            ->first(fn ($e) => ($e->new_values['status'] ?? null) === TripStatus::DISPATCHED);

        $this->assertFalse($entry->context['fleet_state_applied']);
        $this->assertStringContainsString('pending Developer A', $entry->context['fleet_boundary']);
    }

    public function test_a_gateway_that_applies_is_recorded_as_applied(): void
    {
        // Proves the seam works, so Developer A's real service needs no change here.
        $this->app->bind(FleetResourceGateway::class, fn () => new class implements FleetResourceGateway {
            public function markDispatched($trip, ?int $v, ?int $d, int $t, $a = null): bool { return true; }
        });

        [$trip] = $this->readyTrip();
        app(DispatchService::class)->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.trip.status_changed')->get()
            ->first(fn ($e) => ($e->new_values['status'] ?? null) === TripStatus::DISPATCHED);

        $this->assertTrue($entry->context['fleet_state_applied']);
        $this->assertNull($entry->context['fleet_boundary']);
    }

    public function test_a_throwing_gateway_does_not_lose_the_dispatch(): void
    {
        // A bookkeeping failure in Fleet must not block a real departure.
        $this->app->bind(FleetResourceGateway::class, fn () => new class implements FleetResourceGateway {
            public function markDispatched($trip, ?int $v, ?int $d, int $t, $a = null): bool {
                return false;
            }
        });

        [$trip] = $this->readyTrip();
        $moved = app(DispatchService::class)->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::DISPATCHED, $moved->status);
    }

    /* ══════════ audit ══════════ */

    public function test_the_transition_is_audited_with_its_authorization(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.trip.status_changed')->get()
            ->first(fn ($e) => ($e->new_values['status'] ?? null) === TripStatus::DISPATCHED);

        $this->assertNotNull($entry);
        $this->assertSame(['status' => TripStatus::PRETRIP_OK], $entry->old_values);
        $this->assertSame(['status' => TripStatus::DISPATCHED], $entry->new_values);
        $this->assertSame($this->actor->id, $entry->actor_id);

        $ctx = $entry->context;
        $this->assertSame(DispatchScope::OPS_008, $ctx['rule']);
        $this->assertStringContainsString('STT-005', $ctx['registry']);
        // No ticket exists — the authorization must be on the record.
        $this->assertStringContainsString('2026-09-10', $ctx['authorization']);
        $this->assertStringContainsString('STOS-REQ-OPS-008', $ctx['sources']);
        $this->assertSame(1, $ctx['version']);
    }

    public function test_the_audit_names_the_side_effects_that_did_not_happen(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.trip.status_changed')->get()
            ->first(fn ($e) => ($e->new_values['status'] ?? null) === TripStatus::DISPATCHED);

        $deferred = $entry->context['deferred_effects'];
        foreach (['Update Vehicle = In Operation', 'Update Driver = On Trip', 'Start GPS monitoring', 'Activate trip SLA'] as $e) {
            $this->assertContains($e, $deferred);
        }
        $this->assertNotContains('Create/start Trip', $deferred);
    }

    public function test_brw_050_is_fully_dispositioned(): void
    {
        $d = DispatchScope::BRW_050_DISPOSITION;
        $this->assertCount(8, $d, 'BRW-050 names eight side effects');
        foreach ($d as $effect => $status) {
            $this->assertContains($status, ['built', 'boundary', 'no_ticket', 'blocked'], $effect);
        }
    }

    /* ══════════ tenancy ══════════ */

    public function test_another_tenant_cannot_dispatch_the_trip(): void
    {
        [$tripA] = $this->readyTrip(self::TENANT_A);

        $this->expectException(\App\Exceptions\ResourceNotFoundException::class);
        $this->dispatch->confirm($tripA, $this->fields(), self::TENANT_B, $this->actor);
    }

    public function test_a_cross_tenant_attempt_changes_nothing(): void
    {
        [$tripA] = $this->readyTrip(self::TENANT_A);

        try { $this->dispatch->confirm($tripA, $this->fields(), self::TENANT_B, $this->actor); } catch (\Throwable) {}

        $this->assertSame(TripStatus::PRETRIP_OK, $tripA->fresh()->status);
        $this->assertNull($tripA->fresh()->dispatched_at);
    }

    public function test_another_tenant_cannot_amend(): void
    {
        [$tripA] = $this->readyTrip(self::TENANT_A);
        $this->dispatch->confirm($tripA, $this->fields(), self::TENANT_A, $this->actor);

        $this->expectException(\App\Exceptions\ResourceNotFoundException::class);
        $this->dispatch->amend($tripA->fresh(), ['pickup_contact' => 'X'], 'reason', self::TENANT_B, $this->actor);
    }

    public function test_two_tenants_dispatch_independently(): void
    {
        [$a] = $this->readyTrip(self::TENANT_A);
        [$b] = $this->readyTrip(self::TENANT_B);

        $this->dispatch->confirm($a, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::DISPATCHED, $a->fresh()->status);
        $this->assertSame(TripStatus::PRETRIP_OK, $b->fresh()->status);
    }

    /* ══════════ interaction with what is already built ══════════ */

    public function test_the_pretrip_checklist_survives_dispatch(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertSame(5, $this->pretrip->checksFor($trip->fresh(), self::TENANT_A)->count());
    }

    public function test_the_crew_stays_assigned_after_dispatch(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->assertNotNull(
            TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->active()->first(),
        );
    }

    /* ══════════ BRW-046 — readiness re-derived at dispatch ══════════
     *
     * Owner's ruling, 2026-09-10: option (b). Pre-trip records what was true
     * when it was confirmed; BRW-046 is about the moment of departure. These
     * tests are the difference between the two.
     */

    public function test_a_licence_that_expires_after_pretrip_blocks_dispatch(): void
    {
        [$trip, , $driver] = $this->readyTrip();

        // Passed pre-trip on a valid licence, and the stored row still says so.
        $this->assertSame(TripStatus::PRETRIP_OK, $trip->fresh()->status);
        $stored = $this->pretrip->checksFor($trip->fresh(), self::TENANT_A)
            ->firstWhere('check_key', PretripCheckKey::DRIVER_DOCUMENTS);
        $this->assertFalse($stored->blocks(), 'precondition: the check passed at pre-trip');

        // Time passes. The licence lapses in the yard.
        $driver->forceFill(['licence_valid_until' => now()->subDay()->toDateString()])->save();

        try {
            $this->dispatch->confirm($trip->fresh(), $this->fields(), self::TENANT_A, $this->actor);
            $this->fail('a trip with a lapsed licence was dispatched');
        } catch (BusinessException $e) {
            // BRW-048's "exact reason", and OPS §30's pairing of reason with fix.
            $this->assertStringContainsString('Dispatch blocked', $e->getMessage());
            $this->assertStringContainsString('has changed since', $e->getMessage());
            $this->assertStringContainsString('re-run the checklist', $e->getMessage());
            $this->assertSame(422, $e->getStatusCode());
        }

        // A refusal leaves the trip where it was, so fix-and-retry stays open.
        $this->assertSame(TripStatus::PRETRIP_OK, $trip->fresh()->status);
        $this->assertNull($trip->fresh()->dispatched_at);
    }

    public function test_revalidation_names_the_check_that_lapsed(): void
    {
        [$trip, , $driver] = $this->readyTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()->toDateString()])->save();

        $live = $this->pretrip->revalidate($trip->fresh(), self::TENANT_A);

        $this->assertFalse($live['ready']);
        $this->assertSame(PretripReadiness::BLOCKED, $live['status']);
        $this->assertCount(1, $live['lapsed']);
        $this->assertSame(PretripCheckKey::DRIVER_DOCUMENTS, $live['lapsed'][0]['key']);
        // Both sides of the change, so a screen can say it USED to pass.
        $this->assertSame(PretripResult::PASS, $live['lapsed'][0]['was']);
        $this->assertSame(PretripResult::CRITICAL_FAIL, $live['lapsed'][0]['now']);
    }

    public function test_revalidation_writes_nothing(): void
    {
        [$trip, , $driver] = $this->readyTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()->toDateString()])->save();

        $before = $this->pretrip->checksFor($trip->fresh(), self::TENANT_A)
            ->map(fn ($c) => [$c->check_key, $c->result, $c->completed_at?->toIso8601String()])->all();
        $audits = TransportAuditLog::forTenant(self::TENANT_A)->count();

        $this->pretrip->revalidate($trip->fresh(), self::TENANT_A);

        $after = $this->pretrip->checksFor($trip->fresh(), self::TENANT_A)
            ->map(fn ($c) => [$c->check_key, $c->result, $c->completed_at?->toIso8601String()])->all();

        // The stored checklist is the record of what was CONFIRMED. A second
        // opinion taken at departure must not overwrite it.
        $this->assertSame($before, $after);
        $this->assertSame($audits, TransportAuditLog::forTenant(self::TENANT_A)->count());
        $this->assertSame(TripStatus::PRETRIP_OK, $trip->fresh()->status);
    }

    public function test_a_still_valid_trip_dispatches_unchanged(): void
    {
        [$trip] = $this->readyTrip();

        $live = $this->pretrip->revalidate($trip, self::TENANT_A);
        $this->assertTrue($live['ready']);
        $this->assertTrue($live['checked'], 'an empty checklist must not read as a pass');
        $this->assertSame([], $live['lapsed']);

        $moved = $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::DISPATCHED, $moved->status);
    }

    public function test_fixing_the_lapse_reopens_dispatch(): void
    {
        [$trip, , $driver] = $this->readyTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()->toDateString()])->save();

        $this->assertFalse($this->pretrip->revalidate($trip->fresh(), self::TENANT_A)['ready']);

        $driver->forceFill(['licence_valid_until' => now()->addYear()->toDateString()])->save();

        $moved = $this->dispatch->confirm($trip->fresh(), $this->fields(), self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::DISPATCHED, $moved->status);
    }

    public function test_an_already_dispatched_trip_is_not_revalidated(): void
    {
        [$trip, , $driver] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        // A licence expiring mid-trip is a transit exception (SNG-TRN-013), not
        // a reason to refuse an amendment to a trip that has already left.
        $driver->forceFill(['licence_valid_until' => now()->subDay()->toDateString()])->save();

        $amended = $this->dispatch->amend(
            $trip->fresh(), ['dispatch_instructions' => 'Call on arrival'], 'Customer request',
            self::TENANT_A, $this->actor,
        );

        $this->assertSame(2, (int) $amended->dispatch_version);
    }

    /* ══════════ TRP-P0-006 — version history ══════════ */

    public function test_history_is_empty_before_release(): void
    {
        [$trip] = $this->readyTrip();

        $this->assertSame([], $this->dispatch->history($trip, self::TENANT_A));
    }

    public function test_version_one_is_the_release_itself(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $history = $this->dispatch->history($trip->fresh(), self::TENANT_A);

        $this->assertCount(1, $history);
        $this->assertSame(1, $history[0]['version']);
        $this->assertSame('release', $history[0]['type']);
        $this->assertNull($history[0]['before']);
        $this->assertSame($this->actor->id, $history[0]['actor_id']);
        // The reader of the history is exactly who needs to know Fleet did not move.
        $this->assertFalse($history[0]['fleet_state_applied']);
        $this->assertNotNull($history[0]['fleet_boundary']);
    }

    public function test_each_amendment_appends_a_version_with_its_reason(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->dispatch->amend($trip->fresh(), ['dispatch_destination' => 'Nhava Sheva'], 'Customer changed the drop', self::TENANT_A, $this->actor);
        $this->dispatch->amend($trip->fresh(), ['dispatch_instructions' => 'Gate 4'], 'Port advisory', self::TENANT_A, $this->actor);

        $history = $this->dispatch->history($trip->fresh(), self::TENANT_A);

        // Oldest first — a history is read forwards.
        $this->assertSame([1, 2, 3], array_column($history, 'version'));
        $this->assertSame(['release', 'amendment', 'amendment'], array_column($history, 'type'));

        $this->assertSame('Customer changed the drop', $history[1]['reason']);
        $this->assertSame(['dispatch_destination'], $history[1]['fields']);
        $this->assertSame('Bhiwandi Warehouse, Gate 3', $history[1]['before']['dispatch_destination']);
        $this->assertSame('Nhava Sheva', $history[1]['after']['dispatch_destination']);

        // Nobody may read the absence of an approver as an approval.
        $this->assertNull($history[1]['approval']);
        $this->assertNotNull($history[1]['approval_note']);
    }

    public function test_a_no_op_amendment_leaves_no_gap_in_the_history(): void
    {
        [$trip] = $this->readyTrip();
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);
        $this->dispatch->amend($trip->fresh(), ['dispatch_destination' => 'Bhiwandi Warehouse, Gate 3'], 'No change', self::TENANT_A, $this->actor);

        $this->assertSame([1], array_column($this->dispatch->history($trip->fresh(), self::TENANT_A), 'version'));
    }

    public function test_history_carries_no_other_tenants_entries(): void
    {
        [$a] = $this->readyTrip(self::TENANT_A);
        [$b] = $this->readyTrip(self::TENANT_B);

        $this->dispatch->confirm($a, $this->fields(), self::TENANT_A, $this->actor);
        $this->dispatch->confirm($b, $this->fields(), self::TENANT_B, $this->actor);

        $this->assertCount(1, $this->dispatch->history($a->fresh(), self::TENANT_A));
        $this->assertCount(1, $this->dispatch->history($b->fresh(), self::TENANT_B));
    }

    public function test_history_for_another_tenants_trip_is_a_404(): void
    {
        [$trip] = $this->readyTrip(self::TENANT_A);
        $this->dispatch->confirm($trip, $this->fields(), self::TENANT_A, $this->actor);

        $this->expectException(\App\Exceptions\ResourceNotFoundException::class);
        $this->dispatch->history($trip->fresh(), self::TENANT_B);
    }

    /* ══════════ the gate blocks on FAILURE, never on incompleteness ══════
     *
     * A trip at pretrip_ok can no longer regenerate its checklist
     * (GENERATABLE_FROM is [approved, allocated]). So if re-validation demanded
     * a fresh human confirmation, a policy change after the gate was passed
     * would strand the trip with no way out. BRW-052 is the rule that applies:
     * a CRITICAL FAILURE blocks dispatch. These pin that down.
     */

    public function test_a_check_enabled_after_the_gate_does_not_strand_the_trip(): void
    {
        [$trip] = $this->readyTrip();

        // The tenant widens its policy after this trip passed. The new check has
        // no stored row, so nobody has confirmed it — but it passes, and a
        // passing check is not a reason to hold a vehicle in the yard.
        $policies = app(\App\Services\Transport\TransportPolicyService::class);
        $policies->set(self::TENANT_A, 'pretrip.check.'.PretripCheckKey::VEHICLE_COMPLIANCE.'.critical', true, $this->actor);

        $live = $this->pretrip->revalidate($trip->fresh(), self::TENANT_A);
        $this->assertFalse($live['blocking']);

        $moved = $this->dispatch->confirm($trip->fresh(), $this->fields(), self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::DISPATCHED, $moved->status);
    }

    public function test_disabling_every_check_after_the_gate_does_not_strand_the_trip(): void
    {
        [$trip] = $this->readyTrip();

        app(\App\Services\Transport\TransportPolicyService::class)
            ->set(self::TENANT_A, 'pretrip.checks.enabled', [], $this->actor);

        $live = $this->pretrip->revalidate($trip->fresh(), self::TENANT_A);

        // Nothing left that COULD lapse. Not a pass on the merits, and `checked`
        // says so — but not a reason to refuse a trip that already passed.
        $this->assertFalse($live['checked']);
        $this->assertFalse($live['blocking']);
        $this->assertSame([], $live['blockers']);

        $moved = $this->dispatch->confirm($trip->fresh(), $this->fields(), self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::DISPATCHED, $moved->status);
    }

    public function test_a_newly_applicable_check_that_fails_still_blocks(): void
    {
        [$trip, , $driver] = $this->readyTrip();

        // The distinction that matters: incompleteness does not block, a
        // failure does — even one the policy only just started asking for.
        $driver->forceFill(['licence_valid_until' => now()->subDay()->toDateString()])->save();

        $live = $this->pretrip->revalidate($trip->fresh(), self::TENANT_A);

        $this->assertTrue($live['blocking']);
        $this->assertNotEmpty($live['blockers']);
    }
}
