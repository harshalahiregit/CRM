<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportOrder;
use App\Models\Transport\TransportTrip;
use App\Models\User;
use App\Services\Transport\TransportPermissionService;
use App\Support\Transport\TransportPermission;
use App\Support\Transport\TripStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PERM-005 "Trip | close", and the derived PERM-004 mirror for delivery.
 *
 * The registry row, verbatim:
 *   PERM-005 | Trip | close | Y | Y | N | Y | Y | N | N | N | Y
 *   columns:                 Own Ops Dis Acc App Drv Cus Sup Adm
 *
 * ── THE DISPATCHER DENIAL IS THE POINT ────────────────────────────────────
 * PERM-003 (approve) and PERM-005 (close) are the ONLY two Trip rows that
 * exclude the Dispatcher, and they are the two commercial acts. The person who
 * moves the truck is not the person who signs the job off. Tested as a refusal
 * exactly as PERM-003's denial is, at the matrix, the service and the route.
 *
 * All nine columns are walked at the matrix level, because three of them
 * (Approver, Driver, Supplier) have no Sangoe identity that maps to them — a
 * service-level test alone would silently skip a third of the row.
 */
class TripClosurePermissionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Alpha', 'slug' => 'alpha',
            'subdomain' => 'alpha', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => ucfirst($role), 'role' => $role,
            'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]);
    }

    private function trip(): TransportTrip
    {
        $order = TransportOrder::create([
            'tenant_id' => self::TENANT, 'order_number' => 'TO-'.Str::random(8), 'customer_id' => 1,
            'pickup_location' => ['address' => 'A'], 'delivery_location' => ['address' => 'B'],
            'required_at' => now()->addDays(3), 'service_type' => 'Container Haulage',
        ]);

        $trip = TransportTrip::create([
            'tenant_id' => self::TENANT, 'order_id' => $order->id,
            'trip_number' => 'TRP-'.Str::random(8), 'customer_id' => 1,
        ]);
        $trip->forceFill(['status' => TripStatus::COLLECTION_PENDING])->save();

        return $trip->fresh();
    }

    /* ══════════ 1. PERM-005, all nine registry columns ══════════ */

    /** @return array<string, array{0:string,1:bool}> */
    public static function perm005(): array
    {
        return [
            'Owner may close'         => [TransportPermission::ROLE_OWNER, true],
            'Operations may close'    => [TransportPermission::ROLE_OPERATIONS, true],
            'Dispatcher may NOT'      => [TransportPermission::ROLE_DISPATCHER, false],
            'Accounts may close'      => [TransportPermission::ROLE_ACCOUNTS, true],
            'Approver may close'      => [TransportPermission::ROLE_APPROVER, true],
            'Driver may NOT'          => [TransportPermission::ROLE_DRIVER, false],
            'Customer may NOT'        => [TransportPermission::ROLE_CUSTOMER, false],
            'Supplier may NOT'        => [TransportPermission::ROLE_SUPPLIER, false],
            'Admin may close'         => [TransportPermission::ROLE_ADMIN, true],
        ];
    }

    /** @dataProvider perm005 */
    public function test_perm_005_matrix_row(string $role, bool $granted): void
    {
        $this->assertSame(
            $granted,
            TransportPermission::scopeFor(TransportPermission::TRIP_CLOSE, $role) !== null,
            "PERM-005 says $role ".($granted ? 'MAY' : 'may NOT').' close a trip',
        );
    }

    /* ══════════ 2. The same answer through a real identity ══════════ */

    public function test_a_dispatcher_identity_is_denied_by_the_service(): void
    {
        $svc = app(TransportPermissionService::class);
        $dispatcher = $this->user('staff', 'transport_dispatcher');

        // The same person who released the trip, and who may still dispatch it.
        $this->assertTrue($svc->can($dispatcher, TransportPermission::TRIP_DISPATCH));
        $this->assertTrue($svc->can($dispatcher, TransportPermission::TRIP_DELIVER));

        // And may not close it.
        $this->assertFalse($svc->can($dispatcher, TransportPermission::TRIP_CLOSE));
    }

    public function test_an_accounts_identity_is_the_mirror_image(): void
    {
        $svc = app(TransportPermissionService::class);
        $accounts = $this->user('staff', 'accounts');

        // PERM-005 grants Accounts; PERM-004 (and so our derived deliver key)
        // does not. The two roles cross over exactly at the point where the
        // trip stops being an operation and becomes a receivable.
        $this->assertTrue($svc->can($accounts, TransportPermission::TRIP_CLOSE));
        $this->assertFalse($svc->can($accounts, TransportPermission::TRIP_DELIVER));
        $this->assertFalse($svc->can($accounts, TransportPermission::TRIP_DISPATCH));
    }

    /* ══════════ 3. The route actually refuses ══════════ */

    public function test_a_dispatcher_is_refused_at_the_close_route(): void
    {
        Sanctum::actingAs($this->user('staff', 'transport_dispatcher'));

        $this->postJson('/api/transport/trips/'.$this->trip()->id.'/close', [
            'closure_reason' => 'Settled in full by NEFT.',
        ])->assertForbidden();
    }

    public function test_a_dispatcher_may_still_read_why_it_is_blocked(): void
    {
        // The GET is on TRIP_VIEW, not TRIP_CLOSE. Reading why a trip cannot
        // close is not closing it — TRP-P0-014's acceptance is that a user SEES
        // the blockers, and hiding them from the dispatcher would leave the one
        // person on the ground unable to tell the customer anything.
        Sanctum::actingAs($this->user('staff', 'transport_dispatcher'));

        $this->getJson('/api/transport/trips/'.$this->trip()->id.'/closure')->assertOk();
    }

    public function test_operations_reaches_the_endpoint_and_is_refused_on_the_merits(): void
    {
        // Not 403. The permission passes and the CONTROLS refuse — which is the
        // distinction that proves the gate and the business rule are separate.
        Sanctum::actingAs($this->user('staff', 'transport_operations'));

        $this->postJson('/api/transport/trips/'.$this->trip()->id.'/close', [
            'closure_reason' => 'Settled in full by NEFT.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    /* ══════════ 4. The contract, CTR-013 ══════════ */

    public function test_a_closure_reason_is_required_by_the_contract(): void
    {
        Sanctum::actingAs($this->user('staff', 'transport_operations'));

        $this->postJson('/api/transport/trips/'.$this->trip()->id.'/close', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('closure_reason');
    }

    public function test_the_waiver_field_is_refused_with_the_reason_it_is_refused(): void
    {
        // BR-P0-017 names a waiver AND the Owner role that may use it. Refusing
        // it silently would be the same as refusing an override nobody
        // specified, and these two are not the same thing.
        Sanctum::actingAs($this->user('staff', 'transport_operations'));

        $res = $this->postJson('/api/transport/trips/'.$this->trip()->id.'/close', [
            'closure_reason' => 'Writing this one off.',
            'waive'          => true,
        ])->assertStatus(422);

        $this->assertStringContainsString('BR-P0-017', $res->json('errors.waive.0'));
        $this->assertStringContainsString('not built yet', $res->json('errors.waive.0'));
    }

    /* ══════════ 5. Delivery's derived key ══════════ */

    public function test_the_deliver_key_mirrors_perm_004_and_not_perm_010(): void
    {
        // FRS TRP-P0-013 names "Driver/Delivery", but that row is POD capture.
        // A driver may submit their own proof (PERM-010, `own`) and may NOT
        // declare the trip delivered, because that unlocks billing downstream.
        $this->assertSame(
            TransportPermission::MATRIX[TransportPermission::TRIP_ASSIGN],
            TransportPermission::MATRIX[TransportPermission::TRIP_DELIVER],
            'transport.trip.deliver mirrors PERM-004 exactly',
        );

        $this->assertNull(TransportPermission::scopeFor(
            TransportPermission::TRIP_DELIVER, TransportPermission::ROLE_DRIVER,
        ));
        $this->assertNotNull(TransportPermission::scopeFor(
            TransportPermission::POD_SUBMIT, TransportPermission::ROLE_DRIVER,
        ));
    }
}
