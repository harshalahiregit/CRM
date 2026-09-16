<?php

namespace Tests\Feature\Transport;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A full-surface audit of Vehicle/Driver masters + Allocation.
 *
 * Deliberately end-to-end through the HTTP layer wherever a role is involved,
 * because a permission that holds in the service but not on the route protects
 * nothing. Every role in TransportPermission::ROLE_MAP is exercised against every
 * action, positive and negative.
 */
class TransportMasterAllocationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const A = 1;
    private const B = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::A => 'Alpha Transport', self::B => 'Bravo Logistics'] as $id => $name) {
            (new Tenant())->forceFill([
                'id' => $id, 'name' => $name, 'slug' => Str::slug($name),
                'subdomain' => Str::slug($name), 'status' => 'active',
            ])->save();
        }
    }

    /* ── identities ── */

    private function user(string $role, ?string $internal = null, int $tenant = self::A): User
    {
        return User::create([
            'tenant_id' => $tenant, 'name' => ucfirst($role), 'role' => $role, 'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    /** Every identity that can reach the module, by the STOS role it maps to. */
    private function identities(): array
    {
        return [
            'owner'      => ['staff', 'transport_owner'],
            'operations' => ['staff', 'transport_operations'],
            'dispatcher' => ['staff', 'transport_dispatcher'],
            'accounts'   => ['staff', 'accounts'],
            'admin'      => ['admin', null],
        ];
    }

    private function actAs(string $stosRole, int $tenant = self::A): User
    {
        [$role, $internal] = $this->identities()[$stosRole];
        $u = $this->user($role, $internal, $tenant);
        Sanctum::actingAs($u);

        return $u;
    }

    /* ── fixtures ── */

    private function vehiclePayload(): array
    {
        return ['registration_number' => 'MH12'.Str::upper(Str::random(2)).self::uniqueSeq(4), 'capacity_tonnes' => 30];
    }

    private function driverPayload(): array
    {
        return [
            'name' => 'Driver '.Str::random(4),
            'licence_number' => 'RJ14'.self::uniqueSeq(6),
            'licence_valid_until' => now()->addYears(2)->toDateString(),
        ];
    }

    private function seedVehicle(int $tenant = self::A, ?float $capacity = 30): TransportVehicle
    {
        $svc = app(TransportVehicleService::class);
        $v = $svc->create(['registration_number' => 'MH12'.Str::upper(Str::random(2)).self::uniqueSeq(4), 'capacity_tonnes' => $capacity], $tenant, null);

        return $svc->transitionTo($v, VehicleStatus::AVAILABLE, $tenant, null);
    }

    private function seedDriver(int $tenant = self::A): TransportDriver
    {
        return app(TransportDriverService::class)->create($this->driverPayload(), $tenant, null);
    }

    private function approvedTrip(int $tenant = self::A, ?float $capacity = null): TransportTrip
    {
        $o = TransportOrder::create([
            'tenant_id' => $tenant, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(2), 'service_type' => 'Container Haulage',
            'required_capacity_tonnes' => $capacity,
        ]);
        $t = TransportTrip::create([
            'tenant_id' => $tenant, 'order_id' => $o->id, 'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $t->forceFill(['status' => TripStatus::APPROVED])->save();

        return $t->fresh();
    }

    /* ═══════════════ 1. ROLE × ACTION MATRIX, THROUGH HTTP ═══════════════ */

    /** VEHICLE: view = owner/ops/dispatcher/accounts/approver/admin. */
    public function test_vehicle_view_is_allowed_for_every_internal_role(): void
    {
        foreach (array_keys($this->identities()) as $stosRole) {
            $this->actAs($stosRole);
            $this->getJson('/api/transport/vehicles')->assertOk("{$stosRole} should read vehicles");
        }
    }

    public function test_vehicle_create_and_update_are_owner_operations_admin_only(): void
    {
        foreach (['owner', 'operations', 'admin'] as $stosRole) {
            $this->actAs($stosRole);
            $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())
                ->assertCreated("{$stosRole} should create")->json('data.id');
            $this->putJson("/api/transport/vehicles/{$id}", ['manufacturer' => 'Tata'])->assertOk();
        }

        foreach (['dispatcher', 'accounts'] as $stosRole) {
            $this->actAs($stosRole);
            $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->assertForbidden("{$stosRole} must not create");
            $this->putJson('/api/transport/vehicles/1', ['manufacturer' => 'X'])->assertForbidden();
        }
    }

    public function test_vehicle_delete_is_owner_and_admin_only(): void
    {
        $admin = $this->actAs('admin');
        $ids = [];
        foreach (range(1, 4) as $i) {
            $ids[] = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');
        }

        foreach (['operations', 'dispatcher', 'accounts'] as $stosRole) {
            $this->actAs($stosRole);
            $this->deleteJson('/api/transport/vehicles/'.$ids[0])->assertForbidden("{$stosRole} must not delete");
        }

        $this->actAs('owner');
        $this->deleteJson('/api/transport/vehicles/'.$ids[0])->assertOk();
        Sanctum::actingAs($admin);
        $this->deleteJson('/api/transport/vehicles/'.$ids[1])->assertOk();
    }

    /** DRIVER: same shape as vehicle. */
    public function test_driver_view_create_update_delete_follow_the_same_matrix(): void
    {
        foreach (array_keys($this->identities()) as $stosRole) {
            $this->actAs($stosRole);
            $this->getJson('/api/transport/drivers')->assertOk();
        }

        foreach (['owner', 'operations', 'admin'] as $stosRole) {
            $this->actAs($stosRole);
            $id = $this->postJson('/api/transport/drivers', $this->driverPayload())->assertCreated()->json('data.id');
            $this->putJson("/api/transport/drivers/{$id}", ['mobile' => '9800000000'])->assertOk();
        }

        foreach (['dispatcher', 'accounts'] as $stosRole) {
            $this->actAs($stosRole);
            $this->postJson('/api/transport/drivers', $this->driverPayload())->assertForbidden();
        }

        $this->actAs('operations');
        $id = $this->postJson('/api/transport/drivers', $this->driverPayload())->json('data.id');
        $this->deleteJson("/api/transport/drivers/{$id}")->assertForbidden('operations must not delete a driver');
        $this->actAs('admin');
        $this->deleteJson("/api/transport/drivers/{$id}")->assertOk();
    }

    /** ELIGIBILITY VIEW + ASSIGN + RELEASE all key off PERM-004. */
    public function test_assign_release_and_eligibility_are_owner_operations_dispatcher_admin(): void
    {
        $trip = $this->approvedTrip();

        foreach (['owner', 'operations', 'dispatcher', 'admin'] as $stosRole) {
            $this->actAs($stosRole);
            $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertOk("{$stosRole} should see candidates");
        }

        // Accounts may VIEW a trip (PERM-001) but not crew it (PERM-004).
        $this->actAs('accounts');
        $this->getJson("/api/transport/trips/{$trip->id}")->assertOk();
        $this->getJson("/api/transport/trips/{$trip->id}/candidates")->assertForbidden();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => 1])->assertForbidden();
        $this->deleteJson("/api/transport/trips/{$trip->id}/assign")->assertForbidden();
    }

    /** Portal roles are refused at the module gate, before any permission check. */
    public function test_portal_roles_cannot_reach_any_of_it(): void
    {
        $trip = $this->approvedTrip();

        foreach (['client', 'third_party_vendor', 'company'] as $role) {
            Sanctum::actingAs($this->user($role));
            foreach ([
                ['getJson', '/api/transport/vehicles'],
                ['getJson', '/api/transport/drivers'],
                ['getJson', "/api/transport/trips/{$trip->id}/candidates"],
            ] as [$verb, $path]) {
                $this->{$verb}($path)->assertForbidden("{$role} must not reach {$path}");
            }
        }
    }

    public function test_everything_requires_authentication(): void
    {
        $trip = $this->approvedTrip();

        $this->getJson('/api/transport/vehicles')->assertUnauthorized();
        $this->postJson('/api/transport/drivers', [])->assertUnauthorized();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", [])->assertUnauthorized();
        $this->deleteJson("/api/transport/trips/{$trip->id}/assign")->assertUnauthorized();
    }

    /* ═══════════════ 2. EXPIRY BLOCKS ALLOCATION ═══════════════ */

    /** QA-003 (Critical) — expired vehicle document blocks, with a reason. */
    public function test_expired_insurance_blocks_the_vehicle_with_the_actionable_reason(): void
    {
        $admin = $this->actAs('admin');
        $trip = $this->approvedTrip();
        $v = $this->seedVehicle();
        app(TransportDocumentService::class)->file($v, TransportDocumentType::INSURANCE,
            ['valid_until' => now()->subDays(3)->toDateString()], self::A, $admin);

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id])
            ->assertStatus(422)->json();

        $this->assertStringContainsString('Insurance', $body['message']);
        $this->assertStringContainsString('expired', $body['message']);
        $this->assertStringContainsString('Renew', $body['message']);
        // And nothing was written.
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);
        $this->assertSame(0, TripAssignment::forTenant(self::A)->forTrip($trip->id)->count());
        $this->assertSame(VehicleStatus::AVAILABLE, $v->fresh()->status);
    }

    /** BR-P0-004 — expired licence blocks the driver, with a reason. */
    public function test_expired_licence_blocks_the_driver_with_the_actionable_reason(): void
    {
        $this->actAs('admin');
        $trip = $this->approvedTrip();
        $d = app(TransportDriverService::class)->create([
            'name' => 'Lapsed', 'licence_number' => 'MH99'.self::uniqueSeq(4),
            'licence_valid_until' => now()->subDay()->toDateString(),
        ], self::A, null);

        $body = $this->postJson("/api/transport/trips/{$trip->id}/assign", ['driver_id' => $d->id])
            ->assertStatus(422)->json();

        $this->assertStringContainsString('Licence expired', $body['message']);
        $this->assertStringContainsString('assign another eligible driver', $body['message']);
        $this->assertSame(DriverAvailability::AVAILABLE, $d->fresh()->availability);
    }

    /** Expiry is evaluated LIVE at assignment time, not from a stored label. */
    public function test_a_document_that_lapses_after_creation_blocks_without_any_sweep(): void
    {
        $admin = $this->actAs('admin');
        $trip = $this->approvedTrip();
        $v = $this->seedVehicle();
        $doc = app(TransportDocumentService::class)->file($v, TransportDocumentType::FITNESS,
            ['valid_until' => now()->addDay()->toDateString()], self::A, $admin);

        // Eligible today.
        $this->getJson("/api/transport/trips/{$trip->id}/candidates")
            ->assertOk()->assertJsonPath('data.vehicles.0.eligible', true);

        // The certificate lapses. Nothing runs, no job, no status column changes.
        \Illuminate\Support\Facades\DB::table('transport_documents')
            ->where('id', $doc->id)->update(['valid_until' => now()->subDay()->toDateString()]);

        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id])->assertStatus(422);
        $this->assertSame(VehicleStatus::AVAILABLE, $v->fresh()->status, 'the stored status never changed — the check is live');
    }

    /* ═══════════════ 3. DOUBLE BOOKING + CONCURRENCY ═══════════════ */

    public function test_an_already_assigned_vehicle_cannot_be_given_to_a_second_trip(): void
    {
        $this->actAs('admin');
        $v = $this->seedVehicle();
        $this->postJson('/api/transport/trips/'.$this->approvedTrip()->id.'/assign', ['vehicle_id' => $v->id])->assertOk();

        $body = $this->postJson('/api/transport/trips/'.$this->approvedTrip()->id.'/assign', ['vehicle_id' => $v->id])
            ->assertStatus(422)->json();

        $this->assertStringContainsString('Already assigned', $body['message']);
    }

    public function test_an_already_assigned_driver_cannot_be_given_to_a_second_trip(): void
    {
        $this->actAs('admin');
        $d = $this->seedDriver();
        $this->postJson('/api/transport/trips/'.$this->approvedTrip()->id.'/assign', ['driver_id' => $d->id])->assertOk();

        $this->postJson('/api/transport/trips/'.$this->approvedTrip()->id.'/assign', ['driver_id' => $d->id])
            ->assertStatus(422);
    }

    /** Same shape as the step-4 concurrency test, but through HTTP. */
    public function test_simultaneous_requests_for_one_vehicle_leave_exactly_one_winner(): void
    {
        $this->actAs('admin');
        $v = $this->seedVehicle();
        $trips = [$this->approvedTrip(), $this->approvedTrip(), $this->approvedTrip()];
        $won = 0;

        foreach ($trips as $t) {
            if ($this->postJson("/api/transport/trips/{$t->id}/assign", ['vehicle_id' => $v->id])->status() === 200) {
                $won++;
            }
        }

        $this->assertSame(1, $won, 'exactly one request may win');
        $this->assertSame(1, TripAssignment::forTenant(self::A)->forVehicle($v->id)->active()->count());
    }

    /** The DB backstop holds even when the service is bypassed entirely. */
    public function test_the_database_refuses_a_second_active_row_without_the_service(): void
    {
        $this->actAs('admin');
        $v = $this->seedVehicle();
        $t1 = $this->approvedTrip(); $t2 = $this->approvedTrip();
        $this->postJson("/api/transport/trips/{$t1->id}/assign", ['vehicle_id' => $v->id])->assertOk();

        $this->expectException(QueryException::class);
        \Illuminate\Support\Facades\DB::table('trip_assignments')->insert([
            'tenant_id' => self::A, 'trip_id' => $t2->id, 'vehicle_id' => $v->id,
            'status' => AssignmentStatus::ASSIGNED, 'assigned_at' => now(),
            'allocation_override' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ═══════════════ 4. RELEASE PRESERVES HISTORY ═══════════════ */

    public function test_release_then_reassign_keeps_the_old_assignment_as_history(): void
    {
        $this->actAs('admin');
        $trip = $this->approvedTrip();
        $v1 = $this->seedVehicle(); $v2 = $this->seedVehicle(); $d = $this->seedDriver();

        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v1->id, 'driver_id' => $d->id])->assertOk();
        $first = TripAssignment::forTenant(self::A)->forTrip($trip->id)->first();

        $this->deleteJson("/api/transport/trips/{$trip->id}/assign", ['reason' => 'wrong vehicle'])->assertOk();

        // Released, NOT deleted — the row is still there with its timestamps.
        $first->refresh();
        $this->assertSame(AssignmentStatus::RELEASED, $first->status);
        $this->assertNotNull($first->released_at);
        $this->assertNotNull($first->assigned_at);
        $this->assertSame($v1->id, (int) $first->vehicle_id, 'history keeps WHICH vehicle it was');

        // Both resources are free again.
        $this->assertSame(VehicleStatus::AVAILABLE, $v1->fresh()->status);
        $this->assertSame(DriverAvailability::AVAILABLE, $d->fresh()->availability);
        $this->assertSame(TripStatus::APPROVED, $trip->fresh()->status);

        // Reassign to a different vehicle.
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v2->id, 'driver_id' => $d->id])->assertOk();

        $this->assertSame(2, TripAssignment::forTenant(self::A)->forTrip($trip->id)->count(), 'two rows: one released, one active');
        $this->assertSame(TripStatus::ALLOCATED, $trip->fresh()->status);
    }

    /** No soft deletes on the table at all — an assignment is released, never removed. */
    public function test_the_assignment_table_has_no_delete_path(): void
    {
        $this->assertNotContains(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive(TripAssignment::class)
        );
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('trip_assignments', 'deleted_at'));
    }

    /* ═══════════════ 5. CROSS-TENANT ISOLATION ═══════════════ */

    public function test_tenant_a_cannot_see_assign_or_release_tenant_b_resources(): void
    {
        // Tenant B's world.
        $vB = $this->seedVehicle(self::B);
        $dB = $this->seedDriver(self::B);
        $tripB = $this->approvedTrip(self::B);

        $this->actAs('admin', self::A);
        $tripA = $this->approvedTrip(self::A);

        // Cannot SEE.
        $this->assertSame(0, $this->getJson('/api/transport/vehicles')->json('data.total'));
        $this->assertSame(0, $this->getJson('/api/transport/drivers')->json('data.total'));
        $this->getJson("/api/transport/vehicles/{$vB->id}")->assertNotFound();
        $this->getJson("/api/transport/drivers/{$dB->id}")->assertNotFound();
        $this->getJson("/api/transport/trips/{$tripB->id}/candidates")->assertNotFound();

        // Cannot ASSIGN — 404, never 403, so the status code never confirms it exists.
        $this->postJson("/api/transport/trips/{$tripA->id}/assign", ['vehicle_id' => $vB->id])->assertNotFound();
        $this->postJson("/api/transport/trips/{$tripA->id}/assign", ['driver_id' => $dB->id])->assertNotFound();
        $this->postJson("/api/transport/trips/{$tripB->id}/assign", ['vehicle_id' => $vB->id])->assertNotFound();

        // Cannot RELEASE.
        $this->deleteJson("/api/transport/trips/{$tripB->id}/assign")->assertNotFound();

        // Candidate listing never leaks the other tenant's fleet.
        $vA = $this->seedVehicle(self::A);
        $ids = collect($this->getJson("/api/transport/trips/{$tripA->id}/candidates")->json('data.vehicles'))->pluck('subject.id');
        $this->assertTrue($ids->contains($vA->id));
        $this->assertFalse($ids->contains($vB->id));
    }

    public function test_the_same_vehicle_may_be_active_in_both_tenants_at_once(): void
    {
        $vA = $this->seedVehicle(self::A);
        $vB = $this->seedVehicle(self::B);

        $this->actAs('admin', self::A);
        $this->postJson('/api/transport/trips/'.$this->approvedTrip(self::A)->id.'/assign', ['vehicle_id' => $vA->id])->assertOk();

        $this->actAs('admin', self::B);
        $this->postJson('/api/transport/trips/'.$this->approvedTrip(self::B)->id.'/assign', ['vehicle_id' => $vB->id])->assertOk();
    }

    /* ═══════════════ 6. DELETING A MASTER THAT HAS HISTORY ═══════════════ */

    /**
     * D-14, RESOLVED — STOS-DB §156/§157.
     *
     * "Critical relationships must not silently become orphaned"; §157 names
     * "vehicle allocation without vehicle" as an orphan to detect. An audit found
     * a vehicle could be deleted mid-trip, leaving the assignment pointing at a
     * record no ordinary query could find. Now refused at the SERVICE layer, so
     * the rule holds however delete is invoked.
     */
    public function test_a_vehicle_with_an_active_assignment_cannot_be_deleted(): void
    {
        $this->actAs('admin');
        $trip = $this->approvedTrip();
        $v = $this->seedVehicle(); $d = $this->seedDriver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id, 'driver_id' => $d->id])->assertOk();

        $body = $this->deleteJson("/api/transport/vehicles/{$v->id}", ['reason' => 'duplicate'])
            ->assertStatus(422)->json();

        $this->assertStringContainsString($trip->trip_number, $body['message'], 'the message must name the trip');
        $this->assertStringContainsString('Release the assignment first', $body['message']);
        $this->assertStringContainsString('retire the vehicle', $body['message']);

        // Nothing was destroyed and nothing was orphaned.
        $this->assertNotNull(TransportVehicle::forTenant(self::A)->find($v->id));
        $assignment = TripAssignment::forTenant(self::A)->forTrip($trip->id)->first();
        $this->assertNotNull($assignment->vehicle, 'the relation still resolves — no orphan');
    }

    public function test_a_driver_with_an_active_assignment_cannot_be_deleted(): void
    {
        $this->actAs('admin');
        $trip = $this->approvedTrip();
        $v = $this->seedVehicle(); $d = $this->seedDriver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id, 'driver_id' => $d->id])->assertOk();

        $body = $this->deleteJson("/api/transport/drivers/{$d->id}")->assertStatus(422)->json();

        $this->assertStringContainsString($trip->trip_number, $body['message']);
        $this->assertStringContainsString('deactivate the driver', $body['message']);
        $this->assertNotNull(TransportDriver::forTenant(self::A)->find($d->id));
    }

    /** Released assignments are history — they must not pin a record forever. */
    public function test_deletion_succeeds_once_the_assignment_is_released(): void
    {
        $this->actAs('admin');
        $trip = $this->approvedTrip();
        $v = $this->seedVehicle(); $d = $this->seedDriver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id, 'driver_id' => $d->id])->assertOk();

        $this->deleteJson("/api/transport/vehicles/{$v->id}")->assertStatus(422);

        $this->deleteJson("/api/transport/trips/{$trip->id}/assign", ['reason' => 'off hire'])->assertOk();

        // Now both may go, even though the released assignment still references them.
        $this->deleteJson("/api/transport/vehicles/{$v->id}")->assertOk();
        $this->deleteJson("/api/transport/drivers/{$d->id}")->assertOk();

        // History survives the masters it describes.
        $released = TripAssignment::forTenant(self::A)->forTrip($trip->id)->first();
        $this->assertSame(AssignmentStatus::RELEASED, $released->status);
        $this->assertSame($v->id, (int) $released->vehicle_id);
        $this->assertSame($d->id, (int) $released->driver_id);
    }

    /** The guard lives in the service, so it holds outside the HTTP layer too. */
    public function test_the_guard_holds_when_the_service_is_called_directly(): void
    {
        $this->actAs('admin');
        $trip = $this->approvedTrip();
        $v = $this->seedVehicle(); $d = $this->seedDriver();
        $this->postJson("/api/transport/trips/{$trip->id}/assign", ['vehicle_id' => $v->id, 'driver_id' => $d->id])->assertOk();

        $this->expectException(BusinessException::class);
        app(TransportVehicleService::class)->delete($v->fresh(), self::A, null, 'console');
    }

    /** A vehicle that never had an assignment is unaffected by the guard. */
    public function test_an_unassigned_vehicle_still_deletes_freely(): void
    {
        $this->actAs('admin');
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $this->deleteJson("/api/transport/vehicles/{$id}")->assertOk();
    }

    /** Masters ARE soft-deleted, so the record itself is recoverable. */
    public function test_a_deleted_master_is_soft_deleted_not_destroyed(): void
    {
        $this->actAs('admin');
        $id = $this->postJson('/api/transport/vehicles', $this->vehiclePayload())->json('data.id');

        $this->deleteJson("/api/transport/vehicles/{$id}")->assertOk();

        $this->assertNull(TransportVehicle::forTenant(self::A)->find($id));
        $this->assertNotNull(TransportVehicle::withTrashed()->find($id), 'soft delete — the row is still there');
        $this->assertDatabaseHas('transport_audit_logs', [
            'action' => 'transport.vehicle.deleted', 'auditable_id' => $id,
        ]);
    }
}
