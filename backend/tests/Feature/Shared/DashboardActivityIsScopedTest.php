<?php

namespace Tests\Feature\Shared;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The landing page's activity feed shows your work, not everyone's — SIR-000044.
 *
 * It filtered on tenant_id alone, so any authenticated user read the whole
 * company's audit trail on the first screen after login, actor_name included:
 * who edited which invoice, who opened whose employee record, across every
 * module. On a tenant where several departments share one workspace that is an
 * org chart and a work diary handed to anybody with a password.
 *
 * The entitlement is StaffPermissionService::scope($user, 'reports'), which is
 * what that method was written for. `reports` governs it because the audit trail
 * spans every module rather than belonging to one.
 *
 * No grandfather clause here, deliberately — unlike the lead-edit gate, where an
 * unconfigured grid had to keep permitting or the whole company lost work they
 * do daily. Narrowing a feed to your own actions takes away sight of other
 * people's, which is the thing being fixed.
 */
class DashboardActivityIsScopedTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Tenant 1', 'slug' => 'dash-act-t',
            'subdomain' => 'dashact', 'status' => 'active',
        ])->save();
    }

    /** @param array<string,array<string>>|null $permissions */
    private function user(string $role, ?array $permissions = null): User
    {
        return User::create([
            'tenant_id' => self::TENANT,
            'name'      => ucfirst($role),
            'email'     => $role.uniqid().'@test.com',
            'password'  => bcrypt('secret'),
            'role'      => $role,
            'status'    => 'active',
            'meta'      => $permissions === null ? [] : ['permissions' => $permissions],
        ]);
    }

    private function log(?int $actorId, string $actorName, string $action): void
    {
        DB::table('audit_logs')->insert([
            'tenant_id'      => self::TENANT,
            'auditable_type' => 'App\\Models\\Sales\\Lead',
            'auditable_id'   => 1,
            'action'         => $action,
            'actor_id'       => $actorId,
            'actor_name'     => $actorName,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /** @return array<int,string> the actor names the feed showed */
    private function actorsSeen(): array
    {
        $body = $this->getJson('/api/dashboard')->assertOk()->json('data.recent_activity') ?? [];

        // description is "<ref or type> · <actor_name>"; the name is what leaks.
        return array_values(array_filter(array_map(
            fn ($row) => str_contains($row['description'] ?? '', '·')
                ? trim(explode('·', $row['description'])[1] ?? '')
                : null,
            $body,
        )));
    }

    public function test_a_user_sees_only_their_own_actions(): void
    {
        $me    = $this->user('staff');
        $other = $this->user('staff');

        $this->log($me->id, 'Me', 'Updated');
        $this->log($other->id, 'Somebody Else', 'Deleted');

        Sanctum::actingAs($me);

        $this->assertSame(['Me'], $this->actorsSeen());
    }

    /**
     * A system write has no actor. It belongs to nobody, so a scoped reader is
     * not shown it either — "unattributed" must not become "everyone's".
     */
    public function test_an_unattributed_action_is_not_shown_to_a_scoped_reader(): void
    {
        $me = $this->user('staff');

        $this->log(null, 'System', 'Imported');

        Sanctum::actingAs($me);

        $this->assertSame([], $this->actorsSeen());
    }

    public function test_an_admin_still_sees_the_whole_tenant(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('staff');

        $this->log($admin->id, 'Admin', 'Updated');
        $this->log($other->id, 'Somebody Else', 'Deleted');

        Sanctum::actingAs($admin);

        $seen = $this->actorsSeen();
        sort($seen);
        $this->assertSame(['Admin', 'Somebody Else'], $seen);
    }

    /**
     * The grant an admin can make. `reports` → view_global is the tick that turns
     * the feed back into a company-wide one for somebody who is not an admin.
     */
    public function test_view_global_on_reports_widens_the_feed(): void
    {
        $auditor = $this->user('staff', ['reports' => ['view_global']]);
        $other   = $this->user('staff');

        $this->log($other->id, 'Somebody Else', 'Deleted');

        Sanctum::actingAs($auditor);

        $this->assertSame(['Somebody Else'], $this->actorsSeen());
    }

    /**
     * view_own is not view_global. It is the narrower of the pair on purpose, and
     * a feed is exactly where confusing them would leak the company.
     */
    public function test_view_own_on_reports_does_not_widen_the_feed(): void
    {
        $narrow = $this->user('staff', ['reports' => ['view_own']]);
        $other  = $this->user('staff');

        $this->log($other->id, 'Somebody Else', 'Deleted');

        Sanctum::actingAs($narrow);

        $this->assertSame([], $this->actorsSeen());
    }

    /** Another tenant's trail was never visible and must stay that way. */
    public function test_another_tenants_activity_is_never_shown(): void
    {
        (new Tenant())->forceFill([
            'id' => 2, 'name' => 'Tenant 2', 'slug' => 'dash-act-t2',
            'subdomain' => 'dashact2', 'status' => 'active',
        ])->save();

        $admin = $this->user('admin');

        DB::table('audit_logs')->insert([
            'tenant_id' => 2, 'auditable_type' => 'App\\Models\\Sales\\Lead', 'auditable_id' => 9,
            'action' => 'Updated', 'actor_id' => 999, 'actor_name' => 'Outsider',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->assertNotContains('Outsider', $this->actorsSeen());
    }
}
