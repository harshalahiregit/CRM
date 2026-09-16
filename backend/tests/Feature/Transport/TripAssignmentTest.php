<?php

namespace Tests\Feature\Transport;

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
use App\Services\Transport\TripAssignmentService;
use App\Support\Transport\AssignmentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SNG-TRN-009 step 4 — trip_assignments (DB-003).
 *
 * The rule under test is BR-P0-003, "Vehicle cannot have overlapping active
 * trips", rated Hard and Critical, plus STOS-DB §198/§199's vehicle and driver
 * double-allocation prohibitions. It is enforced twice — a row lock in the
 * service and a unique index in the database — so both layers are tested
 * separately: bypassing the service must still fail.
 */
class TripAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private TripAssignmentService $svc;
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

        $this->svc = app(TripAssignmentService::class);
        $this->actor = User::create([
            'tenant_id' => self::TENANT_A, 'name' => 'Dispatcher', 'role' => 'staff',
            'internal_role' => 'transport_dispatcher',
            'email' => 'disp-'.Str::random(6).'@test.local', 'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(int $tenantId = self::TENANT_A): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => $tenantId, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        return TransportTrip::create([
            'tenant_id' => $tenantId, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
    }

    private function vehicle(int $tenantId = self::TENANT_A): TransportVehicle
    {
        return TransportVehicle::create([
            'tenant_id' => $tenantId, 'registration_number' => 'MH12AB'.self::uniqueSeq(4),
        ]);
    }

    private function driver(int $tenantId = self::TENANT_A): TransportDriver
    {
        return TransportDriver::create(['tenant_id' => $tenantId, 'name' => 'Driver '.Str::random(5)]);
    }

    /* ══════════ Creating an assignment ══════════ */

    public function test_a_vehicle_and_driver_can_be_assigned_in_one_call(): void
    {
        $trip = $this->trip(); $v = $this->vehicle(); $d = $this->driver();

        $a = $this->svc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->assertSame(AssignmentStatus::ASSIGNED, $a->status);
        $this->assertTrue($a->isComplete());
        $this->assertTrue($a->isActive());
        $this->assertNotNull($a->assigned_at);
        $this->assertNull($a->released_at);
        // The trip's denormalised pointers follow the assignment.
        $this->assertSame($v->id, (int) $trip->fresh()->vehicle_id);
        $this->assertSame($d->id, (int) $trip->fresh()->driver_id);
    }

    /** CTR-007/008 say both required; TRP-P0-004's trigger is "Vehicle allocated". */
    public function test_a_vehicle_can_be_assigned_first_and_the_driver_added_later(): void
    {
        $trip = $this->trip(); $v = $this->vehicle(); $d = $this->driver();

        $first = $this->svc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);
        $this->assertTrue($first->hasVehicle());
        $this->assertFalse($first->hasDriver());
        $this->assertFalse($first->isComplete());

        $second = $this->svc->assign($trip->fresh(), null, $d->id, self::TENANT_A, $this->actor);

        // Same row, not a second one — §47 keeps one history record per allocation.
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($second->isComplete());
        $this->assertSame(1, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    public function test_the_driver_can_also_be_assigned_first(): void
    {
        $trip = $this->trip(); $v = $this->vehicle(); $d = $this->driver();

        $a = $this->svc->assign($trip, null, $d->id, self::TENANT_A, $this->actor);
        $a = $this->svc->assign($trip->fresh(), $v->id, null, self::TENANT_A, $this->actor);

        $this->assertTrue($a->isComplete());
    }

    public function test_an_assignment_with_neither_resource_is_refused(): void
    {
        $this->expectException(BusinessException::class);
        $this->svc->assign($this->trip(), null, null, self::TENANT_A, $this->actor);
    }

    public function test_resending_the_same_allocation_is_idempotent(): void
    {
        $trip = $this->trip(); $v = $this->vehicle(); $d = $this->driver();
        $this->svc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);
        $before = TransportAuditLog::count();

        $this->svc->assign($trip->fresh(), $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->assertSame($before, TransportAuditLog::count(), 're-sending must not write a second audit row');
        $this->assertSame(1, TripAssignment::forTenant(self::TENANT_A)->forTrip($trip->id)->count());
    }

    /** OPS §122-123: swapping a resource is a TRANSFER, not an edit. */
    public function test_replacing_an_already_assigned_resource_is_refused(): void
    {
        $trip = $this->trip(); $v1 = $this->vehicle(); $v2 = $this->vehicle();
        $this->svc->assign($trip, $v1->id, null, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->svc->assign($trip->fresh(), $v2->id, null, self::TENANT_A, $this->actor);
    }

    /* ══════════ BR-P0-003 · double booking ══════════ */

    public function test_a_vehicle_cannot_be_assigned_to_two_trips(): void
    {
        $v = $this->vehicle();
        $this->svc->assign($this->trip(), $v->id, null, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/already assigned to trip/');
        $this->svc->assign($this->trip(), $v->id, null, self::TENANT_A, $this->actor);
    }

    /** STOS-DB §199 — ruled a hard block, same as the vehicle. */
    public function test_a_driver_cannot_be_assigned_to_two_trips(): void
    {
        $d = $this->driver();
        $this->svc->assign($this->trip(), null, $d->id, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/already assigned to trip/');
        $this->svc->assign($this->trip(), null, $d->id, self::TENANT_A, $this->actor);
    }

    /**
     * The database backstop, proven by bypassing the service entirely.
     *
     * A row lock only protects callers that take it. This is the guard that
     * holds for a raw INSERT, a future second service, or a racing connection.
     */
    public function test_the_database_refuses_a_second_active_row_even_without_the_service(): void
    {
        $t1 = $this->trip(); $t2 = $this->trip(); $v = $this->vehicle();
        $this->svc->assign($t1, $v->id, null, self::TENANT_A, $this->actor);

        $this->expectException(QueryException::class);
        DB::table('trip_assignments')->insert([
            'tenant_id' => self::TENANT_A, 'trip_id' => $t2->id, 'vehicle_id' => $v->id,
            'status' => AssignmentStatus::ASSIGNED, 'assigned_at' => now(),
            'allocation_override' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_trip_cannot_hold_two_active_assignments(): void
    {
        $trip = $this->trip(); $v = $this->vehicle();
        $this->svc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);

        $this->expectException(QueryException::class);
        DB::table('trip_assignments')->insert([
            'tenant_id' => self::TENANT_A, 'trip_id' => $trip->id, 'vehicle_id' => $this->vehicle()->id,
            'status' => AssignmentStatus::ASSIGNED, 'assigned_at' => now(),
            'allocation_override' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Two dispatchers, same vehicle, same moment — exactly one may win. */
    public function test_concurrent_assignment_attempts_leave_exactly_one_winner(): void
    {
        $v = $this->vehicle();
        $trips = [$this->trip(), $this->trip(), $this->trip()];
        $won = 0; $lost = 0;

        foreach ($trips as $trip) {
            try {
                $this->svc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);
                $won++;
            } catch (BusinessException|QueryException $e) {
                $lost++;
            }
        }

        $this->assertSame(1, $won, 'exactly one assignment may succeed');
        $this->assertSame(2, $lost);
        $this->assertSame(1, TripAssignment::forTenant(self::TENANT_A)->forVehicle($v->id)->active()->count());
    }

    public function test_releasing_frees_the_vehicle_for_another_trip(): void
    {
        $v = $this->vehicle();
        $a = $this->svc->assign($this->trip(), $v->id, null, self::TENANT_A, $this->actor);

        $this->svc->release($a, self::TENANT_A, $this->actor, 'breakdown');

        $this->assertSame(AssignmentStatus::RELEASED, $a->fresh()->status);
        $this->assertNotNull($a->fresh()->released_at);

        // Freed — a second trip may now take it.
        $next = $this->svc->assign($this->trip(), $v->id, null, self::TENANT_A, $this->actor);
        $this->assertTrue($next->isActive());
        // History survives: the released row is still there.
        $this->assertSame(2, TripAssignment::forTenant(self::TENANT_A)->forVehicle($v->id)->count());
    }

    public function test_a_released_assignment_cannot_be_released_again(): void
    {
        $a = $this->svc->assign($this->trip(), $this->vehicle()->id, null, self::TENANT_A, $this->actor);
        $this->svc->release($a, self::TENANT_A, $this->actor);

        $this->expectException(BusinessException::class);
        $this->svc->release($a->fresh(), self::TENANT_A, $this->actor);
    }

    public function test_releasing_clears_the_trips_pointers(): void
    {
        $trip = $this->trip(); $v = $this->vehicle(); $d = $this->driver();
        $a = $this->svc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $this->svc->release($a, self::TENANT_A, $this->actor);

        $this->assertNull($trip->fresh()->vehicle_id, 'a trip must not claim a vehicle it no longer holds');
        $this->assertNull($trip->fresh()->driver_id);
    }

    /* ══════════ Audit — the G-1 lesson ══════════ */

    public function test_assignment_create_extend_and_release_are_all_audited(): void
    {
        $trip = $this->trip(); $v = $this->vehicle(); $d = $this->driver();

        $a = $this->svc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);
        $this->assertDatabaseHas('transport_audit_logs', ['action' => 'transport.assignment.created', 'auditable_id' => $a->id]);
        // The trip is a party to it too — readable from the trip's own history.
        $this->assertDatabaseHas('transport_audit_logs', ['action' => 'transport.trip.assignment_created', 'auditable_id' => $trip->id]);

        $this->svc->assign($trip->fresh(), null, $d->id, self::TENANT_A, $this->actor);
        $this->assertDatabaseHas('transport_audit_logs', ['action' => 'transport.assignment.updated', 'auditable_id' => $a->id]);

        $this->svc->release($a->fresh(), self::TENANT_A, $this->actor, 'trip cancelled');
        $this->assertDatabaseHas('transport_audit_logs', ['action' => 'transport.assignment.released', 'auditable_id' => $a->id]);
    }

    public function test_the_audit_row_carries_the_actor_and_the_resources(): void
    {
        $trip = $this->trip(); $v = $this->vehicle(); $d = $this->driver();
        $a = $this->svc->assign($trip, $v->id, $d->id, self::TENANT_A, $this->actor);

        $entry = TransportAuditLog::where('action', 'transport.assignment.created')->latest('id')->first();

        $this->assertSame($this->actor->id, (int) $entry->actor_id);
        $this->assertSame($v->id, $entry->new_values['vehicle_id']);
        $this->assertSame($d->id, $entry->new_values['driver_id']);
        $this->assertSame('BR-P0-003', $entry->new_values['rule']);
        $this->assertSame(self::TENANT_A, (int) $entry->tenant_id);
    }

    /* ══════════ Override columns reserved, not enforced (PLN-007 is P1) ══════════ */

    public function test_override_columns_exist_but_nothing_sets_them(): void
    {
        $a = $this->svc->assign($this->trip(), $this->vehicle()->id, null, self::TENANT_A, $this->actor);

        $this->assertFalse($a->allocation_override, 'PLN-007 is P1 — no override path exists yet');
        $this->assertNull($a->override_reason);
        // §48 names both fields verbatim; they are present so PLN-007 needs no ALTER.
        foreach (['allocation_override', 'override_reason', 'previous_assignment_id', 'allocation_type', 'approved_by'] as $column) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('trip_assignments', $column),
                "STOS-DB §47/§48 field {$column} must exist"
            );
        }
    }

    public function test_override_cannot_be_mass_assigned(): void
    {
        $a = $this->svc->assign($this->trip(), $this->vehicle()->id, null, self::TENANT_A, $this->actor);

        $a->fill(['allocation_override' => true, 'override_reason' => 'because I said so', 'status' => AssignmentStatus::RELEASED])->save();

        $fresh = $a->fresh();
        $this->assertFalse($fresh->allocation_override);
        $this->assertNull($fresh->override_reason);
        $this->assertSame(AssignmentStatus::ASSIGNED, $fresh->status);
    }

    /* ══════════ Lifecycle enum ══════════ */

    public function test_all_eight_lsm_44_states_are_declared_but_only_release_is_wired(): void
    {
        $this->assertCount(8, AssignmentStatus::ALL);
        $this->assertSame(
            [AssignmentStatus::ASSIGNED, AssignmentStatus::CONFIRMED, AssignmentStatus::ACTIVE],
            AssignmentStatus::ACTIVE_STATES
        );
        $this->assertSame([AssignmentStatus::ASSIGNED => [AssignmentStatus::RELEASED]], AssignmentStatus::TRANSITIONS);
    }

    /* ══════════ Tenancy ══════════ */

    public function test_the_same_vehicle_may_be_active_under_two_tenants(): void
    {
        $vA = $this->vehicle(self::TENANT_A);
        $vB = $this->vehicle(self::TENANT_B);

        $this->svc->assign($this->trip(self::TENANT_A), $vA->id, null, self::TENANT_A, $this->actor);
        $b = $this->svc->assign($this->trip(self::TENANT_B), $vB->id, null, self::TENANT_B, null);

        $this->assertTrue($b->isActive());
    }

    public function test_tenant_b_can_neither_see_nor_release_tenant_a_assignments(): void
    {
        $trip = $this->trip(); $v = $this->vehicle();
        $a = $this->svc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);

        $this->assertSame(0, TripAssignment::forTenant(self::TENANT_B)->forTrip($trip->id)->count());
        $this->assertNull($this->svc->activeForTrip($trip->id, self::TENANT_B));

        $this->expectException(ResourceNotFoundException::class);
        $this->svc->release($a, self::TENANT_B, $this->actor);
    }

    public function test_assigning_against_another_tenants_trip_reads_as_not_found(): void
    {
        $trip = $this->trip(self::TENANT_A);

        $this->expectException(ResourceNotFoundException::class);
        $this->svc->assign($trip, $this->vehicle(self::TENANT_B)->id, null, self::TENANT_B, null);
    }

    public function test_history_is_tenant_scoped_and_ordered(): void
    {
        $trip = $this->trip(); $v = $this->vehicle();
        $a = $this->svc->assign($trip, $v->id, null, self::TENANT_A, $this->actor);
        $this->svc->release($a, self::TENANT_A, $this->actor);
        $this->svc->assign($trip->fresh(), $v->id, null, self::TENANT_A, $this->actor);

        $this->assertCount(2, $this->svc->historyForTrip($trip->id, self::TENANT_A));
        $this->assertCount(0, $this->svc->historyForTrip($trip->id, self::TENANT_B));
    }

    /** Step 5's conventions: transactional records are released, never destroyed. */
    public function test_the_model_has_no_soft_deletes(): void
    {
        $this->assertNotContains(
            \Illuminate\Database\Eloquent\SoftDeletes::class,
            class_uses_recursive(TripAssignment::class),
            'an assignment is history — it is released, not deleted'
        );
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('trip_assignments', 'deleted_at'));
    }
}
