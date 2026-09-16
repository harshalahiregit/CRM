<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportDocument;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportVehicle;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverStatus;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\VehicleOwnership;
use App\Support\Transport\VehicleStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-003 (Vehicle master) and SNG-TRN-004 (Driver master).
 *
 * Ticket 003 acceptance: "Unique vehicle identity, document dates, audit."
 * Ticket 004 acceptance: "Required fields, validity dates, audit."
 *                 tests: "CRUD + expiry tests."
 *
 * Every test below maps to a requirement ID so the traceability rule (non-
 * negotiable rule 7) is satisfiable for these two tickets despite the ticket
 * pack's own references being wrong (defects D-1/D-2).
 */
class TransportMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::TENANT_A => 'Alpha Transport', self::TENANT_B => 'Bravo Logistics'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }
    }

    private function vehicle(int $tenantId, array $attributes = []): TransportVehicle
    {
        return TransportVehicle::create(array_merge([
            'tenant_id' => $tenantId,
            'registration_number' => 'MH12AB'.self::uniqueSeq(4),
        ], $attributes));
    }

    private function driver(int $tenantId, array $attributes = []): TransportDriver
    {
        return TransportDriver::create(array_merge([
            'tenant_id' => $tenantId,
            'name' => 'Driver '.Str::random(5),
        ], $attributes));
    }

    /* ══════════ Vehicle master — SNG-TRN-003 ══════════ */

    /** FLEET §9 — "unique within organization; searchable; normalized". */
    public function test_vehicle_registration_is_normalized_however_it_is_typed(): void
    {
        foreach (['RJ 14 XX 1234', 'rj-14-xx-1234', ' RJ14xx1234 '] as $input) {
            $this->assertSame('RJ14XX1234', TransportVehicle::normalizeRegistration($input));
        }

        $vehicle = $this->vehicle(self::TENANT_A, ['registration_number' => 'MH 12 AB 4455']);

        $this->assertSame('MH12AB4455', $vehicle->registration_normalized);
    }

    public function test_the_same_vehicle_cannot_be_registered_twice_under_different_formatting(): void
    {
        $this->vehicle(self::TENANT_A, ['registration_number' => 'MH 12 AB 4455']);

        $this->expectException(QueryException::class);
        $this->vehicle(self::TENANT_A, ['registration_number' => 'mh-12-ab-4455']);
    }

    public function test_two_tenants_may_each_hold_the_same_registration(): void
    {
        $a = $this->vehicle(self::TENANT_A, ['registration_number' => 'MH12AB4455']);
        $b = $this->vehicle(self::TENANT_B, ['registration_number' => 'MH 12 AB 4455']);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(1, TransportVehicle::forTenant(self::TENANT_A)->count());
        $this->assertSame(1, TransportVehicle::forTenant(self::TENANT_B)->count());
    }

    /** FLEET §8 — status must be driven by business events, not typed. */
    public function test_vehicle_status_cannot_be_mass_assigned(): void
    {
        $vehicle = $this->vehicle(self::TENANT_A);
        $this->assertSame(VehicleStatus::NEW, $vehicle->status);

        $vehicle->fill(['status' => VehicleStatus::AVAILABLE, 'registration_normalized' => 'HACKED'])->save();

        $this->assertSame(VehicleStatus::NEW, $vehicle->fresh()->status);
        $this->assertNotSame('HACKED', $vehicle->fresh()->registration_normalized);
    }

    public function test_vehicle_declares_all_thirteen_states_but_only_wires_reachable_transitions(): void
    {
        $this->assertCount(13, VehicleStatus::ALL);

        $vehicle = $this->vehicle(self::TENANT_A);

        $this->assertTrue($vehicle->canTransitionTo(VehicleStatus::AVAILABLE));
        // Owned by later tickets — must not be reachable from the master.
        $this->assertFalse($vehicle->canTransitionTo(VehicleStatus::ALLOCATED));
        $this->assertFalse($vehicle->canTransitionTo(VehicleStatus::IN_TRANSIT));
        $this->assertFalse($vehicle->canTransitionTo(VehicleStatus::UNDER_MAINTENANCE));
    }

    public function test_vehicle_defaults_match_the_column_defaults_in_memory(): void
    {
        $vehicle = new TransportVehicle();

        $this->assertSame(VehicleStatus::NEW, $vehicle->status);
        $this->assertSame(VehicleOwnership::OWNED, $vehicle->ownership_type);
    }

    /* ══════════ Driver master — SNG-TRN-004 ══════════ */

    /** STOS-DB §152 — driver search by licence must work however it is typed. */
    public function test_driver_licence_is_normalized_however_it_is_typed(): void
    {
        foreach (['RJ14 20110012345', 'rj-14-2011-0012345', ' RJ1420110012345 '] as $input) {
            $this->assertSame('RJ1420110012345', TransportDriver::normalizeLicence($input));
        }

        $driver = $this->driver(self::TENANT_A, ['licence_number' => 'RJ14 2011 0012345']);

        $this->assertSame('RJ1420110012345', $driver->licence_normalized);
    }

    public function test_the_same_licence_cannot_be_held_by_two_drivers_in_one_tenant(): void
    {
        $this->driver(self::TENANT_A, ['licence_number' => 'RJ14 20110012345']);

        $this->expectException(QueryException::class);
        $this->driver(self::TENANT_A, ['licence_number' => 'rj-14-2011-0012345']);
    }

    /**
     * The unique indexes must not punish incomplete onboarding.
     *
     * A driver may exist before their licence, code or employee link does. If
     * licence_normalized were stored as '' rather than NULL, the second such
     * driver would collide — which would make the master unusable in exactly the
     * situation it is most needed.
     */
    public function test_many_drivers_may_exist_without_licence_code_or_employee_link(): void
    {
        $drivers = collect(range(1, 3))->map(fn () => $this->driver(self::TENANT_A));

        $this->assertCount(3, $drivers->pluck('id')->unique());
        $this->assertNull($drivers->first()->fresh()->licence_normalized);
    }

    /** STOS-INT §76 — "Avoid duplicate driver profiles." */
    public function test_one_hr_employee_cannot_have_two_driver_profiles(): void
    {
        $this->driver(self::TENANT_A, ['hr_employee_id' => 500]);

        $this->expectException(QueryException::class);
        $this->driver(self::TENANT_A, ['hr_employee_id' => 500]);
    }

    public function test_the_same_employee_id_may_exist_under_another_tenant(): void
    {
        $this->driver(self::TENANT_A, ['hr_employee_id' => 500]);
        $other = $this->driver(self::TENANT_B, ['hr_employee_id' => 500]);

        $this->assertNotNull($other->id);
    }

    public function test_driver_code_is_unique_per_tenant(): void
    {
        $this->driver(self::TENANT_A, ['driver_code' => 'DRV-001']);

        $this->expectException(QueryException::class);
        $this->driver(self::TENANT_A, ['driver_code' => 'DRV-001']);
    }

    /** Both state axes are guarded, exactly as the vehicle's single axis is. */
    public function test_neither_driver_status_nor_availability_can_be_mass_assigned(): void
    {
        $driver = $this->driver(self::TENANT_A);

        $driver->fill([
            'status' => DriverStatus::BLOCKED,
            'availability' => DriverAvailability::ON_TRIP,
            'licence_normalized' => 'HACKED',
        ])->save();

        $fresh = $driver->fresh();
        $this->assertSame(DriverStatus::ACTIVE, $fresh->status);
        $this->assertSame(DriverAvailability::AVAILABLE, $fresh->availability);
        $this->assertNull($fresh->licence_normalized);
    }

    public function test_driver_availability_declares_assigned_but_this_ticket_cannot_reach_it(): void
    {
        $this->assertContains(DriverAvailability::ASSIGNED, DriverAvailability::ALL);

        $driver = $this->driver(self::TENANT_A);

        // Master-admin moves this ticket owns.
        $this->assertTrue($driver->canTransitionAvailabilityTo(DriverAvailability::ON_LEAVE));
        $this->assertTrue($driver->canTransitionAvailabilityTo(DriverAvailability::SUSPENDED));

        // `assigned` became a declared transition when SNG-TRN-009 step 6 wired
        // allocation — the move is real. What the MASTER may do is narrower than
        // what the enum declares, and TransportDriverService is what enforces
        // that (see TransportMasterAuditTest). `on_trip` still has no producer.
        $this->assertContains(DriverAvailability::ASSIGNED, DriverAvailability::ALLOCATION_OWNED);
        $this->assertContains(DriverAvailability::ON_TRIP, DriverAvailability::ALLOCATION_OWNED);
        $this->assertArrayNotHasKey(DriverAvailability::ON_TRIP, DriverAvailability::TRANSITIONS);
    }

    public function test_driver_lifecycle_is_a_separate_axis_from_availability(): void
    {
        $driver = $this->driver(self::TENANT_A);

        $this->assertTrue($driver->canTransitionStatusTo(DriverStatus::INACTIVE));
        $this->assertTrue($driver->canTransitionStatusTo(DriverStatus::BLOCKED));
        // A lifecycle value is not an availability value and vice versa.
        $this->assertFalse(DriverAvailability::isValid(DriverStatus::INACTIVE));
        $this->assertFalse(DriverStatus::isValid(DriverAvailability::ON_LEAVE));
    }

    /** CMP §22 / BR-P0-004 — the licence check allocation will run in step 5. */
    public function test_licence_validity_covers_every_date_case(): void
    {
        $valid = $this->driver(self::TENANT_A, ['licence_number' => 'A1', 'licence_valid_until' => now()->addYear()->toDateString()]);
        $lapsed = $this->driver(self::TENANT_A, ['licence_number' => 'A2', 'licence_valid_until' => now()->subDay()->toDateString()]);
        $future = $this->driver(self::TENANT_A, ['licence_number' => 'A3', 'licence_valid_from' => now()->addMonth()->toDateString()]);
        $noExpiry = $this->driver(self::TENANT_A, ['licence_number' => 'A4']);
        $noLicence = $this->driver(self::TENANT_A);

        $this->assertTrue($valid->licenceIsValid());
        $this->assertFalse($lapsed->licenceIsValid());
        $this->assertTrue($lapsed->licenceIsExpired());
        $this->assertFalse($future->licenceIsValid(), 'a licence that starts next month is not valid today');
        $this->assertTrue($noExpiry->licenceIsValid(), 'a null expiry never expires');
        $this->assertNull($noExpiry->daysUntilLicenceExpiry());
        $this->assertFalse($noLicence->licenceIsValid(), 'no licence is not a valid licence');
        $this->assertNull($noLicence->daysUntilLicenceExpiry());
    }

    /** PLN-003 first filter — both axes, because either alone is wrong. */
    public function test_allocatable_drivers_require_active_status_and_available_availability(): void
    {
        $usable = $this->driver(self::TENANT_A);
        $onLeave = $this->driver(self::TENANT_A);
        $inactive = $this->driver(self::TENANT_A);

        DB::table('transport_drivers')->where('id', $onLeave->id)->update(['availability' => DriverAvailability::ON_LEAVE]);
        DB::table('transport_drivers')->where('id', $inactive->id)->update(['status' => DriverStatus::INACTIVE]);

        $ids = TransportDriver::forTenant(self::TENANT_A)->allocatable()->pluck('id');

        $this->assertTrue($ids->contains($usable->id));
        $this->assertFalse($ids->contains($onLeave->id), 'a driver on leave is not allocatable');
        $this->assertFalse($ids->contains($inactive->id), 'an inactive driver is not allocatable even when marked available');
    }

    public function test_driver_search_matches_name_code_mobile_and_licence(): void
    {
        $this->driver(self::TENANT_A, [
            'name' => 'Ramesh Kumar', 'driver_code' => 'DRV-001',
            'mobile' => '9876543210', 'licence_number' => 'RJ14 20110012345',
        ]);

        foreach (['Ramesh', 'DRV-001', '98765', 'rj-14-2011'] as $term) {
            $this->assertSame(1, TransportDriver::forTenant(self::TENANT_A)->search($term)->count(), "search failed for: {$term}");
        }
    }

    /* ══════════ Shared document table — DB-019 / IDX-010 ══════════ */

    /**
     * The riskiest property of a polymorphic table: a vehicle and a driver can
     * hold the same numeric id, and entity_type is the only thing separating
     * their documents. If that ever breaks, a driver inherits a truck's
     * insurance and the compliance gate silently passes.
     */
    public function test_a_vehicle_and_a_driver_sharing_an_id_never_see_each_others_documents(): void
    {
        $driver = $this->driver(self::TENANT_A);
        $vehicle = $this->vehicle(self::TENANT_A);
        DB::table('transport_vehicles')->where('id', $vehicle->id)->update(['id' => $driver->id]);
        $vehicle = TransportVehicle::forTenant(self::TENANT_A)->find($driver->id);

        $this->assertSame($driver->id, $vehicle->id, 'precondition: ids must collide for this test to mean anything');

        TransportDocument::create(['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::DRIVER, 'entity_id' => $driver->id, 'document_type' => TransportDocumentType::DRIVER_DOC]);
        TransportDocument::create(['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::DRIVER, 'entity_id' => $driver->id, 'document_type' => TransportDocumentType::FITNESS]);
        TransportDocument::create(['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::VEHICLE, 'entity_id' => $vehicle->id, 'document_type' => TransportDocumentType::INSURANCE]);

        $this->assertSame(2, $driver->documents()->count());
        $this->assertSame(1, $vehicle->documents()->count());
    }

    /** The eager-loading path is separate code — and is where a tenant clause broke it. */
    public function test_documents_load_eagerly(): void
    {
        $driver = $this->driver(self::TENANT_A);
        TransportDocument::create(['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::DRIVER, 'entity_id' => $driver->id, 'document_type' => TransportDocumentType::DRIVER_DOC]);

        $loaded = TransportDriver::forTenant(self::TENANT_A)->with('documents')->find($driver->id);

        $this->assertCount(1, $loaded->documents, 'eager loading must not silently return zero rows');
    }

    /** IDX-010 — UNIQUE(tenant, entity_type, entity_id, document_type, version). */
    public function test_a_document_version_cannot_be_issued_twice(): void
    {
        $driver = $this->driver(self::TENANT_A);
        $payload = ['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::DRIVER, 'entity_id' => $driver->id, 'document_type' => TransportDocumentType::FITNESS, 'version' => 1];
        TransportDocument::create($payload);

        $this->expectException(QueryException::class);
        TransportDocument::create($payload);
    }

    /** STOS-DOC §26 — a replacement is a new version, never an overwrite. */
    public function test_renewing_a_document_preserves_the_previous_version(): void
    {
        $driver = $this->driver(self::TENANT_A);
        $base = ['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::DRIVER, 'entity_id' => $driver->id, 'document_type' => TransportDocumentType::FITNESS];

        $v1 = TransportDocument::create($base + ['valid_until' => now()->subDay()->toDateString()]);
        $v1->update(['status' => TransportDocument::STATUS_SUPERSEDED]);
        $v2 = TransportDocument::create($base + [
            'version' => TransportDocument::nextVersion(self::TENANT_A, TransportDocumentEntity::DRIVER, $driver->id, TransportDocumentType::FITNESS),
            'valid_until' => now()->addYear()->toDateString(),
        ]);

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertSame(2, TransportDocument::forTenant(self::TENANT_A)->forDriver($driver->id)->count());
        $this->assertSame(1, TransportDocument::forTenant(self::TENANT_A)->forDriver($driver->id)->active()->count());
    }

    public function test_document_expiry_treats_a_null_valid_until_as_never_expiring(): void
    {
        $driver = $this->driver(self::TENANT_A);
        $base = ['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::DRIVER, 'entity_id' => $driver->id];

        $lapsed = TransportDocument::create($base + ['document_type' => TransportDocumentType::FITNESS, 'valid_until' => now()->subDay()->toDateString()]);
        $forever = TransportDocument::create($base + ['document_type' => TransportDocumentType::DRIVER_DOC]);

        $this->assertTrue($lapsed->isExpired());
        $this->assertFalse($lapsed->isCurrentlyValid());
        $this->assertFalse($forever->isExpired());
        $this->assertTrue($forever->isCurrentlyValid());
        $this->assertSame(1, TransportDocument::forTenant(self::TENANT_A)->forDriver($driver->id)->expired()->count());
    }

    /* ══════════ Tenancy — the single most important rule of this ticket ══════════ */

    public function test_tenant_b_can_see_no_vehicle_driver_or_document_of_tenant_a(): void
    {
        $driver = $this->driver(self::TENANT_A, ['licence_number' => 'RJ1420110012345']);
        $vehicle = $this->vehicle(self::TENANT_A, ['registration_number' => 'MH12AB4455']);
        TransportDocument::create(['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::DRIVER, 'entity_id' => $driver->id, 'document_type' => TransportDocumentType::DRIVER_DOC]);
        TransportDocument::create(['tenant_id' => self::TENANT_A, 'entity_type' => TransportDocumentEntity::VEHICLE, 'entity_id' => $vehicle->id, 'document_type' => TransportDocumentType::INSURANCE]);

        $this->assertNull(TransportDriver::forTenant(self::TENANT_B)->find($driver->id));
        $this->assertNull(TransportVehicle::forTenant(self::TENANT_B)->find($vehicle->id));
        $this->assertSame(0, TransportDocument::forTenant(self::TENANT_B)->forDriver($driver->id)->count());
        $this->assertSame(0, TransportDocument::forTenant(self::TENANT_B)->forVehicle($vehicle->id)->count());
        $this->assertSame(0, TransportDriver::forTenant(self::TENANT_B)->count());
        $this->assertSame(0, TransportVehicle::forTenant(self::TENANT_B)->allocatable()->count());
    }

    public function test_every_master_model_carries_the_tenant_trait(): void
    {
        foreach ([TransportVehicle::class, TransportDriver::class, TransportDocument::class] as $model) {
            $this->assertContains(
                \App\Models\Traits\BelongsToTenant::class,
                class_uses_recursive($model),
                $model.' must use BelongsToTenant'
            );
        }
    }
}
