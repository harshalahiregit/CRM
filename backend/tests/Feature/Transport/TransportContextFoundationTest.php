<?php

namespace Tests\Feature\Transport;

use App\Models\Tenant;
use App\Models\Transport\TransportAuditLog;
use App\Models\User;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportPermissionService;
use App\Support\Transport\TransportPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * SNG-TRN-001 — "Context exists and passes tenant isolation tests."
 *
 * That sentence is the ticket's whole acceptance criterion, so these tests are
 * the deliverable rather than a formality. They prove the four things the
 * foundation claims: tenancy actually isolates, the audit trail is genuinely
 * immutable, the permission matrix denies by default, and the route group is
 * gated.
 */
class TransportContextFoundationTest extends TestCase
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

    private function user(int $tenantId, string $role = 'admin', ?string $internal = null): User
    {
        return User::create([
            'tenant_id' => $tenantId,
            'name' => ucfirst($role).' T'.$tenantId,
            'role' => $role,
            'internal_role' => $internal,
            'email' => $role.'-'.Str::random(8).'@test.local',
            'password' => bcrypt('x'),
            'status' => 'active',
        ]);
    }

    private function logger(): TransportAuditLogger
    {
        return app(TransportAuditLogger::class);
    }

    /* ── 1. Tenancy ───────────────────────────────────────────────────── */

    public function test_belongs_to_tenant_stamps_the_tenant_of_the_authenticated_user(): void
    {
        $actor = $this->user(self::TENANT_A);
        Sanctum::actingAs($actor);

        // tenant_id deliberately omitted — the trait must supply it.
        $entry = TransportAuditLog::create([
            'action' => 'transport.context.probe',
            'occurred_at' => now(),
        ]);

        $this->assertSame(self::TENANT_A, (int) $entry->fresh()->tenant_id);
    }

    public function test_for_tenant_returns_only_that_tenants_rows(): void
    {
        $a = $this->user(self::TENANT_A);
        $b = $this->user(self::TENANT_B);

        $this->logger()->record('transport.context.probe', self::TENANT_A, actor: $a);
        $this->logger()->record('transport.context.probe', self::TENANT_A, actor: $a);
        $this->logger()->record('transport.context.probe', self::TENANT_B, actor: $b);

        $this->assertSame(3, TransportAuditLog::count(), 'all three rows exist globally');
        $this->assertSame(2, TransportAuditLog::forTenant(self::TENANT_A)->count());
        $this->assertSame(1, TransportAuditLog::forTenant(self::TENANT_B)->count());
    }

    public function test_tenant_b_cannot_read_tenant_a_audit_entries(): void
    {
        $a = $this->user(self::TENANT_A);
        $entry = $this->logger()->record('transport.context.probe', self::TENANT_A, actor: $a);

        // The scoped lookup a service would perform on behalf of tenant B.
        $found = TransportAuditLog::forTenant(self::TENANT_B)->find($entry->id);

        $this->assertNull($found, 'tenant B must not resolve a tenant A record by id');
    }

    public function test_logger_writes_the_tenant_it_is_given_not_the_logged_in_one(): void
    {
        // Guards the console/queue path: BelongsToTenant only auto-stamps when
        // auth()->check() is true, so the logger must pass tenant_id explicitly.
        Sanctum::actingAs($this->user(self::TENANT_A));

        $entry = $this->logger()->record('transport.context.probe', self::TENANT_B);

        $this->assertSame(self::TENANT_B, (int) $entry->tenant_id);
    }

    /* ── 2. Immutable audit trail (SNG-TRN-027) ───────────────────────── */

    public function test_audit_entry_records_actor_action_and_payload(): void
    {
        $actor = $this->user(self::TENANT_A, 'staff');

        $entry = $this->logger()->record(
            action: 'transport.context.probe',
            tenantId: self::TENANT_A,
            actor: $actor,
            old: ['status' => 'draft'],
            new: ['status' => 'submitted'],
            context: ['reason' => 'foundation test'],
        );

        $this->assertSame('transport.context.probe', $entry->action);
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame($actor->name, $entry->actor_name, 'actor name is snapshotted, not joined');
        $this->assertSame('staff', $entry->actor_role);
        $this->assertSame(['status' => 'draft'], $entry->old_values);
        $this->assertSame(['status' => 'submitted'], $entry->new_values);
        $this->assertSame(['reason' => 'foundation test'], $entry->context);
        $this->assertNotNull($entry->occurred_at);
    }

    public function test_audit_entry_cannot_be_updated(): void
    {
        $entry = $this->logger()->record('transport.context.probe', self::TENANT_A);

        $this->expectException(RuntimeException::class);
        $entry->update(['action' => 'transport.context.tampered']);
    }

    public function test_audit_entry_cannot_be_deleted(): void
    {
        $entry = $this->logger()->record('transport.context.probe', self::TENANT_A);

        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    public function test_transition_helper_records_from_and_to(): void
    {
        $entry = $this->logger()->recordTransition(
            action: 'transport.context.transitioned',
            tenantId: self::TENANT_A,
            subject: $this->user(self::TENANT_A),
            from: 'draft',
            to: 'approved',
        );

        $this->assertSame(['status' => 'draft'], $entry->old_values);
        $this->assertSame(['status' => 'approved'], $entry->new_values);
    }

    /* ── 3. Permission matrix (SNG-TRN-028) ───────────────────────────── */

    public function test_admin_maps_to_the_admin_matrix_role_and_may_create(): void
    {
        $svc = app(TransportPermissionService::class);
        $admin = $this->user(self::TENANT_A, 'admin');

        $this->assertSame(TransportPermission::ROLE_ADMIN, $svc->stosRole($admin));
        $this->assertTrue($svc->can($admin, TransportPermission::ORDER_CREATE));
        $this->assertTrue($svc->can($admin, TransportPermission::TRIP_CREATE));
        $this->assertSame(TransportPermission::SCOPE_ALL, $svc->scope($admin, TransportPermission::TRIP_VIEW));
    }

    public function test_client_maps_to_customer_and_is_denied_creation(): void
    {
        $svc = app(TransportPermissionService::class);
        $client = $this->user(self::TENANT_A, 'client');

        $this->assertSame(TransportPermission::ROLE_CUSTOMER, $svc->stosRole($client));
        // PERM-002 gives Customer no create right at all.
        $this->assertFalse($svc->can($client, TransportPermission::TRIP_CREATE));
        // PERM-001 gives Customer view, but only over its own records.
        $this->assertSame(TransportPermission::SCOPE_OWN, $svc->scope($client, TransportPermission::TRIP_VIEW));
    }

    public function test_unmapped_role_is_denied_everything(): void
    {
        $svc = app(TransportPermissionService::class);
        // vendor is intentionally unmapped until an assignment concept exists.
        $vendor = $this->user(self::TENANT_A, 'vendor');

        $this->assertNull($svc->stosRole($vendor));
        $this->assertFalse($svc->can($vendor, TransportPermission::TRIP_VIEW));
        $this->assertSame([], $svc->grantsFor($vendor));
    }

    public function test_unknown_permission_key_is_denied(): void
    {
        $svc = app(TransportPermissionService::class);

        $this->assertFalse($svc->can($this->user(self::TENANT_A, 'admin'), 'transport.order.obliterate'));
        $this->assertFalse(TransportPermission::isPermission('transport.order.obliterate'));
    }

    public function test_grants_payload_lists_only_held_permissions(): void
    {
        $svc = app(TransportPermissionService::class);
        $grants = $svc->grantsFor($this->user(self::TENANT_A, 'client'));

        $this->assertArrayHasKey(TransportPermission::TRIP_VIEW, $grants);
        $this->assertArrayNotHasKey(TransportPermission::TRIP_CREATE, $grants);
    }

    /* ── 4. The gate and the route group ──────────────────────────────── */

    public function test_middleware_denies_a_role_without_the_permission(): void
    {
        Route::middleware(['auth:sanctum', 'transport.permission:'.TransportPermission::TRIP_CREATE])
            ->get('/_test/transport/create', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($this->user(self::TENANT_A, 'client'));

        $this->getJson('/_test/transport/create')->assertStatus(403);
    }

    public function test_middleware_allows_a_role_that_holds_the_permission(): void
    {
        Route::middleware(['auth:sanctum', 'transport.permission:'.TransportPermission::TRIP_CREATE])
            ->get('/_test/transport/create-ok', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($this->user(self::TENANT_A, 'admin'));

        $this->getJson('/_test/transport/create-ok')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_route_gated_on_an_unknown_permission_fails_closed(): void
    {
        Route::middleware(['auth:sanctum', 'transport.permission:transport.order.obliterate'])
            ->get('/_test/transport/bogus', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($this->user(self::TENANT_A, 'admin'));

        // 500, not 200: a route naming a permission that does not exist is a bug
        // in the route, never a reason to let the request through.
        $this->getJson('/_test/transport/bogus')->assertStatus(500);
    }

    public function test_transport_route_group_is_registered_and_gated(): void
    {
        $group = collect(Route::getRoutes())->first(
            fn ($r) => str_starts_with($r->uri(), 'api/transport')
        );

        // The group exists even though this ticket registers no business routes;
        // what matters is that the middleware stack is in place before any are.
        $file = base_path('routes/transport.php');
        $this->assertFileExists($file);
        $this->assertStringContainsString("'auth:sanctum', 'role:admin,staff'", file_get_contents($file));
        $this->assertStringContainsString("prefix('transport')", file_get_contents($file));
        $this->assertStringContainsString("require __DIR__.'/transport.php'", file_get_contents(base_path('routes/api.php')));
    }
}
