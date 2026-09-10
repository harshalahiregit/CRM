<?php

namespace Tests\Feature\Transport;

use App\Events\Transport\TripAssigned;
use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripAssignment;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\AssignmentStatus;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-009 step 6 — the allocation act. STT-004 and EVT-005.
 *
 * The property under test is the ORDER of operations: eligibility must be
 * decided before anything is written, so a refusal leaves the trip in approved
 * with no assignment row and no resource moved. OPS §194: "No vehicle is
 * allocated without required eligibility."
 */
class TransportAllocationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private AllocationService $alloc;
    private TransportVehicleService $vehicleSvc;
    private TransportDriverService $driverSvc;
    private TransportDocumentService $docs;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha Transport', self::TENANT_B => 'Bravo Logistics'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }

        $this->alloc      = app(AllocationService::class);
        $this->vehicleSvc = app(TransportVehicleService::class);
        $this->driverSvc  = app(TransportDriverService::class);
        $this->docs       = app(TransportDocumentService::class);
        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Dispatcher', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'd-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /* ── fixtures ── */

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

    private function vehicle(int $tenantId = self::TENANT_A, ?float $capacity = 30): TransportVehicle
    {
        $v = $this->vehicleSvc->create([
            'registration_number' => 'MH12AB'.random_int(1000, 9999),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => $capacity,
        ], $tenantId, $this->actor);

        return $this->vehicleSvc->transitionTo($v, VehicleStatus::AVAILABLE, $tenantId, $this->actor);
    }

    private function driver(int $tenantId = self::TENANT_A): TransportDriver
    {
        return $this->driverSvc->create([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.random_int(100000, 999999),
            'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ], $tenantId, $this->actor);
    }

    /* ══════════ STT-004 — the happy path ══════════ */

    public function test_allocating_both_resources_moves_the_trip_to_allocated(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $result = $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::ALLOCATED, $result['trip']->status);
        $this->assertTrue($result['assignment']->isComplete());
        $this->assertSame(AssignmentStatus::ASSIGNED, $result['assignment']->status);
        // Resource states follow, so the masters stop advertising them.
        $this->assertSame(VehicleStatus::ALLOCATED, $v->fresh()->status);
        $this->assertSame(DriverAvailability::ASSIGNED, $d->fresh()->availability);
    }

    /** SM-TRP's entry gate is "Vehicle+driver eligible" — both, not either. */
    public function test_a_vehicle_only_allocation_leaves_the_trip_approved(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle();

        $result = $this->alloc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::APPROVED, $result['trip']->status, 'a trip is not allocated until it is crewed');
        $this->assertFalse($result['assignment']->isComplete());
        $this->assertSame(VehicleStatus::ALLOCATED, $v->fresh()->status, 'but the vehicle is still spoken for');
    }

    public function test_adding_the_driver_afterwards_completes_the_transition(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $this->alloc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);
        $result = $this->alloc->assign($trip->fresh(), null, $d->id, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::ALLOCATED, $result['trip']->status);
        // Still one assignment row — §47 keeps one history record per allocation.
        $this->assertSame(1, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    public function test_only_an_approved_trip_can_be_allocated(): void
    {
        $trip = $this->approvedTrip();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        $this->expectException(BusinessException::class);
        $this->alloc->assign($trip->fresh(), $this->vehicle()->id, null, self::TENANT_A, $this->actor);
    }

    public function test_an_allocation_with_neither_resource_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->alloc->assign($this->approvedTrip(), null, null, self::TENANT_A, $this->actor);
    }

    /* ══════════ Eligibility gates the act — OPS §194 ══════════ */

    public function test_an_ineligible_vehicle_blocks_and_leaves_nothing_behind(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle();
        // QA-003 — an expired document must block allocation.
        $this->docs->file($v, TransportDocumentType::INSURANCE,
            ['valid_until' => now()->subDay()->toDateString()], self::TENANT_A, $this->actor);

        try {
            $this->alloc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);
            $this->fail('an ineligible vehicle must be refused');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('Insurance', $e->getMessage(), 'the reason must be surfaced');
            $this->assertStringContainsString('Renew', $e->getMessage(), 'QA-003 requires an actionable message');
        }

        // Nothing written: eligibility runs before the assignment.
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(0, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
        $this->assertSame(VehicleStatus::AVAILABLE, $v->fresh()->status, 'the vehicle must not be marked allocated');
    }

    public function test_an_ineligible_driver_blocks_and_leaves_nothing_behind(): void
    {
        $trip = $this->approvedTrip();
        $d = $this->driverSvc->create([
            'name' => 'Lapsed', 'licence_number' => 'MH0199',
            'licence_valid_until' => now()->subDay()->toDateString(),
        ], self::TENANT_A, $this->actor);

        try {
            $this->alloc->assign($trip, null, $d->id, self::TENANT_A, $this->actor);
            $this->fail('an ineligible driver must be refused');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(0, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
        $this->assertSame(DriverAvailability::AVAILABLE, $d->fresh()->availability);
    }

    /** PLN-001 — the capacity check reaches the allocation act. */
    public function test_a_vehicle_too_small_for_the_order_is_refused(): void
    {
        $trip = $this->approvedTrip(capacity: 25);
        $small = $this->vehicle(capacity: 10);

        $this->expectException(BusinessException::class);
        $this->alloc->assign($trip, $small->id, null, self::TENANT_A, $this->actor);
    }

    /** BR-P0-003 reaches it too — via the eligibility check, before the index. */
    public function test_a_vehicle_already_on_another_trip_is_refused(): void
    {
        $v = $this->vehicle();
        $this->alloc->assign($this->approvedTrip(), $v->id, null, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->alloc->assign($this->approvedTrip(), $v->id, null, self::TENANT_A, $this->actor);
    }

    /**
     * If the driver fails, the vehicle must not be quietly allocated.
     * Both verdicts are decided before the transaction opens.
     */
    public function test_a_failing_driver_does_not_leave_the_vehicle_allocated(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle();
        $bad = $this->driverSvc->create(['name' => 'No Licence'], self::TENANT_A, $this->actor);

        try {
            $this->alloc->assign($trip, $v->id, $bad->id, self::TENANT_A, $this->actor);
            $this->fail('must be refused');
        } catch (BusinessException $e) {
            // expected
        }

        $this->assertSame(VehicleStatus::AVAILABLE, $v->fresh()->status);
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(0, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    /* ══════════ EVT-005 ══════════ */

    public function test_the_event_fires_once_with_exactly_the_registry_payload(): void
    {
        Event::fake([TripAssigned::class]);
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        Event::assertDispatchedTimes(TripAssigned::class, 1);
        Event::assertDispatched(TripAssigned::class, function (TripAssigned $e) use ($trip, $v, $d) {
            // EVT-005 Payload Core: trip_id, vehicle_id, driver_id. Exactly three.
            $this->assertSame(['trip_id', 'vehicle_id', 'driver_id'], array_keys($e->payload()));
            $this->assertSame($trip->id, $e->payload()['trip_id']);
            $this->assertSame($v->id, $e->payload()['vehicle_id']);
            $this->assertSame($d->id, $e->payload()['driver_id']);
            // Idempotency Key: "trip_id+assignment_id".
            $this->assertSame($trip->id.'+'.$e->assignment->id, $e->idempotencyKey());
            $this->assertSame(self::TENANT_A, $e->tenantId());

            return true;
        });
    }

    public function test_a_partial_allocation_fires_no_event(): void
    {
        Event::fake([TripAssigned::class]);

        $this->alloc->assign($this->approvedTrip(), $this->vehicle()->id, null, self::TENANT_A, $this->actor);

        Event::assertNotDispatched(TripAssigned::class);
    }

    public function test_the_event_fires_exactly_once_across_a_sequential_allocation(): void
    {
        Event::fake([TripAssigned::class]);
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $this->alloc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);
        $this->alloc->assign($trip->fresh(), null, $d->id, self::TENANT_A, $this->actor);

        Event::assertDispatchedTimes(TripAssigned::class, 1);
    }

    /* ══════════ Audit — the evidence that makes review possible ══════════ */

    public function test_the_allocation_is_audited_against_both_the_assignment_and_the_trip(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();

        $result = $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->assertDatabaseHas('transport_audit_logs', [
            'action' => 'transport.allocation.performed', 'auditable_id' => $result['assignment']->id,
        ]);
        $this->assertDatabaseHas('transport_audit_logs', [
            'action' => 'transport.trip.status_changed', 'auditable_id' => $trip->id,
        ]);
    }

    /** Step 9's philosophy: an allocation must be reviewable afterwards. */
    public function test_the_audit_row_carries_every_eligibility_check_that_passed(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();
        $result = $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $entry = TransportAuditLog::where('action', 'transport.allocation.performed')
            ->where('auditable_id', $result['assignment']->id)->latest('id')->first();

        $this->assertNotNull($entry);
        $checks = $entry->context['checks'];
        $this->assertArrayHasKey('vehicle', $checks);
        $this->assertArrayHasKey('driver', $checks);
        $this->assertCount(4, $checks['vehicle'], 'status, assignment, documents, capacity');
        $this->assertCount(5, $checks['driver'], 'lifecycle, availability, assignment, licence, documents');
        // Every check records what it verified, not only the failures.
        foreach ($checks['vehicle'] as $c) {
            $this->assertArrayHasKey('passed', $c);
            $this->assertArrayHasKey('detail', $c);
        }
        $this->assertSame('BR-P0-003', $entry->context['rule']);
    }

    public function test_the_trip_transition_records_stt_004(): void
    {
        $trip = $this->approvedTrip();
        $this->alloc->assign($trip, $this->vehicle()->id, $this->driver()->id, self::TENANT_A, $this->actor);

        $entry = TransportAuditLog::where('action', 'transport.trip.status_changed')
            ->where('auditable_id', $trip->id)->latest('id')->first();

        $this->assertSame('STT-004', $entry->context['transition']);
        $this->assertSame(TripStatus::APPROVED, $entry->old_values['status'] ?? $entry->old_values['from'] ?? null);
        $this->assertSame(TripStatus::ALLOCATED, $entry->new_values['status'] ?? $entry->new_values['to'] ?? null);
    }

    public function test_a_partial_allocation_is_recorded_on_the_trip_too(): void
    {
        $trip = $this->approvedTrip();
        $this->alloc->assign($trip, $this->vehicle()->id, null, self::TENANT_A, $this->actor);

        $this->assertDatabaseHas('transport_audit_logs', [
            'action' => 'transport.trip.partially_allocated', 'auditable_id' => $trip->id,
        ]);
    }

    /* ══════════ Release ══════════ */

    public function test_releasing_frees_everything_and_reverts_the_trip(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();
        $result = $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->alloc->release($result['assignment'], self::TENANT_A, $this->actor, 'wrong vehicle');

        $this->assertSame(AssignmentStatus::RELEASED, $result['assignment']->fresh()->status);
        $this->assertSame(VehicleStatus::AVAILABLE, $v->fresh()->status);
        $this->assertSame(DriverAvailability::AVAILABLE, $d->fresh()->availability);
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status, 'inferred revert — see TripStatus');
    }

    public function test_release_then_reallocate_works(): void
    {
        $trip = $this->approvedTrip(); $v1 = $this->vehicle(); $v2 = $this->vehicle(); $d = $this->driver();
        $first = $this->alloc->assign($trip, $v1->id, $d->id, self::TENANT_A, $this->actor);

        $this->alloc->release($first['assignment'], self::TENANT_A, $this->actor);
        $second = $this->alloc->assign($trip->fresh(), $v2->id, $d->id, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::ALLOCATED, $second['trip']->status);
        $this->assertSame($v2->id, (int) $second['assignment']->vehicle_id);
        // The released row survives as history.
        $this->assertSame(2, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
        $this->assertSame(VehicleStatus::AVAILABLE, $v1->fresh()->status, 'the first vehicle is free again');
    }

    /** A vehicle that broke down while allocated must not be marked Available. */
    public function test_release_does_not_overwrite_a_resource_state_it_did_not_set(): void
    {
        $trip = $this->approvedTrip(); $v = $this->vehicle(); $d = $this->driver();
        $result = $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);
        \Illuminate\Support\Facades\DB::table('transport_vehicles')->where('id', $v->id)->update(['status' => VehicleStatus::BREAKDOWN]);

        $this->alloc->release($result['assignment'], self::TENANT_A, $this->actor);

        $this->assertSame(VehicleStatus::BREAKDOWN, $v->fresh()->status);
    }

    /* ══════════ Candidates ══════════ */

    public function test_candidates_returns_only_eligible_resources_for_the_trip(): void
    {
        $trip = $this->approvedTrip(capacity: 20);
        $ok = $this->vehicle(capacity: 30);
        $tooSmall = $this->vehicle(capacity: 5);
        $d = $this->driver();

        $c = $this->alloc->candidates($trip, self::TENANT_A);

        $vehicleIds = collect($c['vehicles'])->pluck('subject.id');
        $this->assertTrue($vehicleIds->contains($ok->id));
        $this->assertFalse($vehicleIds->contains($tooSmall->id));
        $this->assertTrue(collect($c['drivers'])->pluck('subject.id')->contains($d->id));
    }

    /* ══════════ Tenancy ══════════ */

    public function test_another_tenants_trip_vehicle_or_driver_is_not_found(): void
    {
        $tripA = $this->approvedTrip(self::TENANT_A);
        $vA = $this->vehicle(self::TENANT_A);

        // Tenant B cannot allocate against tenant A's trip.
        try {
            $this->alloc->assign($tripA, $vA->id, null, self::TENANT_B, null);
            $this->fail('cross-tenant allocation must be refused');
        } catch (ResourceNotFoundException $e) {
            $this->assertTrue(true);
        }

        // Nor can tenant A allocate tenant B's vehicle.
        $vB = $this->vehicle(self::TENANT_B);
        $this->expectException(ResourceNotFoundException::class);
        $this->alloc->assign($tripA, $vB->id, null, self::TENANT_A, $this->actor);
    }

    public function test_a_tenant_cannot_release_another_tenants_assignment(): void
    {
        $result = $this->alloc->assign($this->approvedTrip(), $this->vehicle()->id, $this->driver()->id, self::TENANT_A, $this->actor);

        $this->expectException(ResourceNotFoundException::class);
        $this->alloc->release($result['assignment'], self::TENANT_B, null);
    }

    public function test_the_same_vehicle_can_be_allocated_in_two_tenants_at_once(): void
    {
        $vA = $this->vehicle(self::TENANT_A);
        $vB = $this->vehicle(self::TENANT_B);

        $this->alloc->assign($this->approvedTrip(self::TENANT_A), $vA->id, $this->driver(self::TENANT_A)->id, self::TENANT_A, $this->actor);
        $r = $this->alloc->assign($this->approvedTrip(self::TENANT_B), $vB->id, $this->driver(self::TENANT_B)->id, self::TENANT_B, null);

        $this->assertSame(TripStatus::ALLOCATED, $r['trip']->status);
    }

    /* ══════════ The state machine stays narrow ══════════ */

    public function test_allocation_never_wired_a_transition_past_its_own(): void
    {
        // Updated 2026-09-09: SNG-TRN-010 added allocated → pretrip_ok and its
        // release reverse. What this test guards is unchanged and is the part
        // that matters — ALLOCATION itself must never reach past `allocated`,
        // and `dispatched` must stay unreachable from anywhere, because dispatch
        // confirmation belongs to no ticket in the register (D-18).
        $this->assertSame(
            [TripStatus::DRAFT, TripStatus::APPROVED, TripStatus::ALLOCATED, TripStatus::PRETRIP_OK],
            array_keys(TripStatus::TRANSITIONS)
        );

        // ALLOCATION must never reach past `allocated` — that is what this
        // guards, and it is unchanged. pretrip_ok -> dispatched became live on
        // 2026-09-10 but belongs to DispatchService, not here.
        $this->assertFalse(TripStatus::canTransition(TripStatus::ALLOCATED, TripStatus::DISPATCHED));
        $this->assertFalse(TripStatus::canTransition(TripStatus::DISPATCHED, TripStatus::IN_TRANSIT));

        // Both reverses are inferred and must keep saying so.
        $this->assertSame(
            ['allocated->approved', 'pretrip_ok->approved'],
            TripStatus::INFERRED_TRANSITIONS,
        );
    }
}
