<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\Transport\TripPretripCheck;
use App\Models\User;
use App\Services\Transport\AllocationService;
use App\Services\Transport\PretripService;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportPolicyService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\OrderStatus;
use App\Support\Transport\PretripCheckKey;
use App\Support\Transport\PretripReadiness;
use App\Support\Transport\PretripResult;
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
 * SNG-TRN-010 steps 4 and 5 — generation, evaluation and completion.
 *
 * RTM OPS-004 ("Missing requirements identified"), OPS-005 ("Mandatory gaps
 * block/flag dispatch"), CMP-006 ("Dispatch control"), BRW-046/047/048/052,
 * OPS §28/§29/§30, and the ticket's own acceptance criterion — completion is
 * time and user stamped.
 */
class PretripGenerationTest extends TestCase
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

    private function approvedTrip(int $tenantId = self::TENANT_A, bool $approveOrder = true, string $serviceType = 'Container Haulage'): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => $serviceType,
        ]);
        if ($approveOrder) {
            $order->forceFill(['order_status' => OrderStatus::APPROVED])->save();
        }

        $trip = TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::APPROVED])->save();

        return $trip->fresh();
    }

    private function vehicle(int $tenantId = self::TENANT_A, string $type = 'Trailer 40ft'): Vehicle
    {
        $v = $this->fleetVehicle([
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'vehicle_type' => $type, 'capacity_tonnes' => 30,
        ], $tenantId, $this->actor);

        return $this->moveFleetVehicle($v, Vehicle::STATUS_AVAILABLE);
    }

    private function driver(int $tenantId = self::TENANT_A, ?string $licenceUntil = null): DriverProfile
    {
        return $this->fleetDriver([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_valid_until' => $licenceUntil ?? now()->addYears(2)->toDateString(),
        ], $tenantId, $this->actor);
    }

    /** An approved, fully crewed trip — the happy path everything else varies from. */
    private function crewedTrip(int $tenantId = self::TENANT_A): array
    {
        $trip = $this->approvedTrip($tenantId);
        $v = $this->vehicle($tenantId);
        $d = $this->driver($tenantId);
        $this->alloc->assign($trip, $v->id, $d->id, $tenantId, $this->actor);

        return [$trip->fresh(), $v->fresh(), $d->fresh()];
    }

    private function keyed(TransportTrip $trip, int $tenantId = self::TENANT_A): \Illuminate\Support\Collection
    {
        return $this->pretrip->checksFor($trip, $tenantId)->keyBy('check_key');
    }

    /* ══════════ Step 4 · generation ══════════ */

    public function test_generation_creates_one_row_per_enabled_check(): void
    {
        [$trip] = $this->crewedTrip();

        $checks = $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertCount(5, $checks);
        $this->assertSame(PretripCheckKey::GENERATED, $checks->pluck('check_key')->all());
    }

    public function test_generation_is_idempotent(): void
    {
        [$trip] = $this->crewedTrip();

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertSame(5, TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    public function test_checks_come_back_in_the_document_order(): void
    {
        // OPS §28's order is Commercial, Driver, Vehicle — not alphabetical.
        [$trip] = $this->crewedTrip();
        $checks = $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertSame([
            'commercial.order_approved',
            'driver.assigned',
            'driver.documents_valid',
            'vehicle.assigned',
            'vehicle.compliance_valid',
        ], $checks->pluck('check_key')->all());
    }

    public function test_a_fully_ready_trip_passes_every_check(): void
    {
        [$trip] = $this->crewedTrip();

        $checks = $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        foreach ($checks as $check) {
            $this->assertTrue($check->satisfied(), $check->check_key.' should pass: '.$check->detail);
            $this->assertFalse($check->blocks());
        }
    }

    public function test_rows_start_pending_completion_even_when_they_pass(): void
    {
        // The AC needs a human stamp; a passing evaluation is not a completion.
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $readiness = $this->pretrip->readiness($trip, self::TENANT_A);

        $this->assertSame(PretripReadiness::IN_PROGRESS, $readiness['status']);
        $this->assertSame(0, $readiness['completed']);
        $this->assertSame(5, $readiness['total']);
    }

    /* ══════════ Step 4 · OPS-004, "missing requirements identified" ══════════ */

    public function test_an_uncrewed_trip_names_the_missing_driver_and_vehicle(): void
    {
        $trip = $this->approvedTrip();

        $checks = $this->keyed($trip)->isEmpty()
            ? $this->pretrip->generate($trip, self::TENANT_A, $this->actor)->keyBy('check_key')
            : $this->keyed($trip);

        $this->assertSame(PretripResult::CRITICAL_FAIL, $checks[PretripCheckKey::DRIVER_ASSIGNED]->result);
        $this->assertStringContainsString('Allocate a driver', $checks[PretripCheckKey::DRIVER_ASSIGNED]->detail);
        $this->assertSame(PretripResult::CRITICAL_FAIL, $checks[PretripCheckKey::VEHICLE_ASSIGNED]->result);
        $this->assertStringContainsString('Allocate a vehicle', $checks[PretripCheckKey::VEHICLE_ASSIGNED]->detail);
    }

    public function test_an_unapproved_order_blocks_and_names_its_status(): void
    {
        $trip = $this->approvedTrip(approveOrder: false);
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $check = $this->keyed($trip)[PretripCheckKey::ORDER_APPROVED];

        $this->assertSame(PretripResult::CRITICAL_FAIL, $check->result);
        $this->assertStringContainsString('Draft', $check->detail);
        $this->assertStringContainsString('must be approved', $check->detail);
    }

    public function test_an_expired_driver_licence_blocks_dispatch(): void
    {
        // BR-P0-004, QA-003, CMP §182: Expiry → Non-Compliant → Dispatch Block.
        [$trip, , $driver] = $this->crewedTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $check = $this->keyed($trip)[PretripCheckKey::DRIVER_DOCUMENTS];

        $this->assertSame(PretripResult::CRITICAL_FAIL, $check->result);
        $this->assertTrue($check->blocks());
        $this->assertSame(PretripReadiness::BLOCKED, $this->pretrip->readiness($trip, self::TENANT_A)['status']);
    }

    public function test_an_expired_vehicle_document_blocks_dispatch(): void
    {
        // RTM CMP-006, "Dispatch control".
        [$trip, $vehicle] = $this->crewedTrip();
        $this->docs->file($vehicle, TransportDocumentType::INSURANCE, [
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->subDay()->toDateString(),
        ], self::TENANT_A, $this->actor);

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $check = $this->keyed($trip)[PretripCheckKey::VEHICLE_COMPLIANCE];

        $this->assertSame(PretripResult::CRITICAL_FAIL, $check->result);
        $this->assertStringContainsString('Insurance', $check->detail);
    }

    public function test_the_blocked_reason_is_actionable(): void
    {
        // BRW-048: "If dispatch fails, Sangoe must display exact reason."
        [$trip, , $driver] = $this->crewedTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $blockers = $this->pretrip->readiness($trip, self::TENANT_A)['blockers'];

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('Driver documents valid', $blockers[0]);
        $this->assertStringContainsString($driver->displayName(), $blockers[0]);
    }

    /* ══════════ Step 4 · BRW-052, critical vs non-critical ══════════ */

    public function test_a_relaxed_check_fails_without_blocking(): void
    {
        // "Non-critical warning: Continue with warning/approval depending on policy."
        $this->policies->set(self::TENANT_A, 'pretrip.check.driver.documents_valid.critical', false, $this->actor);

        [$trip, , $driver] = $this->crewedTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $check = $this->keyed($trip)[PretripCheckKey::DRIVER_DOCUMENTS];

        $this->assertSame(PretripResult::FAIL, $check->result);
        $this->assertFalse($check->blocks());
        $this->assertSame([], $this->pretrip->readiness($trip, self::TENANT_A)['blockers']);
        $this->assertNotEmpty($this->pretrip->readiness($trip, self::TENANT_A)['warnings']);
    }

    public function test_the_criticality_used_is_stored_on_the_row(): void
    {
        // A later policy change must not silently rewrite a completed checklist.
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertTrue($this->keyed($trip)[PretripCheckKey::DRIVER_ASSIGNED]->is_critical);

        $this->policies->set(self::TENANT_A, 'pretrip.check.driver.assigned.critical', false, $this->actor);

        // Still true on the stored row until something regenerates it.
        $this->assertTrue($this->keyed($trip)[PretripCheckKey::DRIVER_ASSIGNED]->is_critical);
    }

    public function test_an_expiring_document_warns_but_does_not_block(): void
    {
        // UX §36 and FLEET §13 — valid today, lapsing inside the window.
        [$trip, $vehicle] = $this->crewedTrip();
        $this->docs->file($vehicle, TransportDocumentType::INSURANCE, [
            'valid_from' => now()->subYear()->toDateString(),
            'valid_until' => now()->addDays(10)->toDateString(),
        ], self::TENANT_A, $this->actor);

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $check = $this->keyed($trip)[PretripCheckKey::VEHICLE_COMPLIANCE];

        $this->assertSame(PretripResult::PASS_WARNING, $check->result);
        $this->assertFalse($check->blocks());
        $this->assertTrue($check->satisfied());
        $this->assertStringContainsString('expiring soon', $check->detail);
    }

    /* ══════════ Step 4 · policy-driven membership ══════════ */

    public function test_a_disabled_check_is_not_generated(): void
    {
        $this->policies->set(self::TENANT_A, 'pretrip.checks.enabled', [
            PretripCheckKey::ORDER_APPROVED, PretripCheckKey::VEHICLE_ASSIGNED,
        ], $this->actor);

        [$trip] = $this->crewedTrip();
        $checks = $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertCount(2, $checks);
    }

    public function test_disabling_a_check_after_generation_removes_its_row(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $this->assertCount(5, $this->keyed($trip));

        $this->policies->set(self::TENANT_A, 'pretrip.checks.enabled', [PretripCheckKey::ORDER_APPROVED], $this->actor);
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertCount(1, $this->keyed($trip));
    }

    public function test_a_vehicle_type_restriction_shortens_the_checklist(): void
    {
        // FRS TRP-P0-005 — "Mandatory checklist by vehicle/service type."
        $this->policies->set(self::TENANT_A, 'pretrip.checks.vehicle_types', [
            PretripCheckKey::VEHICLE_COMPLIANCE => ['Reefer 20ft'],
        ], $this->actor);

        $trip = $this->approvedTrip();
        $v = $this->vehicle(type: 'Flatbed');
        $d = $this->driver();
        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $checks = $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);

        $this->assertCount(4, $checks);
        $this->assertNotContains(PretripCheckKey::VEHICLE_COMPLIANCE, $checks->pluck('check_key')->all());
    }

    public function test_a_service_type_restriction_is_read_from_the_order(): void
    {
        $this->policies->set(self::TENANT_A, 'pretrip.checks.service_types', [
            PretripCheckKey::DRIVER_DOCUMENTS => ['Cold Chain'],
        ], $this->actor);

        $trip = $this->approvedTrip(serviceType: 'Container Haulage');
        $v = $this->vehicle(); $d = $this->driver();
        $this->alloc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $checks = $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);
        $this->assertNotContains(PretripCheckKey::DRIVER_DOCUMENTS, $checks->pluck('check_key')->all());
    }

    /* ══════════ Step 4 · the generatable states ══════════ */

    public function test_an_approved_trip_may_be_prepared_before_it_is_crewed(): void
    {
        // Otherwise OPS-004's "missing requirements identified" is unreachable.
        $trip = $this->approvedTrip();

        $checks = $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $this->assertCount(5, $checks);
    }

    public function test_a_draft_trip_cannot_be_prepared(): void
    {
        $trip = $this->approvedTrip();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        try {
            $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);
            $this->fail('a draft trip has nothing to be ready for');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('approved or allocated', $e->getMessage());
        }
    }

    public function test_a_refused_generation_leaves_nothing_behind(): void
    {
        $trip = $this->approvedTrip();
        $trip->forceFill(['status' => TripStatus::DRAFT])->save();

        try {
            $this->pretrip->generate($trip->fresh(), self::TENANT_A, $this->actor);
        } catch (BusinessException) {
        }

        $this->assertSame(0, TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    /* ══════════ Step 4 · re-evaluation and the human stamp ══════════ */

    public function test_re_evaluation_keeps_a_completion_when_the_result_is_unchanged(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $check = $this->keyed($trip)[PretripCheckKey::ORDER_APPROVED];
        $this->pretrip->complete($check, self::TENANT_A, $this->actor);

        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->assertTrue($this->keyed($trip)[PretripCheckKey::ORDER_APPROVED]->isCompleted());
    }

    public function test_re_evaluation_clears_a_completion_when_the_result_changes(): void
    {
        // The stamp vouched for a fact that no longer holds.
        [$trip, , $driver] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $check = $this->keyed($trip)[PretripCheckKey::DRIVER_DOCUMENTS];
        $this->pretrip->complete($check, self::TENANT_A, $this->actor, 'Checked the licence myself');
        $this->assertTrue($this->keyed($trip)[PretripCheckKey::DRIVER_DOCUMENTS]->isCompleted());

        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $refreshed = $this->keyed($trip)[PretripCheckKey::DRIVER_DOCUMENTS];
        $this->assertFalse($refreshed->isCompleted(), 'the stamp must not survive the fact changing');
        $this->assertSame(PretripResult::CRITICAL_FAIL, $refreshed->result);
        // The person's own note is theirs, and is kept.
        $this->assertSame('Checked the licence myself', $refreshed->remarks);
    }

    /* ══════════ Step 5 · completion, the acceptance criterion ══════════ */

    public function test_completion_is_time_and_user_stamped(): void
    {
        // "Checklist completion is time/user stamped." The whole AC.
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $check = $this->keyed($trip)[PretripCheckKey::ORDER_APPROVED];
        $done = $this->pretrip->complete($check, self::TENANT_A, $this->actor);

        $this->assertSame($this->actor->id, $done->completed_by);
        $this->assertNotNull($done->completed_at);
        $this->assertTrue($done->isCompleted());
    }

    public function test_completion_records_a_remark(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $done = $this->pretrip->complete(
            $this->keyed($trip)[PretripCheckKey::VEHICLE_ASSIGNED],
            self::TENANT_A, $this->actor, '  Walked round the vehicle  ',
        );

        $this->assertSame('Walked round the vehicle', $done->remarks);
    }

    public function test_completing_every_check_reads_ready(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $check) {
            $this->pretrip->complete($check, self::TENANT_A, $this->actor);
        }

        $readiness = $this->pretrip->readiness($trip, self::TENANT_A);
        $this->assertSame(PretripReadiness::READY, $readiness['status']);
        $this->assertTrue($readiness['ready']);
        $this->assertSame(5, $readiness['completed']);
    }

    public function test_completing_a_blocked_check_never_unblocks_the_trip(): void
    {
        // Completion and outcome are different axes. FRS's "supervisor sign-off"
        // is the override, and override is P1.
        [$trip, , $driver] = $this->crewedTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        foreach ($this->pretrip->checksFor($trip, self::TENANT_A) as $check) {
            $this->pretrip->complete($check, self::TENANT_A, $this->actor);
        }

        $readiness = $this->pretrip->readiness($trip, self::TENANT_A);
        $this->assertSame(PretripReadiness::BLOCKED, $readiness['status']);
        $this->assertFalse($readiness['ready']);
        $this->assertSame(5, $readiness['completed'], 'every item was looked at');
    }

    public function test_completion_never_alters_the_result(): void
    {
        [$trip, , $driver] = $this->crewedTrip();
        $driver->forceFill(['licence_valid_until' => now()->subDay()])->save();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $check = $this->keyed($trip)[PretripCheckKey::DRIVER_DOCUMENTS];
        $done = $this->pretrip->complete($check, self::TENANT_A, $this->actor, 'Aware, chasing renewal');

        $this->assertSame(PretripResult::CRITICAL_FAIL, $done->result);
        $this->assertTrue($done->blocks());
    }

    public function test_a_pending_check_cannot_be_completed(): void
    {
        [$trip] = $this->crewedTrip();
        $row = TripPretripCheck::create([
            'tenant_id' => self::TENANT_A, 'trip_id' => $trip->id,
            'check_key' => PretripCheckKey::ORDER_APPROVED,
        ]);

        $this->expectException(BusinessException::class);
        $this->pretrip->complete($row, self::TENANT_A, $this->actor);
    }

    public function test_completing_many_is_all_or_nothing(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $checks = $this->pretrip->checksFor($trip, self::TENANT_A);

        try {
            $this->pretrip->completeMany($trip, [
                ['id' => $checks[0]->id],
                ['id' => 999999],          // not on this trip
            ], self::TENANT_A, $this->actor);
            $this->fail('a partial submission must not be applied');
        } catch (BusinessException) {
        }

        $this->assertSame(
            0,
            TripPretripCheck::forTenant(self::TENANT_A)->forTrip($trip->id)->whereNotNull('completed_at')->count(),
            'the first completion must have rolled back with the second',
        );
    }

    public function test_completing_many_stamps_each_item(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $checks = $this->pretrip->checksFor($trip, self::TENANT_A);

        $result = $this->pretrip->completeMany($trip, [
            ['id' => $checks[0]->id, 'remarks' => 'ok'],
            ['id' => $checks[1]->id],
        ], self::TENANT_A, $this->actor);

        $this->assertSame(2, $result->filter(fn ($c) => $c->isCompleted())->count());
    }

    /* ══════════ tenancy — the rule that matters most ══════════ */

    public function test_generation_is_scoped_to_its_tenant(): void
    {
        [$tripA] = $this->crewedTrip(self::TENANT_A);
        $this->pretrip->generate($tripA, self::TENANT_A, $this->actor);

        $this->assertSame(5, TripPretripCheck::forTenant(self::TENANT_A)->count());
        $this->assertSame(0, TripPretripCheck::forTenant(self::TENANT_B)->count());
    }

    public function test_another_tenant_sees_no_checks_for_the_same_trip_id(): void
    {
        [$tripA] = $this->crewedTrip(self::TENANT_A);
        $this->pretrip->generate($tripA, self::TENANT_A, $this->actor);

        $readiness = $this->pretrip->readiness($tripA, self::TENANT_B);

        $this->assertSame(PretripReadiness::NOT_STARTED, $readiness['status']);
        $this->assertSame(0, $readiness['total']);
    }

    public function test_a_check_cannot_be_completed_from_another_tenant(): void
    {
        [$tripA] = $this->crewedTrip(self::TENANT_A);
        $this->pretrip->generate($tripA, self::TENANT_A, $this->actor);
        $check = $this->keyed($tripA)[PretripCheckKey::ORDER_APPROVED];

        $this->expectException(BusinessException::class);
        $this->pretrip->complete($check, self::TENANT_B, $this->actor);
    }

    public function test_two_tenants_generate_independently(): void
    {
        [$a] = $this->crewedTrip(self::TENANT_A);
        [$b] = $this->crewedTrip(self::TENANT_B);

        $this->pretrip->generate($a, self::TENANT_A, $this->actor);
        $this->pretrip->generate($b, self::TENANT_B, $this->actor);

        $this->assertSame(5, TripPretripCheck::forTenant(self::TENANT_A)->count());
        $this->assertSame(5, TripPretripCheck::forTenant(self::TENANT_B)->count());
    }

    /* ══════════ audit ══════════ */

    public function test_generation_is_audited_with_the_full_checklist(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.pretrip.generated')->first();

        $this->assertNotNull($entry);
        $this->assertSame('STOS-REQ-OPS-004', $entry->context['rule']);
        $this->assertCount(5, $entry->context['checks']);
        $this->assertSame(PretripReadiness::IN_PROGRESS, $entry->context['readiness']);
    }

    public function test_completion_is_audited_with_the_result_it_confirmed(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);
        $check = $this->keyed($trip)[PretripCheckKey::DRIVER_ASSIGNED];

        $this->pretrip->complete($check, self::TENANT_A, $this->actor, 'seen');

        $entry = $check->auditTrail()->where('action', 'transport.pretrip.check_completed')->first();

        $this->assertNotNull($entry);
        $this->assertSame(PretripCheckKey::DRIVER_ASSIGNED, $entry->context['check']);
        $this->assertSame(PretripResult::PASS, $entry->context['result']);
        $this->assertSame('seen', $entry->context['remarks']);
        $this->assertSame($this->actor->id, $entry->actor_id);
    }

    public function test_removing_a_stale_check_is_audited(): void
    {
        [$trip] = $this->crewedTrip();
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $this->policies->set(self::TENANT_A, 'pretrip.checks.enabled', [PretripCheckKey::ORDER_APPROVED], $this->actor);
        $this->pretrip->generate($trip, self::TENANT_A, $this->actor);

        $entry = $trip->auditTrail()->where('action', 'transport.pretrip.checks_removed')->first();

        $this->assertNotNull($entry);
        $this->assertCount(4, $entry->context['checks']);
    }
}
