<?php

namespace Tests\Feature\Transport;

use App\Exceptions\ResourceNotFoundException;
use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\Transport\TransportVehicle;
use App\Models\User;
use App\Services\Transport\DriverEligibilityService;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportPolicyService;
use App\Services\Transport\TransportVehicleService;
use App\Services\Transport\TripAssignmentService;
use App\Services\Transport\VehicleEligibilityService;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverStatus;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-009 step 5 — eligibility.
 *
 * PLN-002/003 ("only eligible suggested"), PLN-004/005 ("invalid blocked"),
 * PLN-006 (double allocation), PLN-001 (capacity), BR-P0-003, BR-P0-004,
 * BRW-028/029, QA-003.
 *
 * Each blocking rule is tested in isolation, because a verdict that fails for
 * two reasons hides whether either check actually works.
 */
class TransportEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private VehicleEligibilityService $vehicles;
    private DriverEligibilityService $drivers;
    private TransportPolicyService $policies;
    private TransportVehicleService $vehicleSvc;
    private TransportDriverService $driverSvc;
    private TransportDocumentService $docs;
    private TripAssignmentService $assignments;
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

        $this->vehicles    = app(VehicleEligibilityService::class);
        $this->drivers     = app(DriverEligibilityService::class);
        $this->policies    = app(TransportPolicyService::class);
        $this->vehicleSvc  = app(TransportVehicleService::class);
        $this->driverSvc   = app(TransportDriverService::class);
        $this->docs        = app(TransportDocumentService::class);
        $this->assignments = app(TripAssignmentService::class);

        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Dispatcher', 'role' => 'staff',
            'email' => 'd-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /* ── fixtures ── */

    private function trip(int $tenantId = self::TENANT_A, ?float $requiredCapacity = null): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
            'required_capacity_tonnes' => $requiredCapacity,
        ]);

        return TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
    }

    /** An otherwise-perfect vehicle: available, no clash, no expired papers. */
    private function goodVehicle(int $tenantId = self::TENANT_A, ?float $capacity = 30): TransportVehicle
    {
        $v = $this->vehicleSvc->create([
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => $capacity,
        ], $tenantId, $this->actor);

        return $this->vehicleSvc->transitionTo($v, VehicleStatus::AVAILABLE, $tenantId, $this->actor);
    }

    private function goodDriver(int $tenantId = self::TENANT_A): TransportDriver
    {
        return $this->driverSvc->create([
            'name' => 'Ramesh '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ], $tenantId, $this->actor);
    }

    private function checkFor(array $verdict, string $key): array
    {
        foreach ($verdict['checks'] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        $this->fail("no check '{$key}' in verdict");
    }

    /* ══════════ VEHICLE ══════════ */

    public function test_a_clean_vehicle_is_eligible(): void
    {
        $v = $this->vehicles->evaluate($this->goodVehicle(), $this->trip(), self::TENANT_A);

        $this->assertTrue($v['eligible']);
        $this->assertSame([], $v['blockers']);
        $this->assertCount(4, $v['checks'], 'status, assignment, documents, capacity');
    }

    /** FLEET §7/§8, BRW-044 — a vehicle the fleet has taken out of service. */
    public function test_a_vehicle_that_is_not_available_is_blocked(): void
    {
        $v = $this->goodVehicle();
        $this->vehicleSvc->transitionTo($v, VehicleStatus::SUSPENDED, self::TENANT_A, $this->actor);

        $verdict = $this->vehicles->evaluate($v->fresh(), $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $this->assertFalse($this->checkFor($verdict, 'status')['passed']);
        $this->assertStringContainsString('Suspended', $verdict['blockers'][0]);
        // The other checks still pass — one failure must not cascade.
        $this->assertTrue($this->checkFor($verdict, 'documents')['passed']);
    }

    /** QA-003 (Critical): "Expired vehicle document → blocked with actionable message." */
    public function test_an_expired_vehicle_document_blocks_allocation(): void
    {
        $v = $this->goodVehicle();
        $this->docs->file($v, TransportDocumentType::INSURANCE,
            ['valid_until' => now()->subDay()->toDateString()], self::TENANT_A, $this->actor);

        $verdict = $this->vehicles->evaluate($v->fresh(), $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $check = $this->checkFor($verdict, 'documents');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('Insurance', $check['detail']);
        $this->assertStringContainsString('Renew', $check['detail'], 'QA-003 requires an ACTIONABLE message');
    }

    /** CMP §24 + FLEET §11 — the required set is per-tenant, empty by default. */
    public function test_a_missing_required_document_blocks_only_once_configured(): void
    {
        $v = $this->goodVehicle();

        // CMP §10: nothing is required until someone says so.
        $this->assertTrue($this->vehicles->evaluate($v, $this->trip(), self::TENANT_A)['eligible']);

        $this->policies->set(self::TENANT_A, 'vehicle.required_documents',
            [TransportDocumentType::INSURANCE, TransportDocumentType::FITNESS], $this->actor);

        $verdict = $this->vehicles->evaluate($v->fresh(), $this->trip(), self::TENANT_A);
        $this->assertFalse($verdict['eligible']);
        $this->assertStringContainsString('Missing required', $this->checkFor($verdict, 'documents')['detail']);

        // File them and it clears.
        $this->docs->file($v, TransportDocumentType::INSURANCE, ['valid_until' => now()->addYear()->toDateString()], self::TENANT_A, $this->actor);
        $this->docs->file($v, TransportDocumentType::FITNESS, ['valid_until' => now()->addYear()->toDateString()], self::TENANT_A, $this->actor);

        $this->assertTrue($this->vehicles->evaluate($v->fresh(), $this->trip(), self::TENANT_A)['eligible']);
    }

    /** PLN-001 — the check the audit found missing. */
    public function test_insufficient_capacity_blocks_the_vehicle(): void
    {
        $small = $this->goodVehicle(capacity: 16);
        $verdict = $this->vehicles->evaluate($small, $this->trip(requiredCapacity: 25), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $check = $this->checkFor($verdict, 'capacity');
        $this->assertFalse($check['passed']);
        $this->assertTrue($check['required']);
        $this->assertStringContainsString('below', $check['detail']);
    }

    public function test_sufficient_capacity_passes(): void
    {
        $verdict = $this->vehicles->evaluate($this->goodVehicle(capacity: 30), $this->trip(requiredCapacity: 25), self::TENANT_A);

        $this->assertTrue($verdict['eligible']);
        $this->assertTrue($this->checkFor($verdict, 'capacity')['passed']);
    }

    /** CMP §10 by analogy — an unstated requirement is not a requirement of zero. */
    public function test_capacity_is_not_applicable_when_the_order_states_none(): void
    {
        $verdict = $this->vehicles->evaluate($this->goodVehicle(capacity: 1), $this->trip(), self::TENANT_A);

        $check = $this->checkFor($verdict, 'capacity');
        $this->assertTrue($check['passed']);
        $this->assertFalse($check['required'], 'a check nobody set must not block');
        $this->assertTrue($verdict['eligible']);
    }

    /** BR-P0-003 / PLN-006 surfaced as an eligibility check. */
    public function test_a_vehicle_assigned_elsewhere_is_not_eligible(): void
    {
        $v = $this->goodVehicle();
        $this->assignments->assign($this->trip(), $v->id, null, self::TENANT_A, $this->actor);

        $verdict = $this->vehicles->evaluate($v->fresh(), $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $this->assertStringContainsString('Already assigned', $this->checkFor($verdict, 'assignment')['detail']);
    }

    /** A vehicle is eligible for the trip it is already on — no self-clash. */
    public function test_a_vehicle_is_still_eligible_for_its_own_trip(): void
    {
        $v = $this->goodVehicle();
        $trip = $this->trip();
        $this->assignments->assign($trip, $v->id, null, self::TENANT_A, $this->actor);

        $this->assertTrue($this->checkFor($this->vehicles->evaluate($v->fresh(), $trip, self::TENANT_A), 'assignment')['passed']);
    }

    /* ══════════ DRIVER ══════════ */

    public function test_a_clean_driver_is_eligible(): void
    {
        $verdict = $this->drivers->evaluate($this->goodDriver(), $this->trip(), self::TENANT_A);

        $this->assertTrue($verdict['eligible']);
        $this->assertCount(5, $verdict['checks'], 'lifecycle, availability, assignment, licence, documents');
        $this->assertSame('compliant', $verdict['compliance_status']);
    }

    /** BR-P0-004: "critical document expired blocks assignment". */
    public function test_an_expired_licence_blocks_the_driver(): void
    {
        $d = $this->driverSvc->create([
            'name' => 'Lapsed', 'licence_number' => 'MH0199',
            'licence_valid_until' => now()->subDay()->toDateString(),
        ], self::TENANT_A, $this->actor);

        $verdict = $this->drivers->evaluate($d, $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $check = $this->checkFor($verdict, 'licence');
        $this->assertFalse($check['passed']);
        $this->assertStringContainsString('expired', $check['detail']);
        // BRWM §70's required tone.
        $this->assertStringContainsString('assign another eligible driver', $check['detail']);
    }

    public function test_a_driver_with_no_licence_is_blocked(): void
    {
        $d = $this->driverSvc->create(['name' => 'No Licence'], self::TENANT_A, $this->actor);

        $verdict = $this->drivers->evaluate($d, $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $this->assertStringContainsString('No licence number', $this->checkFor($verdict, 'licence')['detail']);
    }

    /** BRW-028: "Only drivers with AVAILABLE status may be recommended." */
    public function test_a_driver_on_leave_is_not_eligible(): void
    {
        $d = $this->goodDriver();
        $this->driverSvc->transitionAvailabilityTo($d, DriverAvailability::ON_LEAVE, self::TENANT_A, $this->actor);

        $verdict = $this->drivers->evaluate($d->fresh(), $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $this->assertStringContainsString('On leave', $this->checkFor($verdict, 'availability')['detail']);
        // Licence is untouched — checks are independent.
        $this->assertTrue($this->checkFor($verdict, 'licence')['passed']);
    }

    public function test_an_inactive_driver_is_not_eligible(): void
    {
        $d = $this->goodDriver();
        $this->driverSvc->transitionStatusTo($d, DriverStatus::INACTIVE, self::TENANT_A, $this->actor);

        $verdict = $this->drivers->evaluate($d->fresh(), $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $this->assertFalse($this->checkFor($verdict, 'lifecycle')['passed']);
    }

    /** CMP §23 — a blocked driver reports blocked, whatever the paperwork says. */
    public function test_a_blocked_driver_is_not_eligible_and_reports_blocked(): void
    {
        $d = $this->goodDriver();
        $this->driverSvc->transitionStatusTo($d, DriverStatus::BLOCKED, self::TENANT_A, $this->actor, 'incident');

        $verdict = $this->drivers->evaluate($d->fresh(), $this->trip(), self::TENANT_A);

        $this->assertFalse($verdict['eligible']);
        $this->assertSame('blocked', $verdict['compliance_status']);
    }

    /** BRW-029: "Driver must have valid required documents. If not: BLOCK." */
    public function test_a_missing_required_driver_document_blocks(): void
    {
        $d = $this->goodDriver();
        $this->policies->set(self::TENANT_A, 'driver.required_documents', [TransportDocumentType::FITNESS], $this->actor);

        $verdict = $this->drivers->evaluate($d->fresh(), $this->trip(), self::TENANT_A);
        $this->assertFalse($verdict['eligible']);
        $this->assertStringContainsString('Missing required', $this->checkFor($verdict, 'documents')['detail']);

        $this->docs->file($d, TransportDocumentType::FITNESS, ['valid_until' => now()->addYear()->toDateString()], self::TENANT_A, $this->actor);
        $this->assertTrue($this->drivers->evaluate($d->fresh(), $this->trip(), self::TENANT_A)['eligible']);
    }

    public function test_a_driver_assigned_elsewhere_is_not_eligible(): void
    {
        $d = $this->goodDriver();
        $this->assignments->assign($this->trip(), null, $d->id, self::TENANT_A, $this->actor);

        $verdict = $this->drivers->evaluate($d->fresh(), $this->trip(), self::TENANT_A);
        $this->assertFalse($verdict['eligible']);
        $this->assertStringContainsString('Already assigned', $this->checkFor($verdict, 'assignment')['detail']);
    }

    /** FLEET §13 — expiring warns, it does not block. */
    public function test_an_expiring_licence_warns_without_blocking(): void
    {
        $d = $this->driverSvc->create([
            'name' => 'Expiring', 'licence_number' => 'MH0177',
            'licence_valid_until' => now()->addDays(10)->toDateString(),
        ], self::TENANT_A, $this->actor);

        $verdict = $this->drivers->evaluate($d, $this->trip(), self::TENANT_A);

        $this->assertTrue($verdict['eligible'], 'a warning must not block');
        $this->assertSame('expiring', $verdict['compliance_status']);
        $this->assertNotEmpty($verdict['expiring_soon']);
        $this->assertSame('licence', $verdict['expiring_soon'][0]['document_type']);
    }

    /* ══════════ POLICY: advisory vs blocking (CMP §20) ══════════ */

    public function test_policy_can_move_a_check_from_blocking_to_advisory(): void
    {
        $d = $this->driverSvc->create([
            'name' => 'Lapsed', 'licence_number' => 'MH0155',
            'licence_valid_until' => now()->subDay()->toDateString(),
        ], self::TENANT_A, $this->actor);

        $this->assertFalse($this->drivers->evaluate($d, $this->trip(), self::TENANT_A)['eligible']);

        // CMP §20: "The blocking rule must be configurable."
        $this->policies->set(self::TENANT_A, 'driver.check.licence.required', false, $this->actor);

        $verdict = $this->drivers->evaluate($d->fresh(), $this->trip(), self::TENANT_A);
        $this->assertTrue($verdict['eligible'], 'an advisory check must not block');
        $this->assertFalse($this->checkFor($verdict, 'licence')['passed'], 'but it still reports as failed');
        $this->assertNotEmpty($verdict['warnings'], 'and appears as a warning');
    }

    public function test_defaults_apply_when_a_tenant_has_configured_nothing(): void
    {
        $this->assertSame(30, $this->policies->int(self::TENANT_A, 'compliance.expiring_window_days'));
        $this->assertSame([], $this->policies->list(self::TENANT_A, 'vehicle.required_documents'));
        $this->assertTrue($this->policies->bool(self::TENANT_A, 'driver.check.licence.required'));
    }

    public function test_policy_is_per_tenant(): void
    {
        $this->policies->set(self::TENANT_A, 'compliance.expiring_window_days', 60, $this->actor);

        $this->assertSame(60, $this->policies->int(self::TENANT_A, 'compliance.expiring_window_days'));
        $this->assertSame(30, $this->policies->int(self::TENANT_B, 'compliance.expiring_window_days'));
    }

    public function test_an_unknown_policy_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policies->set(self::TENANT_A, 'vehicle.check.invented.required', true, $this->actor);
    }

    public function test_a_document_type_cannot_be_required_of_the_wrong_entity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // `invoice` is neither vehicle- nor driver-applicable.
        $this->policies->set(self::TENANT_A, 'driver.required_documents', [TransportDocumentType::INVOICE], $this->actor);
    }

    public function test_reset_restores_the_default(): void
    {
        $this->policies->set(self::TENANT_A, 'compliance.expiring_window_days', 90, $this->actor);
        $this->policies->reset(self::TENANT_A, 'compliance.expiring_window_days');

        $this->assertSame(30, $this->policies->int(self::TENANT_A, 'compliance.expiring_window_days'));
    }

    /* ══════════ CANDIDATE LISTING — PLN-002/003 ══════════ */

    public function test_candidate_listing_returns_only_eligible_vehicles(): void
    {
        $trip = $this->trip(requiredCapacity: 20);
        $ok = $this->goodVehicle(capacity: 30);
        $tooSmall = $this->goodVehicle(capacity: 10);
        $suspended = $this->goodVehicle();
        $this->vehicleSvc->transitionTo($suspended, VehicleStatus::SUSPENDED, self::TENANT_A, $this->actor);

        $ids = $this->vehicles->candidatesFor($trip, self::TENANT_A)->pluck('subject.id');

        $this->assertTrue($ids->contains($ok->id));
        $this->assertFalse($ids->contains($tooSmall->id), 'PLN-002: only eligible suggested');
        $this->assertFalse($ids->contains($suspended->id));
    }

    public function test_candidate_listing_returns_only_eligible_drivers(): void
    {
        $trip = $this->trip();
        $ok = $this->goodDriver();
        $onLeave = $this->goodDriver();
        $this->driverSvc->transitionAvailabilityTo($onLeave, DriverAvailability::ON_LEAVE, self::TENANT_A, $this->actor);
        $noLicence = $this->driverSvc->create(['name' => 'No Licence'], self::TENANT_A, $this->actor);

        $ids = $this->drivers->candidatesFor($trip, self::TENANT_A)->pluck('subject.id');

        $this->assertTrue($ids->contains($ok->id));
        $this->assertFalse($ids->contains($onLeave->id));
        $this->assertFalse($ids->contains($noLicence->id), 'PLN-003: only eligible suggested');
    }

    /** The UI needs to show WHY something is unusable, not just hide it. */
    public function test_listing_can_include_ineligible_with_their_reasons(): void
    {
        $trip = $this->trip();
        $this->goodVehicle();
        $bad = $this->goodVehicle();
        $this->vehicleSvc->transitionTo($bad, VehicleStatus::SUSPENDED, self::TENANT_A, $this->actor);

        $all = $this->vehicles->candidatesFor($trip, self::TENANT_A, includeIneligible: true);
        $row = $all->firstWhere('subject.id', $bad->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['eligible']);
        $this->assertNotEmpty($row['blockers']);
    }

    /* ══════════ TENANCY — the leak-prone step ══════════ */

    public function test_candidate_listing_never_crosses_tenants(): void
    {
        $vA = $this->goodVehicle(self::TENANT_A);
        $vB = $this->goodVehicle(self::TENANT_B);
        $dA = $this->goodDriver(self::TENANT_A);
        $dB = $this->goodDriver(self::TENANT_B);

        $vehicleIds = $this->vehicles->candidatesFor($this->trip(self::TENANT_A), self::TENANT_A)->pluck('subject.id');
        $driverIds  = $this->drivers->candidatesFor($this->trip(self::TENANT_A), self::TENANT_A)->pluck('subject.id');

        $this->assertTrue($vehicleIds->contains($vA->id));
        $this->assertFalse($vehicleIds->contains($vB->id), 'tenant B fleet must never appear');
        $this->assertTrue($driverIds->contains($dA->id));
        $this->assertFalse($driverIds->contains($dB->id));
    }

    public function test_listing_against_another_tenants_trip_is_refused(): void
    {
        $tripA = $this->trip(self::TENANT_A);

        $this->expectException(ResourceNotFoundException::class);
        $this->vehicles->candidatesFor($tripA, self::TENANT_B);
    }

    public function test_driver_listing_against_another_tenants_trip_is_refused(): void
    {
        $tripA = $this->trip(self::TENANT_A);

        $this->expectException(ResourceNotFoundException::class);
        $this->drivers->candidatesFor($tripA, self::TENANT_B);
    }

    /**
     * A clash in another tenant must not make this tenant's vehicle look busy.
     * The assignment lookup is the one place a missing forTenant() would be
     * invisible in normal use.
     */
    public function test_another_tenants_assignment_does_not_block_this_tenants_vehicle(): void
    {
        $vA = $this->goodVehicle(self::TENANT_A);
        $vB = $this->goodVehicle(self::TENANT_B);
        $this->assignments->assign($this->trip(self::TENANT_B), $vB->id, null, self::TENANT_B, null);

        $this->assertTrue($this->vehicles->evaluate($vA, $this->trip(self::TENANT_A), self::TENANT_A)['eligible']);
    }

    public function test_another_tenants_documents_are_not_consulted(): void
    {
        $this->policies->set(self::TENANT_A, 'vehicle.required_documents', [TransportDocumentType::INSURANCE], $this->actor);
        $vA = $this->goodVehicle(self::TENANT_A);
        $vB = $this->goodVehicle(self::TENANT_B);
        // Tenant B files the document; tenant A's vehicle must still be missing it.
        $this->docs->file($vB, TransportDocumentType::INSURANCE, ['valid_until' => now()->addYear()->toDateString()], self::TENANT_B, null);

        $this->assertFalse($this->vehicles->evaluate($vA, $this->trip(self::TENANT_A), self::TENANT_A)['eligible']);
    }
}
