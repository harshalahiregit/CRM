<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\Transport\TransportDocument;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportVehicle;
use App\Models\User;
use App\Services\Transport\TransportDocumentService;
use App\Services\Transport\TransportDriverService;
use App\Services\Transport\TransportVehicleService;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverComplianceStatus;
use App\Support\Transport\DriverStatus;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit gap G-1 and compliance gap G-2, closed and proven.
 *
 * G-1: tickets 003 and 004 both end their acceptance criteria with "audit", and
 * an audit on 2026-09-07 found that nothing ever called the audit trait on
 * TransportVehicle, TransportDriver or TransportDocument. These tests fail if
 * that regresses.
 *
 * SEC §110 sets the minimum an audit row must carry: timestamp, organization,
 * user, action, entity, entity ID, old value, new value, IP, user agent.
 */
class TransportMasterAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TransportVehicleService $vehicles;
    private TransportDriverService $drivers;
    private TransportDocumentService $documents;
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

        $this->vehicles  = app(TransportVehicleService::class);
        $this->drivers   = app(TransportDriverService::class);
        $this->documents = app(TransportDocumentService::class);
        $this->actor     = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Ops User', 'role' => 'staff',
            'email' => 'ops-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trail(string $action): ?TransportAuditLog
    {
        return TransportAuditLog::where('action', $action)->latest('id')->first();
    }

    private function makeVehicle(array $attributes = []): TransportVehicle
    {
        return $this->vehicles->create(array_merge([
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer 40ft',
        ], $attributes), self::TENANT_A, $this->actor);
    }

    private function makeDriver(array $attributes = []): TransportDriver
    {
        return $this->drivers->create(array_merge(['name' => 'Ramesh Kumar'], $attributes), self::TENANT_A, $this->actor);
    }

    /* ══════════ G-1 · Vehicle ══════════ */

    public function test_creating_a_vehicle_writes_an_audit_row(): void
    {
        $vehicle = $this->makeVehicle(['registration_number' => 'MH 12 AB 4455']);

        $entry = $this->trail('transport.vehicle.created');

        $this->assertNotNull($entry, 'ticket 003 acceptance requires an audit row on create');
        $this->assertSame(self::TENANT_A, (int) $entry->tenant_id);
        $this->assertSame($vehicle->id, (int) $entry->auditable_id);
        $this->assertSame(TransportVehicle::class, $entry->auditable_type);
        $this->assertSame($this->actor->id, (int) $entry->actor_id);
        $this->assertSame('MH 12 AB 4455', $entry->new_values['registration_number']);
        $this->assertNotNull($entry->occurred_at);
    }

    public function test_updating_a_vehicle_records_only_what_changed(): void
    {
        $vehicle = $this->makeVehicle(['manufacturer' => 'Tata']);

        $this->vehicles->update($vehicle, ['manufacturer' => 'Ashok Leyland'], self::TENANT_A, $this->actor);

        $entry = $this->trail('transport.vehicle.updated');
        $this->assertNotNull($entry);
        $this->assertSame(['manufacturer' => 'Tata'], $entry->old_values);
        $this->assertSame(['manufacturer' => 'Ashok Leyland'], $entry->new_values);
    }

    public function test_a_no_op_update_writes_no_audit_row(): void
    {
        $vehicle = $this->makeVehicle(['manufacturer' => 'Tata']);
        $before = TransportAuditLog::count();

        $this->vehicles->update($vehicle, ['manufacturer' => 'Tata'], self::TENANT_A, $this->actor);

        $this->assertSame($before, TransportAuditLog::count(), 'an unchanged update is noise, not evidence');
    }

    public function test_a_vehicle_status_change_is_audited_as_a_transition(): void
    {
        $vehicle = $this->makeVehicle();

        $this->vehicles->transitionTo($vehicle, VehicleStatus::AVAILABLE, self::TENANT_A, $this->actor, 'commissioned');

        $entry = $this->trail('transport.vehicle.status_changed');
        $this->assertNotNull($entry);
        $this->assertSame(VehicleStatus::NEW, $entry->old_values['status'] ?? $entry->old_values['from'] ?? null);
        $this->assertSame(VehicleStatus::AVAILABLE, $entry->new_values['status'] ?? $entry->new_values['to'] ?? null);
    }

    public function test_an_illegal_vehicle_transition_is_refused(): void
    {
        $vehicle = $this->makeVehicle();

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->vehicles->transitionTo($vehicle, VehicleStatus::IN_TRANSIT, self::TENANT_A, $this->actor);
    }

    public function test_deleting_a_vehicle_is_audited_before_it_disappears(): void
    {
        $vehicle = $this->makeVehicle();
        $id = $vehicle->id;

        $this->vehicles->delete($vehicle, self::TENANT_A, $this->actor, 'sold at auction');

        $entry = $this->trail('transport.vehicle.deleted');
        $this->assertNotNull($entry, 'a vehicle must never vanish without a trace');
        $this->assertSame($id, (int) $entry->auditable_id);
        $this->assertSame('sold at auction', $entry->context['reason']);
        $this->assertNull(TransportVehicle::forTenant(self::TENANT_A)->find($id));
        // The trail outlives the record it describes.
        $this->assertDatabaseHas('transport_audit_logs', ['id' => $entry->id]);
    }

    /* ══════════ G-1 · Driver ══════════ */

    public function test_driver_create_update_and_delete_are_all_audited(): void
    {
        $driver = $this->makeDriver(['licence_number' => 'RJ14 20110012345']);
        $this->assertNotNull($this->trail('transport.driver.created'));

        $this->drivers->update($driver, ['name' => 'Ramesh K.'], self::TENANT_A, $this->actor);
        $updated = $this->trail('transport.driver.updated');
        $this->assertSame(['name' => 'Ramesh Kumar'], $updated->old_values);
        $this->assertSame(['name' => 'Ramesh K.'], $updated->new_values);

        $this->drivers->delete($driver->fresh(), self::TENANT_A, $this->actor, 'left the company');
        $this->assertNotNull($this->trail('transport.driver.deleted'));
    }

    public function test_both_driver_state_axes_are_audited_separately(): void
    {
        $driver = $this->makeDriver();

        $this->drivers->transitionAvailabilityTo($driver, DriverAvailability::ON_LEAVE, self::TENANT_A, $this->actor, 'annual leave');
        $this->drivers->transitionStatusTo($driver->fresh(), DriverStatus::INACTIVE, self::TENANT_A, $this->actor, 'resigned');

        $this->assertNotNull($this->trail('transport.driver.availability_changed'));
        $this->assertNotNull($this->trail('transport.driver.status_changed'));
    }

    public function test_the_master_cannot_reserve_a_driver_for_a_trip(): void
    {
        $driver = $this->makeDriver();

        // `assigned` belongs to SNG-TRN-009, not to master administration.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->drivers->transitionAvailabilityTo($driver, DriverAvailability::ASSIGNED, self::TENANT_A, $this->actor);
    }

    /* ══════════ G-1 · Documents ══════════ */

    public function test_filing_a_document_is_audited_and_versioned(): void
    {
        $vehicle = $this->makeVehicle();

        $document = $this->documents->file(
            $vehicle, TransportDocumentType::INSURANCE,
            ['document_number' => 'POL-1', 'valid_until' => now()->addYear()->toDateString()],
            self::TENANT_A, $this->actor
        );

        $this->assertSame(1, $document->version);
        $entry = $this->trail('transport.document.filed');
        $this->assertNotNull($entry);
        $this->assertSame(TransportDocumentType::INSURANCE, $entry->new_values['document_type']);
    }

    public function test_renewing_supersedes_the_old_version_and_audits_both_acts(): void
    {
        $vehicle = $this->makeVehicle();
        $v1 = $this->documents->file($vehicle, TransportDocumentType::INSURANCE,
            ['valid_until' => now()->subDay()->toDateString()], self::TENANT_A, $this->actor);

        $v2 = $this->documents->renew($v1, ['valid_until' => now()->addYear()->toDateString()], self::TENANT_A, $this->actor);

        $this->assertSame(2, $v2->version);
        $this->assertSame(TransportDocument::STATUS_SUPERSEDED, $v1->fresh()->status);
        $this->assertNotNull($this->trail('transport.document.superseded'));
        $this->assertNotNull($this->trail('transport.document.renewed'));
        // STOS-DOC §26 — history survives.
        $this->assertSame(2, TransportDocument::forTenant(self::TENANT_A)->forVehicle($vehicle->id)->count());
    }

    public function test_a_document_cannot_be_filed_against_the_wrong_entity_kind(): void
    {
        $driver = $this->makeDriver();

        // `insurance` is vehicle-applicable, not driver-applicable.
        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->documents->file($driver, TransportDocumentType::INSURANCE, [], self::TENANT_A, $this->actor);
    }

    /* ══════════ Tenancy still holds through the services ══════════ */

    public function test_services_refuse_to_touch_another_tenants_records(): void
    {
        $vehicle = $this->makeVehicle();
        $driver = $this->makeDriver();

        foreach ([
            fn () => $this->vehicles->update($vehicle, ['manufacturer' => 'X'], self::TENANT_B, $this->actor),
            fn () => $this->vehicles->delete($vehicle, self::TENANT_B, $this->actor),
            fn () => $this->drivers->update($driver, ['name' => 'X'], self::TENANT_B, $this->actor),
            fn () => $this->drivers->delete($driver, self::TENANT_B, $this->actor),
        ] as $call) {
            try {
                $call();
                $this->fail('a cross-tenant write must not succeed');
            } catch (\App\Exceptions\ResourceNotFoundException $e) {
                $this->assertTrue(true); // 404, never 403 — "not there", not "not yours"
            }
        }
    }

    public function test_find_hides_another_tenants_record_as_not_found(): void
    {
        $vehicle = $this->makeVehicle();

        $this->expectException(\App\Exceptions\ResourceNotFoundException::class);
        $this->vehicles->find($vehicle->id, self::TENANT_B);
    }

    /* ══════════ G-2 · derived driver compliance (CMP §23) ══════════ */

    public function test_compliance_is_non_compliant_without_a_valid_licence(): void
    {
        $noLicence = $this->makeDriver();
        $lapsed = $this->makeDriver(['licence_number' => 'A1', 'licence_expiry' => now()->subDay()->toDateString()]);

        $this->assertSame(DriverComplianceStatus::NON_COMPLIANT, $noLicence->complianceStatus());
        $this->assertSame(DriverComplianceStatus::NON_COMPLIANT, $lapsed->complianceStatus());
        $this->assertTrue($lapsed->complianceBlocksAssignment(), 'BR-P0-004 must refuse this driver');
    }

    public function test_compliance_is_compliant_with_a_valid_licence_and_valid_documents(): void
    {
        $driver = $this->makeDriver(['licence_number' => 'A2', 'licence_expiry' => now()->addYears(2)->toDateString()]);
        $this->documents->file($driver, TransportDocumentType::FITNESS,
            ['valid_until' => now()->addYear()->toDateString()], self::TENANT_A, $this->actor);

        $this->assertSame(DriverComplianceStatus::COMPLIANT, $driver->fresh()->complianceStatus());
        $this->assertFalse($driver->fresh()->complianceBlocksAssignment());
    }

    public function test_a_document_inside_the_warning_window_reads_expiring_not_blocking(): void
    {
        $driver = $this->makeDriver(['licence_number' => 'A3', 'licence_expiry' => now()->addYears(2)->toDateString()]);
        $this->documents->file($driver, TransportDocumentType::FITNESS,
            ['valid_until' => now()->addDays(10)->toDateString()], self::TENANT_A, $this->actor);

        $fresh = $driver->fresh();
        $this->assertSame(DriverComplianceStatus::EXPIRING, $fresh->complianceStatus());
        // Expiring warns; it does not stop a trip. That is the point of warning early.
        $this->assertFalse($fresh->complianceBlocksAssignment());
    }

    public function test_the_expiring_window_is_configurable_for_step_5(): void
    {
        $driver = $this->makeDriver(['licence_number' => 'A4', 'licence_expiry' => now()->addDays(45)->toDateString()]);

        $this->assertSame(DriverComplianceStatus::COMPLIANT, $driver->complianceStatus());        // default 30
        $this->assertSame(DriverComplianceStatus::EXPIRING, $driver->complianceStatus(60));       // tenant policy
        $this->assertSame(30, DriverComplianceStatus::DEFAULT_EXPIRING_WINDOW_DAYS);
    }

    public function test_a_lapsed_document_is_ignored_once_superseded(): void
    {
        $driver = $this->makeDriver(['licence_number' => 'A5', 'licence_expiry' => now()->addYears(2)->toDateString()]);
        $old = $this->documents->file($driver, TransportDocumentType::FITNESS,
            ['valid_until' => now()->subDay()->toDateString()], self::TENANT_A, $this->actor);

        $this->assertSame(DriverComplianceStatus::NON_COMPLIANT, $driver->fresh()->complianceStatus());

        $this->documents->renew($old, ['valid_until' => now()->addYear()->toDateString()], self::TENANT_A, $this->actor);

        $this->assertSame(DriverComplianceStatus::COMPLIANT, $driver->fresh()->complianceStatus(),
            'a renewed certificate must not still report the driver as lapsed');
    }

    public function test_a_blocked_driver_reports_blocked_regardless_of_paperwork(): void
    {
        $driver = $this->makeDriver(['licence_number' => 'A6', 'licence_expiry' => now()->addYears(2)->toDateString()]);
        $this->drivers->transitionStatusTo($driver, DriverStatus::BLOCKED, self::TENANT_A, $this->actor, 'incident review');

        $fresh = $driver->fresh();
        $this->assertSame(DriverComplianceStatus::BLOCKED, $fresh->complianceStatus());
        $this->assertTrue($fresh->complianceBlocksAssignment());
    }

    public function test_under_review_is_declared_but_unreachable(): void
    {
        $this->assertContains(DriverComplianceStatus::UNDER_REVIEW, DriverComplianceStatus::ALL);
        $this->assertNotContains(DriverComplianceStatus::UNDER_REVIEW, DriverComplianceStatus::REACHABLE);
        $this->assertSame([DriverComplianceStatus::UNDER_REVIEW], DriverComplianceStatus::UNREACHABLE);
        $this->assertCount(5, DriverComplianceStatus::ALL, 'CMP §23 names five states');
    }

    public function test_compliance_is_a_third_axis_distinct_from_the_other_two(): void
    {
        foreach (DriverComplianceStatus::ALL as $value) {
            $this->assertFalse(DriverAvailability::isValid($value), "{$value} must not be an availability value");
        }
        // 'blocked' is deliberately shared with the lifecycle axis — it is sourced from it.
        $this->assertTrue(DriverStatus::isValid(DriverComplianceStatus::BLOCKED));
    }

    /* ══════════ G-4 · every RTM §17 row is accounted for ══════════ */

    public function test_all_ten_rtm_17_rows_are_accounted_for(): void
    {
        $d = \App\Support\Transport\AllocationScope::RTM_17_DISPOSITION;

        $this->assertCount(10, $d, 'RTM §17 has ten rows');
        foreach (range(1, 10) as $n) {
            $id = 'STOS-REQ-PLN-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $this->assertArrayHasKey($id, $d, "{$id} must be in exactly one list");
            $this->assertContains($d[$id], ['in_scope', 'deferred']);
        }

        $scope = \App\Support\Transport\AllocationScope::class;
        $this->assertContains($scope::PLN_001, $scope::IN_SCOPE, 'PLN-001 was ruled in scope');
        $this->assertContains($scope::PLN_009, $scope::DEFERRED, 'PLN-009 belongs to Control Room');
        $this->assertContains($scope::PLN_010, $scope::DEFERRED);
        $this->assertArrayHasKey('PLN-009', $scope::EXCLUDED);
        $this->assertArrayHasKey('PLN-010', $scope::EXCLUDED);
    }
}
