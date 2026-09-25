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
use Illuminate\Support\Facades\DB;
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
            'licence_expiry' => now()->addYear()->toDateString(),
        ], $o);
    }


    /**
     * A legacy master row, created directly — D-149.
     *
     * These tests used `POST /vehicles` as SETUP for something else: status
     * counts, cross-tenant isolation, the permission matrix. That endpoint now
     * refuses (409, the read-only ruling), so the setup produced a null id and
     * the assertions afterwards stopped testing what they name — the tenancy
     * one collapsed its URL to the index and asserted nothing about tenancy at
     * all.
     *
     * Inserted directly rather than through Fleet: the subject here is the
     * LEGACY read surface, which still serves history, so the fixture has to be
     * a legacy row. Through the service would go through validation that is no
     * longer reachable from the API anyway.
     */
    private function seedLegacyVehicle(?int $tenantId = null): int
    {
        return DB::table('transport_vehicles')->insertGetId([
            'tenant_id' => $tenantId ?? self::TENANT_A,
            'registration_number' => 'MH01SEED'.self::uniqueSeq(4),
            'registration_normalized' => 'MH01SEED'.self::uniqueSeq(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedLegacyDriver(?int $tenantId = null): int
    {
        return DB::table('transport_drivers')->insertGetId([
            'tenant_id' => $tenantId ?? self::TENANT_A,
            'name' => 'Seed Driver '.self::uniqueSeq(3),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ══════════ VEHICLE CRUD ══════════ */

    public function test_status_counts_cover_every_declared_state(): void
    {
        $this->actingAsAdmin();
        $this->seedLegacyVehicle();

        $counts = $this->getJson('/api/transport/vehicles/status-counts')->assertOk()->json('data');

        $this->assertCount(13, $counts, 'all 13 FLEET §7 states must appear so a chip never vanishes');
        $this->assertSame(1, $counts[VehicleStatus::NEW]);
    }

    /* ══════════ DOCUMENTS ══════════ */

    /* ══════════ DRIVER CRUD ══════════ */

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

    /* ══════════ Admin edit + delete — the reported bug ══════════ */

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

        // D-143 — the permission middleware still runs first, so a dispatcher
        // is still refused on permission grounds for the VEHICLE write. The
        // driver create is HELD (D-145) and its route is absent, which answers
        // 405; that is a temporary shape and the hold's comment in
        // routes/transport.php lists this test as one that moves when it lifts.
        $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->assertForbidden();
        $this->postJson('/api/transport/drivers', $this->driverPayload())->assertStatus(405);
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
        }

        // The rule is unchanged — accounts may not write a master. Only the
        // vehicle path can still state it as a permission refusal; the driver
        // create route is held (D-145).
        $this->postJson('/api/transport/vehicles', [])->assertForbidden();
        $this->postJson('/api/transport/drivers', [])->assertStatus(405);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/transport/vehicles')->assertUnauthorized();

        // Against a REGISTERED write, so this asserts authentication rather
        // than the absence of a route — the held POST /drivers answers 405 to
        // everyone, signed in or not, which would have made this vacuous.
        $this->putJson('/api/transport/vehicles/1', [])->assertUnauthorized();
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

        // D-149 — seeded, not POSTed. Creating through the API returns 409 now,
        // so these ids were null and the URLs below collapsed to the index:
        // the guard asserted nothing about tenancy while still looking like it
        // did. Isolation itself was never broken; the test had stopped checking.
        $vehicleId = $this->seedLegacyVehicle(self::TENANT_A);
        $driverId  = $this->seedLegacyDriver(self::TENANT_A);

        $this->assertNotNull($vehicleId, 'nothing was seeded, so this proves nothing');

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
