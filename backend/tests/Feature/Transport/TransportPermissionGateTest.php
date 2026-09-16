<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Transport\Access\AccessGate;
use Transport\Access\PermissionRegistry;

/**
 * SNG-TRN-028 — transport permissions refuse by default, and say why.
 *
 * The acceptance criterion in the ticket is "unauthorized actions blocked and
 * audited", and the QA case (QA-012) is "user lacks permission → request denied
 * and audited". Both are about the denial, not the grant, which is why most of
 * what follows asserts that something does NOT happen.
 *
 * Four of these tests exist because the registry is incomplete, and would be
 * deleted rather than changed if it were completed:
 *
 *   - an action the registry never defined is refused (trip.dispatch)
 *   - an asterisked cell is refused (PERM-013 for Admin)
 *   - an account whose role has not been mapped is refused
 *   - a mapping onto a role that does not exist grants nothing
 *
 * They are the difference between a gap that is handled and a gap that is
 * waiting to be discovered in production.
 */
class TransportPermissionGateTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'T', 'slug' => 'transport-perm', 'status' => 'active']);
        $this->tenantId = $tenant->id;

        config(['transport.roles.map' => []]);
    }

    private function user(string $role, ?string $internalRole = null): User
    {
        return User::create([
            'tenant_id'     => $this->tenantId,
            'name'          => ucfirst($internalRole ?: $role),
            'email'         => uniqid('u', false).'@transport.test',
            'password'      => Hash::make('x'),
            'role'          => $role,
            'internal_role' => $internalRole,
            'status'        => 'active',
        ]);
    }

    private function gate(): AccessGate
    {
        return app(AccessGate::class);
    }

    // ── The transcription itself ────────────────────────────────────────────

    /**
     * Step 11 defines thirteen permissions across nine roles. If either number
     * moves, the sheet changed and the change needs a registry record — not a
     * quiet edit here.
     */
    public function test_the_registry_matches_step_11(): void
    {
        $this->assertCount(13, PermissionRegistry::MATRIX, 'Step 11 defines PERM-001 to PERM-013');
        $this->assertCount(9, PermissionRegistry::ROLES);

        foreach (PermissionRegistry::MATRIX as $key => $row) {
            $this->assertMatchesRegularExpression('/^PERM-0\d{2}$/', $row['id'], "{$key} has no PERM id");
            $this->assertSame(
                PermissionRegistry::ROLES,
                array_keys($row['grants']),
                "{$key} does not carry a grant for every role, in sheet order"
            );
        }
    }

    // ── Refusals ────────────────────────────────────────────────────────────

    /** No role mapped means no access, for everyone but an administrator. */
    public function test_an_unmapped_account_may_do_nothing(): void
    {
        $dispatcher = $this->user('staff', 'dispatcher');

        $this->assertNull($this->gate()->scope($dispatcher, 'trip', 'view'));
        $this->assertFalse($this->gate()->allows($dispatcher, 'trip', 'create'));
        $this->assertStringContainsString(
            'has not been agreed',
            $this->gate()->denialReason($dispatcher, 'trip', 'view')
        );
    }

    /** A mapping onto a role the sheet does not define grants nothing. */
    public function test_a_mistyped_mapping_does_not_grant_access(): void
    {
        config(['transport.roles.map' => ['dispatcher' => 'dispatchers']]);

        $user = $this->user('staff', 'dispatcher');

        $this->assertNull($this->gate()->scope($user, 'trip', 'assign'));
    }

    /**
     * Deny-by-default for actions the specifications need and the registry
     * never granted. Dispatch is the sharpest: SNG-TRN-009 dispatches trips and
     * no PERM row permits it.
     */
    public function test_an_action_the_registry_never_defined_is_refused(): void
    {
        $admin = $this->user('admin');

        $this->assertFalse($this->gate()->allows($admin, 'trip', 'dispatch'));
        $this->assertStringContainsString(
            'No permission exists for trip.dispatch',
            $this->gate()->denialReason($admin, 'trip', 'dispatch')
        );
        $this->assertStringContainsString(
            'SNG-TRN-009',
            $this->gate()->denialReason($admin, 'trip', 'dispatch'),
            'the denial should name the gap, not just refuse'
        );
    }

    /**
     * PERM-013 marks Admin with an asterisk whose footnote is not in the
     * package. Refused until it is: an asterisk on "may modify the canonical
     * registry" is more likely to mean "under change control" than "freely".
     */
    public function test_the_asterisked_registry_permission_is_refused_for_admin(): void
    {
        $admin = $this->user('admin');

        $this->assertTrue($this->gate()->allows($admin, 'trip', 'close'), 'admin holds ordinary permissions');
        $this->assertFalse($this->gate()->allows($admin, 'registry', 'modify'));
    }

    // ── Grants, and how wide they are ───────────────────────────────────────

    /** An administrator resolves without any configuration being written. */
    public function test_an_administrator_needs_no_mapping(): void
    {
        $admin = $this->user('admin');

        $this->assertSame(PermissionRegistry::FULL, $this->gate()->scope($admin, 'trip', 'approve'));
    }

    /**
     * PERM-001 gives a driver `Own`, not `Y`. A gate that answered true here
     * and left the caller to query every row would hand one driver the whole
     * company's trips, which is the failure the column exists to prevent.
     */
    public function test_a_driver_sees_only_their_own_trips(): void
    {
        config(['transport.roles.map' => ['driver' => PermissionRegistry::ROLE_DRIVER]]);

        $driver = $this->user('staff', 'driver');

        $this->assertSame(PermissionRegistry::OWN, $this->gate()->scope($driver, 'trip', 'view'));
        $this->assertNull($this->gate()->scope($driver, 'trip', 'create'), 'PERM-002 gives a driver N');
        $this->assertSame(PermissionRegistry::OWN, $this->gate()->scope($driver, 'pod', 'submit'));
    }

    /** PERM-010 gives a supplier `Assigned` — narrower again than `Own`. */
    public function test_a_supplier_is_limited_to_assigned_work(): void
    {
        config(['transport.roles.map' => ['purchase_vendor' => PermissionRegistry::ROLE_SUPPLIER]]);

        $supplier = $this->user('purchase_vendor');

        $this->assertSame(PermissionRegistry::ASSIGNED, $this->gate()->scope($supplier, 'pod', 'submit'));
        $this->assertSame(PermissionRegistry::ASSIGNED, $this->gate()->scope($supplier, 'trip', 'view'));
        $this->assertNull($this->gate()->scope($supplier, 'advance', 'approve'));
    }

    /**
     * Separation of money from operations, as the sheet draws it: Operations
     * may request an advance and may not approve one. PERM-006 against
     * PERM-007, which is a financial control and not a convenience.
     */
    public function test_operations_may_request_an_advance_but_not_approve_it(): void
    {
        config(['transport.roles.map' => ['operations' => PermissionRegistry::ROLE_OPERATIONS]]);

        $ops = $this->user('staff', 'operations');

        $this->assertTrue($this->gate()->allows($ops, 'advance', 'request'));
        $this->assertFalse($this->gate()->allows($ops, 'advance', 'approve'));
        $this->assertFalse($this->gate()->allows($ops, 'expense', 'approve'));
        $this->assertFalse($this->gate()->allows($ops, 'collection', 'record'));
    }

    /** Portal identities are admitted here, unlike the host's staff-only grid. */
    public function test_a_customer_portal_account_can_be_granted_its_own_view(): void
    {
        config(['transport.roles.map' => ['client' => PermissionRegistry::ROLE_CUSTOMER]]);

        $customer = $this->user('client');

        $this->assertSame(PermissionRegistry::OWN, $this->gate()->scope($customer, 'trip', 'view'));
        $this->assertSame(PermissionRegistry::OWN, $this->gate()->scope($customer, 'controlroom', 'view'));
        $this->assertNull($this->gate()->scope($customer, 'trip', 'create'));
    }
}
