<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Transport\TransportPermissionService;
use App\Support\Transport\TransportPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SNG-TRN-009 step 3 — PERM-004, "Trip | assign".
 *
 * The registry row, verbatim:
 *   PERM-004 | Trip | assign | Y | Y | Y | N | N | N | N | N | Y
 *   columns:            Owner Ops Disp Acc App Drv Cust Supp Admin
 *
 * Tested at two levels on purpose. The matrix test walks all NINE registry
 * columns, which is the only way to prove PERM-004 in full — three of those roles
 * (Approver, Driver, Supplier) have no Sangoe identity that maps to them, so a
 * service-level test alone would silently skip a third of the row. The service
 * and middleware tests then prove the mapped identities behave the same way in
 * the real request path.
 */
class TransportAssignPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Alpha Transport', 'slug' => 'alpha-transport',
            'subdomain' => 'alpha-transport', 'status' => 'active',
        ])->save();
    }

    private function user(string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT,
            'name' => ucfirst($role).' User',
            'role' => $role,
            'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('x'),
            'status' => 'active',
        ]);
    }

    /* ══════════ 1. The registry row itself — all nine columns ══════════ */

    /** @return array<string, array{0:string,1:bool}> */
    public static function perm004Rows(): array
    {
        return [
            'Owner may assign'       => [TransportPermission::ROLE_OWNER, true],
            'Operations may assign'  => [TransportPermission::ROLE_OPERATIONS, true],
            'Dispatcher may assign'  => [TransportPermission::ROLE_DISPATCHER, true],
            'Admin may assign'       => [TransportPermission::ROLE_ADMIN, true],
            'Accounts may NOT'       => [TransportPermission::ROLE_ACCOUNTS, false],
            'Approver may NOT'       => [TransportPermission::ROLE_APPROVER, false],
            'Driver may NOT'         => [TransportPermission::ROLE_DRIVER, false],
            'Customer may NOT'       => [TransportPermission::ROLE_CUSTOMER, false],
            'Supplier may NOT'       => [TransportPermission::ROLE_SUPPLIER, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('perm004Rows')]
    public function test_perm_004_matrix_row_is_encoded_exactly(string $stosRole, bool $allowed): void
    {
        $scope = TransportPermission::scopeFor(TransportPermission::TRIP_ASSIGN, $stosRole);

        if ($allowed) {
            $this->assertSame(
                TransportPermission::SCOPE_ALL, $scope,
                "PERM-004 grants {$stosRole} 'Y', which is full scope"
            );
        } else {
            $this->assertNull(
                $scope,
                "PERM-004 gives {$stosRole} 'N' — it must hold no scope at all"
            );
        }
    }

    public function test_the_permission_key_is_the_string_the_api_registry_names(): void
    {
        // API-004's Permission column reads exactly this. A typo here would gate
        // the endpoint on a key nothing grants, which fails closed but silently.
        $this->assertSame('transport.trip.assign', TransportPermission::TRIP_ASSIGN);
        $this->assertTrue(TransportPermission::isPermission(TransportPermission::TRIP_ASSIGN));
        $this->assertContains(TransportPermission::TRIP_ASSIGN, TransportPermission::all());
    }

    /**
     * PERM-004 and PERM-002 happen to grant the same four roles. They are still
     * separate rows, and nothing may collapse them — PERM-003 (approve) and
     * PERM-005 (close) already drop Dispatcher, so "trip actions share a row" is
     * an assumption the registry disproves.
     */
    public function test_assign_is_a_distinct_row_from_view_and_create(): void
    {
        $assign = TransportPermission::MATRIX[TransportPermission::TRIP_ASSIGN];
        $view   = TransportPermission::MATRIX[TransportPermission::TRIP_VIEW];

        $this->assertNotSame($view, $assign, 'PERM-001 and PERM-004 are different rows');
        // The asymmetry that matters: a supplier may see a trip, never crew one.
        $this->assertSame(TransportPermission::SCOPE_ASSIGNED, $view[TransportPermission::ROLE_SUPPLIER]);
        $this->assertArrayNotHasKey(TransportPermission::ROLE_SUPPLIER, $assign);
        // Accounts and Approver can view but not assign.
        $this->assertArrayHasKey(TransportPermission::ROLE_ACCOUNTS, $view);
        $this->assertArrayNotHasKey(TransportPermission::ROLE_ACCOUNTS, $assign);
        $this->assertArrayNotHasKey(TransportPermission::ROLE_APPROVER, $assign);
    }

    /* ══════════ 2. Real Sangoe identities through the service ══════════ */

    public function test_mapped_identities_that_may_assign(): void
    {
        $svc = app(TransportPermissionService::class);

        foreach ([
            'admin'                            => ['admin', null],
            'staff (maps to Operations)'       => ['staff', null],
            'staff + transport_owner'          => ['staff', 'transport_owner'],
            'staff + transport_operations'     => ['staff', 'transport_operations'],
            'staff + transport_dispatcher'     => ['staff', 'transport_dispatcher'],
        ] as $label => [$role, $internal]) {
            $this->assertTrue(
                $svc->can($this->user($role, $internal), TransportPermission::TRIP_ASSIGN),
                "{$label} should hold transport.trip.assign"
            );
        }
    }

    public function test_mapped_identities_that_may_not_assign(): void
    {
        $svc = app(TransportPermissionService::class);

        foreach ([
            'staff + accounts (PERM-004 Accounts = N)' => ['staff', 'accounts'],
            'client (maps to Customer)'                => ['client', null],
            'third_party_vendor (unmapped)'            => ['third_party_vendor', null],
            'vendor (unmapped)'                        => ['vendor', null],
            'company (unmapped)'                       => ['company', null],
        ] as $label => [$role, $internal]) {
            $this->assertFalse(
                $svc->can($this->user($role, $internal), TransportPermission::TRIP_ASSIGN),
                "{$label} must NOT hold transport.trip.assign"
            );
        }
    }

    /**
     * The accounts case is the one worth stating separately: this is a user who
     * legitimately works on the trip, holds trip.view, and is still refused.
     */
    public function test_accounts_may_view_a_trip_but_never_crew_one(): void
    {
        $svc = app(TransportPermissionService::class);
        $accounts = $this->user('staff', 'accounts');

        $this->assertSame(TransportPermission::ROLE_ACCOUNTS, $svc->stosRole($accounts));
        $this->assertTrue($svc->can($accounts, TransportPermission::TRIP_VIEW));
        $this->assertFalse($svc->can($accounts, TransportPermission::TRIP_ASSIGN));
    }

    public function test_grants_payload_advertises_assign_only_to_holders(): void
    {
        $svc = app(TransportPermissionService::class);

        $this->assertArrayHasKey(
            TransportPermission::TRIP_ASSIGN,
            $svc->grantsFor($this->user('staff', 'transport_dispatcher'))
        );
        $this->assertArrayNotHasKey(
            TransportPermission::TRIP_ASSIGN,
            $svc->grantsFor($this->user('staff', 'accounts'))
        );
        // A UI that hid this would show a crew button the API refuses.
        $this->assertSame([], $svc->grantsFor($this->user('third_party_vendor')));
    }

    /* ══════════ 3. The request path ══════════ */

    public function test_middleware_admits_a_dispatcher_and_refuses_accounts(): void
    {
        Route::middleware(['auth:sanctum', 'transport.permission:'.TransportPermission::TRIP_ASSIGN])
            ->get('/_test/assign', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($this->user('staff', 'transport_dispatcher'));
        $this->getJson('/_test/assign')->assertOk();

        Sanctum::actingAs($this->user('staff', 'accounts'));
        $this->getJson('/_test/assign')->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_assign(): void
    {
        Route::middleware(['transport.permission:'.TransportPermission::TRIP_ASSIGN])
            ->get('/_test/assign-anon', fn () => response()->json(['ok' => true]));

        $this->getJson('/_test/assign-anon')->assertUnauthorized();
    }

    /**
     * Adding a permission must not widen an existing one. This pins the whole
     * matrix so a future edit to one row cannot quietly grant another.
     */
    public function test_adding_assign_did_not_widen_any_other_permission(): void
    {
        $svc = app(TransportPermissionService::class);
        $client = $this->user('client');
        $vendor = $this->user('third_party_vendor');

        foreach (TransportPermission::all() as $permission) {
            $this->assertFalse(
                $svc->can($vendor, $permission),
                "an unmapped vendor identity must hold nothing, but holds {$permission}"
            );
        }

        // A client keeps exactly the two read grants it had, and nothing else.
        $this->assertSame(
            [TransportPermission::TRIP_VIEW, TransportPermission::ORDER_VIEW],
            array_keys($svc->grantsFor($client))
        );
    }
}
