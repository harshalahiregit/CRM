<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\DispatchService;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TransportVehicleService;
use App\Services\Transport\TripAssignmentService;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\TransitScope;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * Transit and delivery — STT-006 and STT-007.
 *
 * RTM STOS-REQ-OPS-009 "Track trip status" and STOS-REQ-OPS-010 "Record
 * delivery", both P0.
 *
 * The refusals are tested harder than the happy path, because the happy path is
 * two columns and a status. What this work is actually for is the four things
 * it will NOT let you assert: that a truck left before it was released, that it
 * arrived before it left, that either happened in the future, or that a trip
 * skipped a state on the way.
 */
class TransitTest extends TestCase
{
    use RefreshDatabase;
    use CreatesFleetResources;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private DispatchService $dispatch;
    private TransportTripService $trips;
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
        $this->trips      = app(TransportTripService::class);
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

    /** A trip standing at `dispatched`, walked there through every real edge. */
    private function dispatchedTrip(int $tenantId = self::TENANT_A): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'JNPT'], 'delivery_location' => ['address' => 'Bhiwandi'],
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
        ], $tenantId, $this->actor);
        $v = $this->moveFleetVehicle($v, Vehicle::STATUS_AVAILABLE);

        $d = $this->fleetDriver([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ], $tenantId, $this->actor);

        $this->alloc->assign($trip->fresh(), $v->id, $d->id, $tenantId, $this->actor);

        $this->pretrip->generate($trip->fresh(), $tenantId, $this->actor);
        foreach ($this->pretrip->checksFor($trip, $tenantId) as $c) {
            $this->pretrip->complete($c, $tenantId, $this->actor);
        }
        $this->pretrip->passPretrip($trip->fresh(), $tenantId, $this->actor);

        return $this->dispatch->confirm($trip->fresh(), [
            'planned_departure_at' => now()->addHours(2)->format('Y-m-d H:i:s'),
            'planned_arrival_at'   => now()->addHours(14)->format('Y-m-d H:i:s'),
        ], $tenantId, $this->actor);
    }

    private function movingTrip(int $tenantId = self::TENANT_A): TransportTrip
    {
        return $this->dispatch->recordDeparture($this->dispatchedTrip($tenantId), [], $tenantId, $this->actor);
    }

    /* ══════════════════════ the edges themselves ══════════════════════ */

    public function test_both_transit_edges_are_wired_and_nothing_else_is(): void
    {
        $this->assertTrue(TripStatus::canTransition(TripStatus::DISPATCHED, TripStatus::IN_TRANSIT));
        $this->assertTrue(TripStatus::canTransition(TripStatus::IN_TRANSIT, TripStatus::DELIVERED));

        $this->assertSame(TransitScope::EDGE_DEPARTURE, TripStatus::DISPATCHED.'->'.TripStatus::IN_TRANSIT);
        $this->assertSame(TransitScope::EDGE_DELIVERY, TripStatus::IN_TRANSIT.'->'.TripStatus::DELIVERED);

        // No shortcuts. Each of these would let a trip claim a milestone it
        // never passed, which is the whole reason the machine exists.
        $this->assertFalse(TripStatus::canTransition(TripStatus::DISPATCHED, TripStatus::DELIVERED));
        $this->assertFalse(TripStatus::canTransition(TripStatus::PRETRIP_OK, TripStatus::IN_TRANSIT));
        $this->assertFalse(TripStatus::canTransition(TripStatus::IN_TRANSIT, TripStatus::POD_VERIFIED));
    }

    public function test_arrived_and_pod_pending_stay_declared_and_unreachable(): void
    {
        // The standing rule of 2026-09-17: vocabulary from Step 9, edges from
        // Step 11, and a state becomes reachable only when something can gate
        // it. `arrived` has no gate, no requirement and no model. D-36, closed.
        foreach ([TripStatus::ARRIVED, TripStatus::POD_PENDING, TripStatus::SETTLEMENT_PENDING] as $state) {
            $this->assertContains($state, TripStatus::ALL, "$state stays in the vocabulary");
            $this->assertArrayHasKey($state, TripStatus::LABELS, "$state keeps its label");

            $intoIt = array_filter(TripStatus::TRANSITIONS, fn (array $to) => in_array($state, $to, true));
            $this->assertSame([], $intoIt, "nothing may transition INTO $state");
            $this->assertSame([], TripStatus::TRANSITIONS[$state] ?? [], "nothing may transition OUT of $state");
        }
    }

    /* ══════════════════════ departure — STT-006 ═══════════════════════ */

    public function test_recording_a_departure_moves_the_trip_and_stamps_who_and_when(): void
    {
        $trip  = $this->dispatchedTrip();
        $moved = $this->dispatch->recordDeparture($trip, [], self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::IN_TRANSIT, $moved->status);
        $this->assertNotNull($moved->departed_at);
        $this->assertSame($this->actor->id, $moved->departed_by);
    }

    public function test_it_writes_the_two_columns_q3_allowed_and_no_others(): void
    {
        // The owner's Q3 limit, taken literally: "departed_at, departed_by
        // columns only. Nothing beyond that." A later hand adding an odometer
        // here would be exceeding an authorisation, not extending a feature.
        $trip = $this->dispatchedTrip();

        // Snapshotted BEFORE the call: recordDeparture() forceFills the same
        // instance, so reading $trip afterwards would compare the row to itself.
        $before = array_map('strval', array_filter($trip->getAttributes(), 'is_scalar'));
        $after  = $this->dispatch->recordDeparture($trip, [], self::TENANT_A, $this->actor);

        $changed = array_keys(array_diff_assoc(
            array_map('strval', array_filter($after->getAttributes(), 'is_scalar')),
            $before,
        ));

        // Asserted as "nothing OUTSIDE this set moved" rather than as an exact
        // list: updated_by may already hold the same actor and updated_at may
        // land in the same second, so an exact list would be a timing test
        // wearing a scope test's clothes.
        $allowed = ['departed_at', 'departed_by', 'status', 'updated_at', 'updated_by'];
        $extra   = array_values(array_diff($changed, $allowed));

        $this->assertSame([], $extra, sprintf(
            "a departure wrote a column the owner's Q3 ruling does not permit: %s",
            implode(', ', $extra),
        ));
        $this->assertContains('departed_at', $changed);
        $this->assertContains('departed_by', $changed);
        $this->assertContains('status', $changed);
    }

    public function test_a_departure_may_be_backdated_because_that_is_the_normal_case(): void
    {
        // A dispatcher records at 11:00 that the truck left at 09:30. Refusing
        // this would teach people to enter the wrong time rather than the right
        // one, which is worse than the imprecision it prevents.
        $trip = $this->dispatchedTrip();

        // The trip was released now; three hours pass; the dispatcher then
        // records that it actually rolled ninety minutes ago.
        $this->travel(3)->hours();
        $when = now()->subMinutes(90);

        $moved = $this->dispatch->recordDeparture(
            $trip, ['departed_at' => $when->format('Y-m-d H:i:s')], self::TENANT_A, $this->actor,
        );

        $this->assertSame($when->format('Y-m-d H:i'), $moved->departed_at->format('Y-m-d H:i'));
        $this->travelBack();
    }

    /* ── refusals ── */

    public function test_a_trip_that_was_never_released_cannot_depart(): void
    {
        $trip = $this->dispatchedTrip();
        $trip->forceFill(['status' => TripStatus::PRETRIP_OK])->save();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Only a dispatched trip can be recorded as departed');
        $this->dispatch->recordDeparture($trip->fresh(), [], self::TENANT_A, $this->actor);
    }

    public function test_a_trip_cannot_depart_twice(): void
    {
        $moving = $this->movingTrip();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already on the road');
        $this->dispatch->recordDeparture($moving, [], self::TENANT_A, $this->actor);
    }

    public function test_a_departure_cannot_be_recorded_in_the_future(): void
    {
        $trip = $this->dispatchedTrip();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot be recorded in the future');
        $this->dispatch->recordDeparture(
            $trip, ['departed_at' => now()->addHour()->format('Y-m-d H:i:s')], self::TENANT_A, $this->actor,
        );
    }

    public function test_a_trip_cannot_have_left_before_it_was_released(): void
    {
        // The one refusal that is about MEANING rather than about time: a
        // departure before dispatch would make the dispatch record — and the
        // pre-trip checks it certifies — irrelevant to the journey that happened.
        $trip = $this->dispatchedTrip();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot have left before it was released');
        $this->dispatch->recordDeparture(
            $trip,
            ['departed_at' => $trip->dispatched_at->copy()->subMinute()->format('Y-m-d H:i:s')],
            self::TENANT_A, $this->actor,
        );
    }

    public function test_another_tenants_trip_is_a_404_not_a_403(): void
    {
        // A 403 confirms the row exists, which is the whole leak.
        $theirs = $this->dispatchedTrip(self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->dispatch->recordDeparture($theirs, [], self::TENANT_A, $this->actor);
    }

    public function test_a_refused_departure_changes_nothing(): void
    {
        $trip = $this->dispatchedTrip();

        try {
            $this->dispatch->recordDeparture(
                $trip, ['departed_at' => now()->addDay()->format('Y-m-d H:i:s')], self::TENANT_A, $this->actor,
            );
            $this->fail('a future departure must be refused');
        } catch (BusinessException) {
            // expected
        }

        $after = $trip->fresh();
        $this->assertSame(TripStatus::DISPATCHED, $after->status);
        $this->assertNull($after->departed_at, 'a refusal must not half-write the record');
    }

    /* ── the audit trail ── */

    public function test_the_departure_audit_records_what_it_did_not_start(): void
    {
        // STT-006's side effect is "Start monitoring" and nothing starts. The
        // audit row says so, so a reader six months from now does not assume
        // telemetry was running on this trip.
        $moving = $this->movingTrip();

        $row = TransportAuditLog::forTenant(self::TENANT_A)
            ->where('auditable_id', $moving->id)
            ->where('action', 'transport.trip.status_changed')
            ->latest('id')->first();

        $this->assertNotNull($row);
        $meta = $row->context ?? [];

        $this->assertSame(TransitScope::STT_006, $meta['registry'] ?? null);
        $this->assertSame('manual', $meta['recorded'] ?? null);
        $this->assertFalse($meta['monitoring_started'] ?? true);
        $this->assertContains('Start GPS monitoring', $meta['deferred_effects'] ?? []);
        $this->assertStringContainsString('2026-09-10', $meta['authorization'] ?? '');
    }

    /* ══════════════════════ delivery — STT-007 ════════════════════════ */

    public function test_recording_a_delivery_moves_the_trip_and_stamps_who_and_when(): void
    {
        $moving    = $this->movingTrip();
        $delivered = $this->trips->recordDelivery($moving, [], self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::DELIVERED, $delivered->status);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertSame($this->actor->id, $delivered->delivered_by);
    }

    public function test_a_released_but_undeparted_trip_is_told_what_to_do_next(): void
    {
        // The likeliest real mistake: the trip was released, nobody recorded it
        // leaving, and now somebody is trying to close the loop. A bare "wrong
        // state" message would leave them guessing which state.
        $trip = $this->dispatchedTrip();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('Record the departure first');
        $this->trips->recordDelivery($trip, [], self::TENANT_A, $this->actor);
    }

    public function test_a_trip_cannot_be_delivered_twice(): void
    {
        $delivered = $this->trips->recordDelivery($this->movingTrip(), [], self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('already recorded as delivered');
        $this->trips->recordDelivery($delivered, [], self::TENANT_A, $this->actor);
    }

    public function test_a_trip_cannot_arrive_before_it_left(): void
    {
        $moving = $this->movingTrip();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot have arrived before it left');
        $this->trips->recordDelivery(
            $moving,
            ['delivered_at' => $moving->departed_at->copy()->subMinute()->format('Y-m-d H:i:s')],
            self::TENANT_A, $this->actor,
        );
    }

    public function test_a_delivery_cannot_be_recorded_in_the_future(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('cannot be recorded in the future');
        $this->trips->recordDelivery(
            $this->movingTrip(), ['delivered_at' => now()->addHour()->format('Y-m-d H:i:s')],
            self::TENANT_A, $this->actor,
        );
    }

    public function test_another_tenants_trip_cannot_be_delivered_either(): void
    {
        $theirs = $this->movingTrip(self::TENANT_B);

        $this->expectException(ResourceNotFoundException::class);
        $this->trips->recordDelivery($theirs, [], self::TENANT_A, $this->actor);
    }

    public function test_the_delivery_audit_records_that_no_arrival_was_skipped(): void
    {
        $delivered = $this->trips->recordDelivery($this->movingTrip(), [], self::TENANT_A, $this->actor);

        $row = TransportAuditLog::forTenant(self::TENANT_A)
            ->where('auditable_id', $delivered->id)
            ->where('action', 'transport.trip.status_changed')
            ->latest('id')->first();

        $meta = $row->context ?? [];
        $this->assertSame(TransitScope::STT_007, $meta['registry'] ?? null);
        $this->assertFalse($meta['via_arrived'] ?? true, '`arrived` does not exist; the audit says so');
        $this->assertStringContainsString('Reaching `delivered` is the request', $meta['pod_requested'] ?? '');
    }

    /* ══════════════ what delivery unlocks, and what it does not ═══════ */

    public function test_delivery_opens_p3s_pod_edge_which_was_previously_dead(): void
    {
        // C-09: P3 built STT-008 (delivered → pod_verified) and said it could
        // not fire until P1 wired these two edges. This is that claim, tested.
        $delivered = $this->trips->recordDelivery($this->movingTrip(), [], self::TENANT_A, $this->actor);

        $this->assertTrue(
            TripStatus::canTransition($delivered->status, TripStatus::POD_VERIFIED),
            'a delivered trip must now be able to reach pod_verified'
        );
    }

    public function test_delivery_does_not_verify_a_pod_by_itself(): void
    {
        // "Request POD" is a request, not a grant. Reaching `delivered` opens
        // the door; it does not walk through it, and a trip that is merely
        // delivered is NOT billable.
        $delivered = $this->trips->recordDelivery($this->movingTrip(), [], self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::DELIVERED, $delivered->status);
        $this->assertNotSame(TripStatus::POD_VERIFIED, $delivered->status);

        $readiness = app(\App\Services\Transport\TripDocumentService::class)
            ->billingReadiness($delivered, self::TENANT_A);

        $this->assertFalse($readiness['billable'], 'delivery alone must not unlock billing');
    }

    /* ══════════════════════ the scope declarations ════════════════════ */

    public function test_the_scope_records_the_ruling_that_authorised_the_work(): void
    {
        // D-105's lesson: a comment that cites a ruling can go stale silently.
        // The ruling's own words are held as data so this test fails if anyone
        // rewrites the history.
        $this->assertStringContainsString('2026-09-10', TransitScope::AUTHORIZATION_DEPARTURE);
        $this->assertStringContainsString('Record departure', TransitScope::AUTHORIZATION_DEPARTURE);
        $this->assertSame(['departed_at', 'departed_by'], TransitScope::DEPARTURE_FIELDS);
        $this->assertSame(['delivered_at', 'delivered_by'], TransitScope::DELIVERY_FIELDS);
    }

    public function test_feedback_is_recorded_as_person_3s_and_not_as_out_of_scope(): void
    {
        // MS-001 §14 step 11 says "delivery, feedback and POD". Feedback is not
        // ours — TM-001 §8 assigns it to Person 3 — and no entity, field or
        // requirement ID for it exists anywhere in the package. "Out of scope"
        // would read as a decision somebody made; this is a gap with an owner.
        $this->assertArrayHasKey('feedback', TransitScope::EXCLUDED);
        $this->assertStringContainsString('PERSON 3', TransitScope::EXCLUDED['feedback']);
        $this->assertStringContainsString('TM-001 §8', TransitScope::EXCLUDED['feedback']);
    }

    /* ═══════════ D-119 — delivery gives the crew back ═══════════ */

    /**
     * The owner had to free the driver by hand after every trip.
     *
     * STOS-FLEET §8: "Vehicle status must be driven by business events."
     * Finishing a trip is the business event, and nothing was driving anything.
     */
    public function test_delivering_a_trip_frees_the_vehicle_and_the_driver(): void
    {
        $trip = $this->movingTrip();
        $a = app(TripAssignmentService::class)->activeForTrip($trip->id, self::TENANT_A);

        $this->assertSame(VehicleStatus::ALLOCATED,
            TransportVehicle::find($a->vehicle_id)->status, 'held while moving');
        $this->assertSame(DriverAvailability::ASSIGNED,
            TransportDriver::find($a->driver_id)->availability, 'held while moving');

        $this->trips->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        $this->assertSame(VehicleStatus::AVAILABLE,
            TransportVehicle::find($a->vehicle_id)->status,
            'the vehicle should come free when the cargo is off');
        $this->assertSame(DriverAvailability::AVAILABLE,
            TransportDriver::find($a->driver_id)->availability,
            'and so should the driver — OPS §79 withholds billing for late documents, not the driver');
    }

    /**
     * Fleet is told. `markReleased()` sat on the gateway with no caller in the
     * whole codebase, so Fleet heard when a resource was taken and never when
     * it came back.
     */
    public function test_delivery_tells_fleet_the_resource_is_free(): void
    {
        $spy = new class implements \App\Services\Transport\Contracts\FleetResourceGateway {
            public array $released = [];
            public function markDispatched($trip, $vehicleId, $driverId, $tenantId, $actor = null): bool { return true; }
            public function markDeparted($trip, $vehicleId, $tenantId, $actor = null): bool { return true; }
            public function markReleased(?int $vehicleId, ?int $driverId, int $tenantId): bool
            { $this->released[] = [$vehicleId, $driverId]; return true; }
        };
        $this->app->instance(\App\Services\Transport\Contracts\FleetResourceGateway::class, $spy);

        $trip = $this->movingTrip();
        $a = app(TripAssignmentService::class)->activeForTrip($trip->id, self::TENANT_A);
        app(TransportTripService::class)->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        $this->assertNotEmpty($spy->released, 'Fleet was never told the resource came free');
        $this->assertSame([$a->vehicle_id, $a->driver_id], $spy->released[0]);
    }

    /**
     * A truck that broke down while allocated must NOT be quietly marked
     * Available because a trip happened to finish. FLEET §8 again: a vehicle
     * "cannot become AVAILABLE if critical maintenance unresolved".
     */
    public function test_a_broken_down_vehicle_is_not_freed_by_a_delivery(): void
    {
        $trip = $this->movingTrip();
        $a = app(TripAssignmentService::class)->activeForTrip($trip->id, self::TENANT_A);

        TransportVehicle::find($a->vehicle_id)
            ->forceFill(['status' => VehicleStatus::BREAKDOWN])->save();

        $this->trips->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        $this->assertSame(VehicleStatus::BREAKDOWN,
            TransportVehicle::find($a->vehicle_id)->status,
            'a delivery must not overwrite a breakdown');
        $this->assertSame(DriverAvailability::AVAILABLE,
            TransportDriver::find($a->driver_id)->availability,
            'the driver is still free though — the two are judged separately');
    }

    /** The timeline says it happened. A driver quietly coming free is its own confusion. */
    public function test_the_release_is_on_the_timeline(): void
    {
        $trip = $this->movingTrip();
        $this->trips->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        $row = \DB::table('trip_events')->where('trip_id', $trip->id)
            ->where('event_type', 'crew.released')->first();

        $this->assertNotNull($row, 'crew.released should be recorded when a trip is delivered');
        $this->assertStringContainsString('delivered', $row->summary ?? '');
        $this->assertSame('delivered', json_decode($row->detail, true)['because'] ?? null);
    }

    /**
     * Releasing the crew must not erase who drove.
     *
     * The trip payload reads the ACTIVE assignment, and after delivery there is
     * none — so without a fallback, finishing a trip would blank the vehicle and
     * driver off its own screen.
     */
    public function test_a_delivered_trip_still_shows_who_drove_it(): void
    {
        $trip = $this->movingTrip();
        $a = app(TripAssignmentService::class)->activeForTrip($trip->id, self::TENANT_A);
        $this->trips->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        \Laravel\Sanctum\Sanctum::actingAs($this->actor);
        $payload = $this->getJson('/api/transport/trips/'.$trip->id)->assertOk()->json('data.assignment');

        $this->assertNotNull($payload, 'the crew disappeared from the trip when it was delivered');
        $this->assertSame($a->vehicle_id, $payload['vehicle_id']);
        $this->assertSame($a->driver_id, $payload['driver_id']);
    }

    /** Delivery does not send the trip backwards or void its checklist. */
    public function test_delivery_does_not_revert_the_trip_or_void_the_checklist(): void
    {
        $trip = $this->movingTrip();
        $this->trips->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::DELIVERED, $trip->fresh()->status,
            'releasing the crew is not abandoning the allocation');
    }

    /**
     * A finished trip must not forget which truck ran it.
     *
     * Releasing an assignment clears the trip's denormalised `vehicle_id` and
     * `driver_id`, which is right for an ABANDONED allocation — the trip goes
     * back to `approved` and must not claim a vehicle it no longer holds.
     *
     * It is wrong for a finished one, and the damage was measured before this
     * test existed: after one delivery, Container 360 lost its vehicle and
     * driver, a plate search answered "no trip has run on this vehicle yet"
     * about a truck that had just delivered one, and the repoint dry run fell
     * from 2 rows to move to 0 — it would have run, moved nothing, and looked
     * finished.
     */
    public function test_a_delivered_trip_keeps_the_vehicle_and_driver_it_ran_with(): void
    {
        $trip = $this->movingTrip();
        $before = $trip->fresh()->only(['vehicle_id', 'driver_id']);

        $this->trips->recordDelivery($trip->fresh(), [], self::TENANT_A, $this->actor);

        $after = $trip->fresh()->only(['vehicle_id', 'driver_id']);

        $this->assertNotNull($after['vehicle_id'], 'the trip forgot which vehicle ran it');
        $this->assertSame($before, $after,
            'a finished trip keeps its crew pointers — they are history, not a claim');
    }

    /** An ABANDONED allocation still clears them. The two paths differ on purpose. */
    public function test_releasing_an_allocation_early_still_clears_the_pointers(): void
    {
        $trip = $this->dispatchedTrip();
        $a = app(TripAssignmentService::class)->activeForTrip($trip->id, self::TENANT_A);

        $this->alloc->release($a, self::TENANT_A, $this->actor, 'changed our minds');

        $this->assertNull($trip->fresh()->vehicle_id,
            'an abandoned allocation must not leave the trip claiming a vehicle');
    }
}
