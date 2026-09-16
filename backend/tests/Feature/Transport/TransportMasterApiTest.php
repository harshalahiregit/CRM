<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportDriver;
use App\Models\Transport\TransportVehicle;
use App\Models\User;
use App\Support\Transport\DriverAvailability;
use App\Support\Transport\DriverStatus;
use App\Support\Transport\TransportDocumentType;
use App\Support\Transport\TransportPermission;
use App\Support\Transport\VehicleStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tickets 003 and 004, FE half — the API surface.
 *
 * Both register rows are marked BE/FE and both test notes demand permissions:
 * 003 "CRUD + validation + permissions", 004 "CRUD + expiry tests".
 *
 * Permission rows for these domains do not exist in Step 11 (defect D-8); the
 * ones under test were added as the narrowest defensible reading and are FLAGGED
 * in TransportPermission. These tests pin the behaviour so a later correction is
 * a deliberate change rather than a silent drift.
 */
class TransportMasterApiTest extends TestCase
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

    private function user(int $tenantId = self::TENANT_A, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => ucfirst($role), 'role' => $role,
            'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function actingAsAdmin(int $tenantId = self::TENANT_A): User
    {
        $u = $this->user($tenantId);
        Sanctum::actingAs($u);

        return $u;
    }

    private function vehiclePayload(array $o = []): array
    {
        return array_merge([
            'registration_number' => 'MH 12 AB '.self::uniqueSeq(4),
            'vehicle_type' => 'Trailer 40ft', 'capacity_tonnes' => 25,
            'ownership_type' => 'owned',
        ], $o);
    }

    private function driverPayload(array $o = []): array
    {
        return array_merge([
            'name' => 'Ramesh Kumar', 'mobile' => '9876543210',
            'licence_number' => 'RJ14 '.self::uniqueSeq(6),
            'licence_class' => 'HMV',
            'licence_valid_until' => now()->addYear()->toDateString(),
        ], $o);
    }

    /* ══════════ VEHICLE CRUD ══════════ */

    public function test_a_vehicle_can_be_created_listed_and_read(): void
    {
        $this->actingAsAdmin();

        $created = $this->postJson('/api/transport/vehicles', $this->vehiclePayload(['registration_number' => 'MH 12 AB 4455']))
            ->assertCreated()->json('data');

        $this->assertSame('MH12AB4455', $created['registration_normalized']);
        $this->assertSame(VehicleStatus::NEW, $created['status'], 'FLEET §8 — a new vehicle is not Available');

        $this->getJson('/api/transport/vehicles')->assertOk()
            ->assertJsonPath('data.data.0.registration_number', 'MH 12 AB 4455');

        $show = $this->getJson('/api/transport/vehicles/'.$created['id'])->assertOk()->json('data');
        $this->assertArrayHasKey('documents', $show);
        $this->assertArrayHasKey('eligibility', $show);
        $this->assertArrayHasKey('audit', $show);
        $this->assertNotEmpty($show['audit'], 'ticket 003 acceptance requires an audit trail');
    }

    public function test_duplicate_registration_is_rejected_with_a_readable_message(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/transport/vehicles', $this->vehiclePayload(['registration_number' => 'MH 12 AB 4455']))->assertCreated();

        $this->postJson('/api/transport/vehicles', $this->vehiclePayload(['registration_number' => 'mh-12-ab-4455']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration_normalized_probe');
    }

    public function test_a_vehicle_can_be_updated_but_status_is_not_an_editable_field(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $this->putJson("/api/transport/vehicles/{$id}", ['manufacturer' => 'Tata', 'status' => VehicleStatus::AVAILABLE])
            ->assertOk()->assertJsonPath('data.manufacturer', 'Tata');

        $this->assertSame(VehicleStatus::NEW, TransportVehicle::find($id)->status);
    }

    public function test_vehicle_status_moves_only_through_the_transition_endpoint(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $this->patchJson("/api/transport/vehicles/{$id}/status", ['status' => VehicleStatus::AVAILABLE, 'reason' => 'commissioned'])
            ->assertOk()->assertJsonPath('data.status', VehicleStatus::AVAILABLE);

        // FLEET §8 / VehicleStatus::TRANSITIONS — an undeclared move is refused.
        $this->patchJson("/api/transport/vehicles/{$id}/status", ['status' => VehicleStatus::IN_TRANSIT])
            ->assertStatus(422);
    }

    public function test_status_counts_cover_every_declared_state(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->assertCreated();

        $counts = $this->getJson('/api/transport/vehicles/status-counts')->assertOk()->json('data');

        $this->assertCount(13, $counts, 'all 13 FLEET §7 states must appear so a chip never vanishes');
        $this->assertSame(1, $counts[VehicleStatus::NEW]);
    }

    /* ══════════ DOCUMENTS ══════════ */

    public function test_a_document_can_be_filed_and_renewed_against_a_vehicle(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $doc = $this->postJson("/api/transport/vehicles/{$id}/documents", [
            'document_type' => TransportDocumentType::INSURANCE,
            'document_number' => 'POL-1',
            'valid_until' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data');

        $this->assertSame(1, $doc['version']);

        $renewed = $this->postJson("/api/transport/vehicles/{$id}/documents/{$doc['id']}/renew", [
            'document_type' => TransportDocumentType::INSURANCE,
            'valid_until' => now()->addYears(2)->toDateString(),
        ])->assertCreated()->json('data');

        $this->assertSame(2, $renewed['version'], 'STOS-DOC §26 — a renewal is a new version');
        $this->assertCount(2, $this->getJson("/api/transport/vehicles/{$id}")->json('data.documents'));
    }

    public function test_a_driver_document_type_cannot_be_filed_against_a_vehicle(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $this->postJson("/api/transport/vehicles/{$id}/documents", ['document_type' => TransportDocumentType::DRIVER_DOC])
            ->assertStatus(422);
    }

    public function test_a_document_cannot_expire_before_it_becomes_valid(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $this->postJson("/api/transport/vehicles/{$id}/documents", [
            'document_type' => TransportDocumentType::FITNESS,
            'valid_from' => now()->addYear()->toDateString(),
            'valid_until' => now()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('valid_until');
    }

    /* ══════════ DRIVER CRUD ══════════ */

    public function test_a_driver_can_be_created_listed_and_read(): void
    {
        $this->actingAsAdmin();

        $created = $this->postJson('/api/transport/drivers', $this->driverPayload(['licence_number' => 'RJ14 20110012345']))
            ->assertCreated()->json('data');

        $this->assertSame('RJ1420110012345', $created['licence_normalized']);
        $this->assertSame(DriverStatus::ACTIVE, $created['status']);
        $this->assertSame(DriverAvailability::AVAILABLE, $created['availability']);

        $show = $this->getJson('/api/transport/drivers/'.$created['id'])->assertOk()->json('data');
        $this->assertSame('compliant', $show['compliance_status'], 'CMP §23, derived');
        $this->assertArrayHasKey('eligibility', $show);
        $this->assertNotEmpty($show['audit']);
    }

    public function test_duplicate_licence_and_duplicate_employee_link_are_rejected(): void
    {
        $this->actingAsAdmin();
        $this->postJson('/api/transport/drivers', $this->driverPayload(['licence_number' => 'RJ14 20110012345', 'hr_employee_id' => 500]))->assertCreated();

        $this->postJson('/api/transport/drivers', $this->driverPayload(['licence_number' => 'rj-14-2011-0012345']))
            ->assertStatus(422)->assertJsonValidationErrors('licence_normalized_probe');

        // INT §76 — "Avoid duplicate driver profiles."
        $this->postJson('/api/transport/drivers', $this->driverPayload(['hr_employee_id' => 500]))
            ->assertStatus(422)->assertJsonValidationErrors('hr_employee_id');
    }

    public function test_neither_driver_axis_is_editable_through_update(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');

        $this->putJson("/api/transport/drivers/{$id}", [
            'name' => 'Ramesh K.', 'status' => DriverStatus::BLOCKED, 'availability' => DriverAvailability::ON_TRIP,
        ])->assertOk();

        $d = TransportDriver::find($id);
        $this->assertSame(DriverStatus::ACTIVE, $d->status);
        $this->assertSame(DriverAvailability::AVAILABLE, $d->availability);
        $this->assertSame('Ramesh K.', $d->name);
    }

    public function test_each_driver_axis_transitions_independently(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');

        $this->patchJson("/api/transport/drivers/{$id}/status", ['axis' => 'availability', 'value' => DriverAvailability::ON_LEAVE, 'reason' => 'annual'])
            ->assertOk()->assertJsonPath('data.availability', DriverAvailability::ON_LEAVE);

        $this->patchJson("/api/transport/drivers/{$id}/status", ['axis' => 'status', 'value' => DriverStatus::INACTIVE])
            ->assertOk()->assertJsonPath('data.status', DriverStatus::INACTIVE);

        // `assigned` belongs to SNG-TRN-009, not to master administration.
        $this->patchJson("/api/transport/drivers/{$id}/status", ['axis' => 'availability', 'value' => DriverAvailability::ASSIGNED])
            ->assertStatus(422);
    }

    public function test_a_licence_cannot_expire_before_it_starts(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/transport/drivers', $this->driverPayload([
            'licence_valid_from' => now()->addYear()->toDateString(),
            'licence_valid_until' => now()->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('licence_valid_until');
    }

    /* ══════════ Admin edit + delete — the reported bug ══════════ */

    /**
     * The exact call the detail screen makes: GET the record, change one field,
     * PUT the WHOLE object back — id, status, derived columns, timestamps and
     * all. The screen does not strip anything, so the API must tolerate it.
     *
     * The original report was "admin cannot edit or delete a vehicle from the
     * UI". Edit turned out to work at every layer; delete had no button. These
     * pin both so neither can regress unnoticed.
     */
    public function test_an_admin_can_edit_a_vehicle_by_putting_the_whole_record_back(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $whole = $this->getJson("/api/transport/vehicles/{$id}")->json('data.vehicle');
        $whole['manufacturer'] = 'Ashok Leyland';
        $whole['model'] = '3718';
        $whole['branch'] = 'Pune';

        $this->putJson("/api/transport/vehicles/{$id}", $whole)->assertOk();

        $fresh = TransportVehicle::find($id);
        $this->assertSame('Ashok Leyland', $fresh->manufacturer);
        $this->assertSame('3718', $fresh->model);
        $this->assertSame('Pune', $fresh->branch);
    }

    public function test_an_admin_can_edit_a_driver_by_putting_the_whole_record_back(): void
    {
        $this->actingAsAdmin();
        $id = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');

        $whole = $this->getJson("/api/transport/drivers/{$id}")->json('data.driver');
        $whole['mobile'] = '9999900000';
        $whole['licence_class'] = 'HTV';
        $whole['alternate_mobile'] = '9812300000';

        $this->putJson("/api/transport/drivers/{$id}", $whole)->assertOk();

        $fresh = TransportDriver::find($id);
        $this->assertSame('9999900000', $fresh->mobile);
        $this->assertSame('HTV', $fresh->licence_class);
    }

    /**
     * Only the four derived/business-event fields resist editing. Everything a
     * user legitimately types must go through — the Step 6 lockdown of
     * ALLOCATION_OWNED states must not have caught ordinary fields.
     */
    public function test_ordinary_fields_are_editable_and_only_the_guarded_four_are_not(): void
    {
        $this->actingAsAdmin();
        $vid = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');
        $did = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');

        $this->putJson("/api/transport/vehicles/{$vid}", [
            'manufacturer' => 'Tata', 'model' => 'Prima', 'variant' => '4028.S', 'fuel_type' => 'Diesel',
            'branch' => 'Nashik', 'fleet_number' => 'FL-9', 'chassis_number' => 'CH1', 'engine_number' => 'EN1',
            'gps_device_id' => 'GPS1', 'vehicle_type' => 'Tipper', 'capacity_tonnes' => 18.5,
            'ownership_type' => 'leased', 'manufacturing_year' => 2022, 'purchase_date' => '2022-05-01',
            // These two must be ignored, not rejected.
            'status' => VehicleStatus::ALLOCATED, 'registration_normalized' => 'HACKED',
        ])->assertOk();

        $v = TransportVehicle::find($vid);
        foreach (['manufacturer' => 'Tata', 'model' => 'Prima', 'variant' => '4028.S', 'fuel_type' => 'Diesel',
                  'branch' => 'Nashik', 'fleet_number' => 'FL-9', 'vehicle_type' => 'Tipper',
                  'ownership_type' => 'leased'] as $field => $expected) {
            $this->assertSame($expected, $v->{$field}, "{$field} must be editable");
        }
        $this->assertSame(18.5, (float) $v->capacity_tonnes);
        $this->assertSame(2022, $v->manufacturing_year);
        $this->assertSame(VehicleStatus::NEW, $v->status, 'status is a business event');
        $this->assertNotSame('HACKED', $v->registration_normalized, 'normalized is derived');

        $this->putJson("/api/transport/drivers/{$did}", [
            'name' => 'Suresh Patil', 'driver_code' => 'DRV-77', 'mobile' => '9800000001',
            'alternate_mobile' => '9800000002', 'licence_class' => 'HTV',
            'licence_valid_until' => now()->addYears(3)->toDateString(),
            'status' => DriverStatus::BLOCKED, 'availability' => DriverAvailability::ASSIGNED,
            'licence_normalized' => 'HACKED',
        ])->assertOk();

        $d = TransportDriver::find($did);
        $this->assertSame('Suresh Patil', $d->name);
        $this->assertSame('DRV-77', $d->driver_code);
        $this->assertSame('9800000001', $d->mobile);
        $this->assertSame('HTV', $d->licence_class);
        $this->assertSame(DriverStatus::ACTIVE, $d->status);
        $this->assertSame(DriverAvailability::AVAILABLE, $d->availability);
        $this->assertNotSame('HACKED', $d->licence_normalized);
    }

    public function test_an_admin_can_delete_a_vehicle_and_a_driver(): void
    {
        $this->actingAsAdmin();
        $vid = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');
        $did = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');

        $this->deleteJson("/api/transport/vehicles/{$vid}", ['reason' => 'created in error'])->assertOk();
        $this->deleteJson("/api/transport/drivers/{$did}", ['reason' => 'duplicate'])->assertOk();

        $this->assertNull(TransportVehicle::find($vid));
        $this->assertNull(TransportDriver::find($did));
        // Deleted, but the trail outlives the record.
        $this->assertDatabaseHas('transport_audit_logs', ['action' => 'transport.vehicle.deleted', 'auditable_id' => $vid]);
        $this->assertDatabaseHas('transport_audit_logs', ['action' => 'transport.driver.deleted', 'auditable_id' => $did]);
    }

    /* ══════════ Capability endpoint — what the UI asks before rendering ══════════ */

    public function test_the_capability_endpoint_tells_an_admin_it_may_delete(): void
    {
        $this->actingAsAdmin();

        $data = $this->getJson('/api/transport/permissions')->assertOk()->json('data');

        $this->assertSame('admin', $data['role']);
        foreach ([TransportPermission::VEHICLE_DELETE, TransportPermission::DRIVER_DELETE,
                  TransportPermission::VEHICLE_UPDATE, TransportPermission::DRIVER_UPDATE] as $key) {
            $this->assertArrayHasKey($key, $data['grants']);
        }
    }

    public function test_the_capability_endpoint_withholds_delete_from_operations(): void
    {
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_operations'));

        $grants = $this->getJson('/api/transport/permissions')->assertOk()->json('data.grants');

        // May maintain the masters, may not destroy them — so the UI hides the button.
        $this->assertArrayHasKey(TransportPermission::VEHICLE_UPDATE, $grants);
        $this->assertArrayNotHasKey(TransportPermission::VEHICLE_DELETE, $grants);
        $this->assertArrayNotHasKey(TransportPermission::DRIVER_DELETE, $grants);
    }

    /**
     * The capability endpoint carries no transport.permission gate of its own,
     * but it still sits inside the module's role:admin,staff group — so a client
     * is refused here exactly as it is everywhere else in Transport. Being
     * ungated means "no per-action permission required", not "open to anyone".
     */
    public function test_the_capability_endpoint_still_respects_the_module_gate(): void
    {
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));
        $this->getJson('/api/transport/permissions')->assertForbidden();

        $this->getJson('/api/transport/permissions')->assertForbidden();
    }

    public function test_the_capability_endpoint_needs_authentication(): void
    {
        $this->getJson('/api/transport/permissions')->assertUnauthorized();
    }

    /* ══════════ PERMISSIONS — D-8's rows, enforced ══════════ */

    public function test_a_dispatcher_may_read_masters_but_not_write_them(): void
    {
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_dispatcher'));

        $this->getJson('/api/transport/vehicles')->assertOk();
        $this->getJson('/api/transport/drivers')->assertOk();

        $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->assertForbidden();
        $this->postJson('/api/transport/drivers', $this->driverPayload())->assertForbidden();
    }

    public function test_accounts_may_read_masters_but_not_write_them(): void
    {
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'accounts'));

        $this->getJson('/api/transport/vehicles')->assertOk();
        $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->assertForbidden();
    }

    public function test_only_owner_or_admin_may_delete_a_master_record(): void
    {
        $admin = $this->actingAsAdmin();
        $vehicleId = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');
        $driverId  = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');

        // Operations may create and update but not delete.
        Sanctum::actingAs($this->user(self::TENANT_A, 'staff', 'transport_operations'));
        $this->deleteJson("/api/transport/vehicles/{$vehicleId}")->assertForbidden();
        $this->deleteJson("/api/transport/drivers/{$driverId}")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/transport/vehicles/{$vehicleId}")->assertOk();
        $this->deleteJson("/api/transport/drivers/{$driverId}")->assertOk();
    }

    public function test_a_client_cannot_reach_the_master_surface_at_all(): void
    {
        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        foreach (['/api/transport/vehicles', '/api/transport/drivers'] as $path) {
            $this->getJson($path)->assertForbidden();
            $this->postJson($path, [])->assertForbidden();
        }
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/transport/vehicles')->assertUnauthorized();
        $this->postJson('/api/transport/drivers', [])->assertUnauthorized();
    }

    public function test_the_new_permission_keys_exist_and_deny_by_default(): void
    {
        foreach ([
            TransportPermission::VEHICLE_VIEW, TransportPermission::VEHICLE_CREATE,
            TransportPermission::VEHICLE_UPDATE, TransportPermission::VEHICLE_DELETE,
            TransportPermission::DRIVER_VIEW, TransportPermission::DRIVER_CREATE,
            TransportPermission::DRIVER_UPDATE, TransportPermission::DRIVER_DELETE,
        ] as $key) {
            $this->assertTrue(TransportPermission::isPermission($key));
            // No portal role gets any of them — there is no "your own" fleet master.
            foreach ([TransportPermission::ROLE_DRIVER, TransportPermission::ROLE_CUSTOMER, TransportPermission::ROLE_SUPPLIER] as $role) {
                $this->assertNull(TransportPermission::scopeFor($key, $role), "{$role} must hold nothing on {$key}");
            }
        }
    }

    /* ══════════ TENANCY THROUGH THE API LAYER ══════════ */

    public function test_a_tenant_cannot_read_or_write_another_tenants_master_records(): void
    {
        $this->actingAsAdmin(self::TENANT_A);
        $vehicleId = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');
        $driverId  = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');

        $this->actingAsAdmin(self::TENANT_B);

        // 404, never 403 — "not there", not "not yours".
        $this->getJson("/api/transport/vehicles/{$vehicleId}")->assertNotFound();
        $this->getJson("/api/transport/drivers/{$driverId}")->assertNotFound();
        $this->putJson("/api/transport/vehicles/{$vehicleId}", ['manufacturer' => 'X'])->assertNotFound();
        $this->patchJson("/api/transport/drivers/{$driverId}/status", ['axis' => 'status', 'value' => DriverStatus::INACTIVE])->assertNotFound();
        $this->deleteJson("/api/transport/vehicles/{$vehicleId}")->assertNotFound();
        $this->postJson("/api/transport/vehicles/{$vehicleId}/documents", ['document_type' => TransportDocumentType::INSURANCE])->assertNotFound();

        // And the listing shows nothing of tenant A's.
        $this->assertSame(0, $this->getJson('/api/transport/vehicles')->json('data.total'));
        $this->assertSame(0, $this->getJson('/api/transport/drivers')->json('data.total'));
    }

    public function test_the_same_registration_may_exist_in_two_tenants(): void
    {
        $this->actingAsAdmin(self::TENANT_A);
        $this->postJson('/api/transport/vehicles', $this->vehiclePayload(['registration_number' => 'MH12AB4455']))->assertCreated();

        $this->actingAsAdmin(self::TENANT_B);
        $this->postJson('/api/transport/vehicles', $this->vehiclePayload(['registration_number' => 'MH 12 AB 4455']))->assertCreated();
    }
}
