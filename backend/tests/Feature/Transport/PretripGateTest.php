<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
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
use App\Services\Transport\TransportPolicyService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
use App\Support\Transport\PretripScope;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TripStatus;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Domains\Fleet\Models\DriverProfile;
use App\Domains\Fleet\Models\Vehicle;
use Tests\Concerns\CreatesFleetResources;
use Tests\TestCase;

/**
 * SNG-TRN-010 step 6 — the gate, the transition, and crew-release invalidation.
 *
 * STT-005's precondition work, under the Q1 ruling of 2026-09-09: Step 9's
 * allocated → pretrip_ok. The gate itself is BRW-046, BRW-052, OPS §30 and
 * CMP §159, all four of which say the same thing — a critical failure blocks
 * dispatch, and the reason must be exact.
 */
class PretripGateTest extends TestCase
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
    private TransportPolicyService $policies;
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

        $this->pretrip    = app(PretripService::class);
        $this->alloc      = app(AllocationService::class);
        $this->vehicleSvc = app(TransportVehicleService::class);
        $this->driverSvc  = app(TransportDriverService::class);
        $this->docs       = app(TransportDocumentService::class);
        $this->policies   = app(TransportPolicyService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Supervisor', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 's-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /* ── fixtures ── */

    private function approvedTrip(int $tenantId = self::TENANT_A): TransportTrip
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

        return $trip->fresh();
    }

    private function vehicle(int $tenantId = self::TENANT_A): Vehicle
    {
        $v = $this->fleetVehicle([
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => 30,
        ], $tenantId, $this->actor);

        return $this->moveFleetVehicle($v, Vehicle::STATUS_AVAILABLE);
    }

    private function driver(int $tenantId = self::TENANT_A): DriverProfile
    {
        return $this->fleetDriver([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_expiry' => now()->addYears(2)->toDateString(),
        ], $tenantId, $this->actor);
    }

    /** Allocated, checklist generated, every item confirmed — READY. */
    private function readyTrip(int $tenantId = self::TENANT_A): array
    {
        $trip = $this->approvedTrip($tenantId);
        $v = $this->vehicle($tenantId);
        $d = $this->driver($tenantId);
        $this->alloc->assign($trip, $v->id, $d->id, $tenantId, $this->actor);
        $trip = $trip->fresh();

        $this->pretrip->generate($trip, $tenantId, $this->actor);
        foreach ($this->pretrip->checksFor($trip, $tenantId) as $check) {
            $this->pretrip->complete($check, $tenantId, $this->actor);
        }

        return [$trip->fresh(), $v->fresh(), $d->fresh()];
    }

    private function assignmentFor(TransportTrip $trip, int $tenantId = self::TENANT_A): TripAssignment
    {
        return TripAssignment::forTenant($tenantId)->forTrip($trip->id)->active()->firstOrFail();
    }

    /* ══════════ the edge is wired, and only that edge ══════════ */

    public function test_the_owned_edge_is_now_live(): void
    {
        $this->assertTrue(TripStatus::canTransition(TripStatus::ALLOCATED, TripStatus::PRETRIP_OK));
        $this->assertSame(PretripScope::STATE_EDGE_OWNED, TripStatus::ALLOCATED.'->'.TripStatus::PRETRIP_OK);
    }

    public function test_the_next_edge_is_dispatch_not_transit(): void
    {
        // Updated 2026-09-10: pretrip_ok -> dispatched is now wired (authorised
        // directly by the owner; see DispatchScope).
        //
        // Updated again 2026-09-17: dispatched -> in_transit is wired too. The
        // previous version of this test said that edge was "blocked on
        // SNG-TRN-013" — it never was. The owner had authorised it a week
        // earlier and its columns had shipped. See D-105.
        //
        // What this test guards has not changed: pre-trip releases a trip, and
        // it does NOT put one on the road. Those are two acts and two services.
        $this->assertTrue(TripStatus::canTransition(TripStatus::PRETRIP_OK, TripStatus::DISPATCHED));
        $this->assertFalse(TripStatus::canTransition(TripStatus::PRETRIP_OK, TripStatus::IN_TRANSIT));
    }

    public function test_the_release_reverse_edge_is_declared_as_inferred(): void
    {
        $this->assertTrue(TripStatus::canTransition(TripStatus::PRETRIP_OK, TripStatus::APPROVED));
        $this->assertContains(
            TripStatus::PRETRIP_OK.'->'.TripStatus::APPROVED,
            TripStatus::INFERRED_TRANSITIONS,
            'a transition with no registry row must say so',
        );
    }

    public function test_allocation_still_only_reaches_allocated(): void
    {
        // Wiring a second edge out of `allocated` must not let assign() skip it.
        $trip = $this->approvedTrip();
        $v = $this->vehicle(); $d = $this->driver();
        $result = $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::ALLOCATED, $result['trip']->status);
    }

    /* ══════════ the gate — READY allows ══════════ */

    public function test_a_ready_checklist_moves_the_trip_to_pretrip_ok(): void
    {
        [$trip] = $this->readyTrip();

        $this->assertSame(PretripReadiness::READY, $this->pretrip->readiness($trip, self::TENANT_A)['status']);

        $moved = $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::PRETRIP_OK, $moved->status);
    }

    public function test_passing_does_not_dispatch_the_trip(): void
    {
        [$trip] = $this->readyTrip();
        $moved = $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $this->assertNotSame(TripStatus::DISPATCHED, $moved->status);
    }

    public function test_the_resources_stay_held_after_passing(): void
    {
        [$trip, $vehicle, $driver] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $this->assertSame(Vehicle::STATUS_ALLOCATED, $vehicle->fresh()->status);
        $this->assertSame(DriverProfile::ON_TRIP, $driver->fresh()->status);
        $this->assertNotNull($this->assignmentFor($trip));
    }

    /* ══════════ the gate — BLOCKED and IN_PROGRESS refuse ══════════ */

    public function test_a_blocked_checklist_refuses_with_the_exact_reason(): void
    {
        // BRW-048's own example is "Dispatch blocked — Driver licence expired."
        [$trip, , $driver] = $this->readyTrip();
        $driver->forceFill(['licence_expiry' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
            $this->fail('a blocked checklist must refuse the transition');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('Dispatch blocked', $e->getMessage());
            $this->assertStringContainsString('Driver documents valid', $e->getMessage());
            $this->assertStringContainsString('Resolve the failed checks', $e->getMessage());
        }

        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
    }

    public function test_an_incomplete_checklist_refuses_and_names_what_is_left(): void
    {
        $trip = $this->approvedTrip();
        $v = $this->vehicle(); $d = $this->driver();
        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);
        $trip = $trip->fresh();

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        // Confirm only the first item.
        $this->pretrip->complete($this->pretrip->checksFor($trip, self::TENANT_A)[0], self::TENANT_A, $this->actor);

        try {
            $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);
            $this->fail('an unfinished checklist must refuse the transition');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('not complete', $e->getMessage());
            $this->assertStringContainsString('Still to confirm', $e->getMessage());
            $this->assertStringContainsString('Driver assigned', $e->getMessage());
        }

        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
    }

    public function test_a_trip_with_no_checklist_at_all_refuses(): void
    {
        $trip = $this->approvedTrip();
        $v = $this->vehicle(); $d = $this->driver();
        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
            $this->fail('no checklist means no readiness');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('have not been run', $e->getMessage());
            $this->assertStringContainsString('Generate the checklist', $e->getMessage());
        }
    }

    public function test_a_non_critical_failure_does_not_stop_the_gate(): void
    {
        // BRW-052: "Non-critical warning: Continue with warning."
        $this->policies->set(self::TENANT_A, 'pretrip.check.driver.documents_valid.critical', false, $this->actor);

        $trip = $this->approvedTrip();
        $v = $this->vehicle(); $d = $this->driver();
        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);
        $trip = $trip->fresh();
        $d->forceFill(['licence_expiry' => now()->subDay()])->save();

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $check) {
            $this->pretrip->complete($check, self::TENANT_A, $this->actor);
        }

        $moved = $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::PRETRIP_OK, $moved->status);
    }

    /* ══════════ the gate — wrong from-state ══════════ */

    public function test_an_approved_trip_cannot_pass_pretrip(): void
    {
        $trip = $this->approvedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $check) {
            // Nothing can be completed while every check fails, but complete what can be.
            if (! $check->isPending()) {
                $this->pretrip->complete($check, self::TENANT_A, $this->actor);
            }
        }

        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
            $this->fail('only an allocated trip may pass pre-trip');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('Approved', $e->getMessage());
            $this->assertStringContainsString('allocated first', $e->getMessage());
        }
    }

    public function test_a_draft_trip_cannot_pass_pretrip(): void
    {
        $trip = $this->approvedTrip();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        $this->expectException(BusinessException::class);
        $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
    }

    public function test_passing_twice_is_refused_rather_than_re_audited(): void
    {
        [$trip] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $before = $trip->auditTrail()->count();

        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
            $this->fail('a second pass must be refused');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('already passed', $e->getMessage());
        }

        $this->assertSame($before, $trip->fresh()->auditTrail()->count(), 'nothing may re-audit a transition that already happened');
    }

    /* ══════════ audit — STT-005's "Audit Yes" ══════════ */

    public function test_the_transition_is_audited_with_every_check_that_was_ready(): void
    {
        [$trip] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()
            ->where('action', 'transport.trip.status_changed')
            ->get()
            ->first(fn ($e) => ($e->new_values['status'] ?? null) === TripStatus::PRETRIP_OK);

        $this->assertNotNull($entry, 'the transition must be audited');
        $this->assertSame(['status' => TripStatus::ALLOCATED], $entry->old_values);
        $this->assertSame(['status' => TripStatus::PRETRIP_OK], $entry->new_values);
        $this->assertSame($this->actor->id, $entry->actor_id);

        $ctx = $entry->context;
        $this->assertSame(PretripScope::BRW_DISPATCH_READINESS, $ctx['rule']);
        $this->assertSame(PretripScope::STATE_EDGE_OWNED, $ctx['transition']);
        $this->assertStringContainsString('STT-005', $ctx['registry']);
        $this->assertStringContainsString('BRW-046', $ctx['sources']);
        $this->assertStringContainsString('CMP §159', $ctx['sources']);
        $this->assertSame(PretripReadiness::READY, $ctx['readiness']);

        // Evidence preservation: every check, its result, and who confirmed it.
        $this->assertCount(5, $ctx['checks']);
        foreach ($ctx['checks'] as $check) {
            $this->assertSame(PretripResult::PASS, $check['result']);
            $this->assertSame($this->actor->id, $check['completed_by']);
            $this->assertNotNull($check['completed_at']);
        }
    }

    public function test_a_refusal_is_audited_and_survives_the_exception(): void
    {
        // Same discipline as ticket 009's refused allocation: a block is exactly
        // the event an auditor asks about later.
        [$trip, , $driver] = $this->readyTrip();
        $driver->forceFill(['licence_expiry' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
        } catch (BusinessException) {
        }

        $entry = $trip->auditTrail()->where('action', 'transport.pretrip.refused')->first();

        $this->assertNotNull($entry, 'the refusal must leave evidence');
        $this->assertSame(PretripReadiness::BLOCKED, $entry->context['readiness']);
        $this->assertNotEmpty($entry->context['blockers']);
        $this->assertCount(5, $entry->context['checks']);
        $this->assertStringContainsString('OPS §30', $entry->context['sources']);
    }

    public function test_a_refusal_changes_no_state(): void
    {
        [$trip, $vehicle, $driver] = $this->readyTrip();
        $driver->forceFill(['licence_expiry' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
        } catch (BusinessException) {
        }

        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
        $this->assertSame(Vehicle::STATUS_ALLOCATED, $vehicle->fresh()->status);
        $this->assertSame(DriverProfile::ON_TRIP, $driver->fresh()->status);
    }

    /* ══════════ crew release — while the checklist is in progress ══════════ */

    public function test_releasing_mid_checklist_invalidates_every_row(): void
    {
        $trip = $this->approvedTrip();
        $v = $this->vehicle(); $d = $this->driver();
        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);
        $trip = $trip->fresh();

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $this->pretrip->complete($this->pretrip->checksFor($trip, self::TENANT_A)[0], self::TENANT_A, $this->actor);

        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor, 'Breakdown');

        $checks = $this->pretrip->checksFor($trip->fresh(), self::TENANT_A);

        $this->assertCount(5, $checks, 'the rows stay — deleting them would orphan their audit entries');
        foreach ($checks as $check) {
            $this->assertSame(PretripResult::PENDING, $check->result);
            $this->assertFalse($check->isCompleted());
            $this->assertNull($check->evaluated_at);
        }
    }

    public function test_an_invalidated_checklist_reads_not_started(): void
    {
        [$trip] = $this->readyTrip();
        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $this->assertSame(
            PretripReadiness::NOT_STARTED,
            $this->pretrip->readiness($trip->fresh(), self::TENANT_A)['status'],
        );
    }

    public function test_invalidation_keeps_what_a_person_wrote(): void
    {
        [$trip] = $this->readyTrip();
        $check = $this->pretrip->checksFor($trip, self::TENANT_A)->firstWhere('check_key', PretripCheckKey::VEHICLE_ASSIGNED);
        $this->pretrip->complete($check, self::TENANT_A, $this->actor, 'Walked round the vehicle');

        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $after = $this->pretrip->checksFor($trip->fresh(), self::TENANT_A)
            ->firstWhere('check_key', PretripCheckKey::VEHICLE_ASSIGNED);

        $this->assertSame('Walked round the vehicle', $after->remarks);
        $this->assertFalse($after->isCompleted());
    }

    public function test_invalidation_explains_itself_on_every_row(): void
    {
        [$trip] = $this->readyTrip();
        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor, 'Vehicle broke down');

        foreach ($this->pretrip->checksFor($trip->fresh(), self::TENANT_A) as $check) {
            $this->assertStringContainsString('released', $check->detail);
            $this->assertStringContainsString('Vehicle broke down', $check->detail);
            $this->assertStringContainsString('Re-run the checklist', $check->detail);
        }
    }

    public function test_invalidation_is_audited_with_what_it_threw_away(): void
    {
        [$trip] = $this->readyTrip();
        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.pretrip.invalidated')->first();

        $this->assertNotNull($entry);
        $this->assertSame(PretripReadiness::READY, $entry->context['was'], 'the audit must record the state that was discarded');
        $this->assertCount(5, $entry->context['checks']);
    }

    public function test_releasing_a_trip_with_no_checklist_is_harmless(): void
    {
        $trip = $this->approvedTrip();
        $v = $this->vehicle(); $d = $this->driver();
        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(0, TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    /* ══════════ crew release — after the trip reached pretrip_ok ══════════ */

    public function test_releasing_from_pretrip_ok_reverts_the_trip_to_approved(): void
    {
        // OPS §27 and BRWM's "Vehicle unavailable → Reallocation": a breakdown
        // does not ask permission, so release is accommodated, never refused.
        [$trip] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::PRETRIP_OK, $trip->fresh()->status);

        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor, 'Engine failure');

        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
    }

    public function test_releasing_from_pretrip_ok_frees_the_resources(): void
    {
        [$trip, $vehicle, $driver] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicle->fresh()->status);
        $this->assertSame(DriverProfile::AVAILABLE, $driver->fresh()->status);
    }

    public function test_releasing_from_pretrip_ok_invalidates_the_passed_checklist(): void
    {
        [$trip] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);

        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $this->assertSame(
            PretripReadiness::NOT_STARTED,
            $this->pretrip->readiness($trip->fresh(), self::TENANT_A)['status'],
        );
    }

    public function test_the_reverse_transition_from_pretrip_ok_is_audited(): void
    {
        [$trip] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);
        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()
            ->where('action', 'transport.trip.status_changed')
            ->get()
            ->first(fn ($e) => ($e->old_values['status'] ?? null) === TripStatus::PRETRIP_OK);

        $this->assertNotNull($entry);
        $this->assertSame(['status' => TripStatus::APPROVED], $entry->new_values);
        $this->assertStringContainsString('inferred', $entry->context['transition']);
    }

    /* ══════════ re-allocation after release ══════════ */

    public function test_a_re_crewed_trip_must_earn_its_checklist_again(): void
    {
        [$trip] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);
        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        // A brand-new crew.
        $v2 = $this->vehicle(); $d2 = $this->driver();
        $this->alloc->assign($trip->fresh(), $v2->id, $d2->id, self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);

        // The old confirmations must not carry over.
        try {
            $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
            $this->fail('a re-crewed trip must not inherit the previous crew confirmations');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('have not been run', $e->getMessage());
        }
    }

    public function test_regeneration_after_re_allocation_reflects_the_new_crew(): void
    {
        [$trip] = $this->readyTrip();
        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $v2 = $this->vehicle(); $d2 = $this->driver();
        $this->alloc->assign($trip->fresh(), $v2->id, $d2->id, self::TENANT_A, $this->actor);

        $checks = $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);

        $this->assertCount(5, $checks);
        foreach ($checks as $check) {
            $this->assertTrue($check->satisfied(), $check->check_key.': '.$check->detail);
            $this->assertFalse($check->isCompleted(), 'a fresh evaluation is not a confirmation');
        }
        $this->assertStringContainsString($v2->displayName(), $checks->firstWhere('check_key', PretripCheckKey::VEHICLE_ASSIGNED)->detail);
        $this->assertStringContainsString($d2->displayName(), $checks->firstWhere('check_key', PretripCheckKey::DRIVER_ASSIGNED)->detail);
    }

    public function test_the_full_release_recrew_repass_cycle_works(): void
    {
        [$trip] = $this->readyTrip();
        $this->pretrip->passPretrip($trip, self::TENANT_A, $this->actor);
        $this->alloc->release($this->assignmentFor($trip), self::TENANT_A, $this->actor);

        $v2 = $this->vehicle(); $d2 = $this->driver();
        $this->alloc->assign($trip->fresh(), $v2->id, $d2->id, self::TENANT_A, $this->actor);

        $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);
        foreach ($this->pretrip->checksFor($trip->fresh(), self::TENANT_A) as $check) {
            $this->pretrip->complete($check, self::TENANT_A, $this->actor);
        }

        $moved = $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
        $this->assertSame(TripStatus::PRETRIP_OK, $moved->status);
    }

    /* ══════════ tenancy ══════════ */

    public function test_the_gate_cannot_be_passed_from_another_tenant(): void
    {
        [$tripA] = $this->readyTrip(self::TENANT_A);

        // Tenant B sees no checks for this trip, so the readiness it computes is
        // NOT_STARTED and the gate refuses. It must never read A's rows.
        try {
            $this->pretrip->passPretrip($tripA, self::TENANT_B, $this->actor);
            $this->fail('another tenant must not be able to pass this gate');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('have not been run', $e->getMessage());
        }

        $this->assertSame(TripStatus::ALLOCATED, $tripA->fresh()->status);
    }

    public function test_invalidation_stays_inside_its_tenant(): void
    {
        [$tripA] = $this->readyTrip(self::TENANT_A);
        [$tripB] = $this->readyTrip(self::TENANT_B);

        $this->alloc->release($this->assignmentFor($tripA, self::TENANT_A), self::TENANT_A, $this->actor);

        $this->assertSame(
            PretripReadiness::NOT_STARTED,
            $this->pretrip->readiness($tripA->fresh(), self::TENANT_A)['status'],
        );
        $this->assertSame(
            PretripReadiness::READY,
            $this->pretrip->readiness($tripB->fresh(), self::TENANT_B)['status'],
            "tenant B's checklist must be untouched",
        );
    }

    public function test_two_tenants_pass_their_own_gates_independently(): void
    {
        [$tripA] = $this->readyTrip(self::TENANT_A);
        [$tripB] = $this->readyTrip(self::TENANT_B);

        $this->pretrip->passPretrip($tripA, self::TENANT_A, $this->actor);

        $this->assertSame(TripStatus::PRETRIP_OK, $tripA->fresh()->status);
        $this->assertSame(TripStatus::ALLOCATED, $tripB->fresh()->status);
    }

    /* ══════════ a document expiring is caught by regeneration ══════════ */

    public function test_a_document_expiring_before_the_gate_blocks_it(): void
    {
        // FLEET §16 — Eligible is not Ready. The interval between allocation and
        // departure is exactly where a policy lapses.
        [$trip, $vehicle] = $this->readyTrip();

        $this->docs->file($vehicle, TransportDocumentType::INSURANCE, [
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ], self::TENANT_A, $this->actor);

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->pretrip->passPretrip($trip->fresh(), self::TENANT_A, $this->actor);
    }
}
