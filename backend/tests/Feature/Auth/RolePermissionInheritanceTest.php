<?php

namespace Tests\Feature\Auth;

use App\Models\StaffRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\StaffPermissionService;
use App\Services\Auth\StaffRoleService;
use App\Support\Hr\StaffPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A role has to stay a role, not become a copy.
 *
 * effectiveGrants() resolves array_replace($role->grants(), $own) per MODULE, and
 * that was always right. What was wrong sat in the browser: picking a role in
 * StaffModal wrote the role's own grants into that person's meta.permissions, so
 * every module the role named immediately became a personal override. The link
 * survived on paper — staff_role_id was set — and meant nothing in practice.
 * Editing "HR Executive" afterwards changed nothing for anyone already holding
 * it, because the copy wins every module it names.
 *
 * These pin the three states the model actually has, because the middle one is
 * the whole reason the fix is subtle:
 *
 *   module ABSENT from overrides  → inherit the role, live, forever
 *   module PRESENT with a list    → this person gets exactly this
 *   module PRESENT and EMPTY      → this person gets NOTHING here
 *
 * Absent and empty must never collapse into each other. Un-ticking every box for
 * one module is a decision somebody made and has to outlive a role edit.
 */
class RolePermissionInheritanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Inherit', 'slug' => 'role-inheritance', 'status' => 'active',
        ]);
    }

    private function user(string $email, array $grid = null): User
    {
        return User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Staff', 'email' => $email,
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
            'meta' => $grid === null ? null : ['permissions' => $grid],
        ]);
    }

    private function role(string $name, array $permissions): StaffRole
    {
        return app(StaffRoleService::class)->create($this->tenant->id, [
            'name' => $name, 'permissions' => $permissions,
        ]);
    }

    private function grants(User $user): array
    {
        return app(StaffRoleService::class)->effectiveGrants($user->fresh());
    }

    /* ── 1. a newly assigned role stays live ──────────────────────────── */

    public function test_a_newly_assigned_role_is_linked_not_copied(): void
    {
        $role = $this->role('HR Ops', ['hr_employees' => [StaffPermission::VIEW_GLOBAL]]);
        $user = app(StaffRoleService::class)->assign($this->user('fresh@inherit.test'), $role);

        $this->assertSame([StaffPermission::VIEW_GLOBAL], $this->grants($user)['hr_employees'] ?? null,
            'The role must supply the grant.');
        $this->assertNull($user->fresh()->meta['permissions'] ?? null,
            'Assigning a role must not write the role into the person — that is the copy this fixes.');
    }

    /* ── 2. editing the role reaches its holders ──────────────────────── */

    public function test_editing_a_role_changes_what_its_holders_may_do(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Mutable', ['hr_employees' => [StaffPermission::VIEW_GLOBAL]]);
        $user    = $service->assign($this->user('holder@inherit.test'), $role);

        $this->assertTrue($user->canManageHrQueue());

        $service->update($role, ['permissions' => ['tasks' => [StaffPermission::VIEW_OWN]]]);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->canManageHrQueue(),
            'Revoking the module on the ROLE must revoke it for its holders, with no deploy.');
        $this->assertSame([StaffPermission::VIEW_OWN], $this->grants($fresh)['tasks'] ?? null,
            'And what the role gained must arrive the same way.');
    }

    public function test_two_holders_of_one_role_both_follow_it(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Shared', ['reports' => [StaffPermission::VIEW_GLOBAL]]);

        $a = $service->assign($this->user('a@inherit.test'), $role);
        $b = $service->assign($this->user('b@inherit.test'), $role);

        $service->update($role, ['permissions' => ['reports' => [], 'goals' => [StaffPermission::VIEW_GLOBAL]]]);

        foreach ([$a, $b] as $user) {
            $g = $this->grants($user);
            $this->assertSame([], $g['reports'] ?? null);
            $this->assertSame([StaffPermission::VIEW_GLOBAL], $g['goals'] ?? null);
        }
    }

    /* ── 3. a personal override survives a role edit ──────────────────── */

    public function test_a_personal_override_outlives_a_role_edit(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Base', [
            'tasks'   => [StaffPermission::VIEW_OWN],
            'reports' => [StaffPermission::VIEW_GLOBAL],
        ]);

        // "The role, plus one extra thing" — the case the override exists for.
        $user = $service->assign(
            $this->user('override@inherit.test', ['tasks' => [StaffPermission::VIEW_GLOBAL, StaffPermission::CREATE]]),
            $role
        );

        $service->update($role, ['permissions' => [
            'tasks'   => [StaffPermission::DELETE],
            'reports' => [StaffPermission::VIEW_GLOBAL],
        ]]);

        $g = $this->grants($user);

        $this->assertSame([StaffPermission::VIEW_GLOBAL, StaffPermission::CREATE], $g['tasks'],
            'A deliberate per-user decision must not be overwritten by an edit to the role.');
        $this->assertSame([StaffPermission::VIEW_GLOBAL], $g['reports'],
            'While every module they did NOT override still follows the role.');
    }

    /* ── 4. an empty override still denies ────────────────────────────── */

    public function test_an_explicit_empty_module_keeps_denying(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Generous', ['hr_employees' => [StaffPermission::VIEW_GLOBAL]]);

        $user = $service->assign($this->user('denied@inherit.test', ['hr_employees' => []]), $role);

        $this->assertSame([], $this->grants($user)['hr_employees'],
            'Empty means "nothing here", and must beat the role.');
        $this->assertFalse($user->canManageHrQueue(),
            'Which is the only way to say "they hold the HR role but not that part of it".');

        // And it must survive the role being re-granted the same module.
        $service->update($role, ['permissions' => ['hr_employees' => [StaffPermission::VIEW_GLOBAL, StaffPermission::DELETE]]]);
        $this->assertFalse($user->fresh()->canManageHrQueue());
    }

    /**
     * Absent and empty are different answers, and everything rests on it.
     */
    public function test_absent_and_empty_are_not_the_same_answer(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Both', ['reports' => [StaffPermission::VIEW_GLOBAL]]);

        $absent = $service->assign($this->user('absent@inherit.test', ['tasks' => [StaffPermission::VIEW_OWN]]), $role);
        $empty  = $service->assign($this->user('empty@inherit.test', ['reports' => []]), $role);

        $this->assertSame([StaffPermission::VIEW_GLOBAL], $this->grants($absent)['reports'] ?? null,
            'Not mentioning a module means "no opinion — use the role".');
        $this->assertSame([], $this->grants($empty)['reports'],
            'Mentioning it with nothing in it means "no".');
    }

    /* ── 5. stored data is never rewritten on its own ─────────────────── */

    public function test_resolving_permissions_never_writes_to_the_user(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Reader', ['hr_employees' => [StaffPermission::VIEW_GLOBAL]]);

        $stored = ['tasks' => [StaffPermission::VIEW_OWN], 'reports' => []];
        $user   = $service->assign($this->user('stored@inherit.test', $stored), $role);

        // Every read path, several times over.
        app(StaffPermissionService::class)->can($user, StaffPermission::VIEW_GLOBAL, 'hr_employees');
        app(StaffPermissionService::class)->scope($user, 'tasks');
        $user->canManageHrQueue();
        $this->grants($user);

        $this->assertSame($stored, $user->fresh()->meta['permissions'],
            'Asking what somebody may do must never change what is stored about them.');
    }

    public function test_a_user_with_no_grid_does_not_acquire_one(): void
    {
        $role = $this->role('Quiet', ['tasks' => [StaffPermission::VIEW_OWN]]);
        $user = app(StaffRoleService::class)->assign($this->user('nogrid@inherit.test'), $role);

        $user->canManageHrQueue();
        $this->grants($user);

        $this->assertNull($user->fresh()->meta['permissions'] ?? null);
    }

    /* ── 6. removing the role leaves no snapshot ──────────────────────── */

    public function test_removing_a_role_leaves_no_copied_permissions_behind(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Temporary', ['hr_employees' => [StaffPermission::VIEW_GLOBAL], 'reports' => [StaffPermission::VIEW_GLOBAL]]);

        $user = $service->assign($this->user('leaver@inherit.test'), $role);
        $this->assertTrue($user->canManageHrQueue());

        $stripped = $service->assign($user, null);

        $this->assertNull($stripped->staff_role_id);
        $this->assertSame([], $this->grants($stripped),
            'Removing the role must remove what it granted — not leave a copy behind that nobody can find.');
        $this->assertFalse($stripped->canManageHrQueue());
    }

    /** Removing the role keeps the person's OWN decisions, which were never the role's. */
    public function test_removing_a_role_keeps_personal_overrides(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Passing', ['reports' => [StaffPermission::VIEW_GLOBAL]]);

        $user = $service->assign($this->user('mine@inherit.test', ['tasks' => [StaffPermission::VIEW_OWN]]), $role);
        $user = $service->assign($user, null);

        $this->assertSame([StaffPermission::VIEW_OWN], $this->grants($user)['tasks'] ?? null,
            'A personal grant was never the role\'s to take away.');
    }

    /* ── 7. deploying this changes nobody ─────────────────────────────── */

    /**
     * The safety property, stated directly.
     *
     * Everybody who exists today has a grid and most have a snapshot in it. The
     * fix changes how NEW assignments are written, not how stored data resolves,
     * so an untouched account must answer exactly as it did before.
     */
    public function test_an_existing_snapshot_resolves_exactly_as_before(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('HR Executive', [
            'hr_employees'   => [StaffPermission::VIEW_GLOBAL],
            'hr_recruitment' => [StaffPermission::VIEW_GLOBAL, StaffPermission::CREATE],
            'reports'        => [StaffPermission::VIEW_GLOBAL],
        ]);

        // Exactly what the old copy-on-assign produced: every role module mirrored
        // into the person. This is what real accounts look like today.
        $snapshot = [
            'hr_employees'   => [StaffPermission::VIEW_GLOBAL],
            'hr_recruitment' => [StaffPermission::VIEW_GLOBAL, StaffPermission::CREATE],
            'reports'        => [StaffPermission::VIEW_GLOBAL],
        ];

        $user = $service->assign($this->user('legacy@inherit.test', $snapshot), $role);

        $this->assertSame($snapshot, $this->grants($user),
            'A snapshotted account must resolve to the same thing it always did.');
        $this->assertSame($snapshot, $user->fresh()->meta['permissions'],
            'And nothing may have been migrated, normalised or cleaned up underneath them.');
        $this->assertTrue($user->canManageHrQueue());
    }

    /**
     * The snapshot's cost is real and worth pinning: a snapshotted user does NOT
     * follow role edits. That is the behaviour an admin has to clear deliberately,
     * and it is why nothing is migrated automatically.
     */
    public function test_a_snapshotted_user_still_shadows_the_role_until_an_admin_clears_it(): void
    {
        $service = app(StaffRoleService::class);
        $role    = $this->role('Shadowed', ['reports' => [StaffPermission::VIEW_GLOBAL]]);

        $user = $service->assign($this->user('shadow@inherit.test', ['reports' => [StaffPermission::VIEW_GLOBAL]]), $role);

        $service->update($role, ['permissions' => ['reports' => []]]);

        $this->assertSame([StaffPermission::VIEW_GLOBAL], $this->grants($user)['reports'],
            'The snapshot still wins — this is the state the UI now labels "Custom" so it can be undone.');

        // What clearing the override looks like: the module leaves meta.permissions.
        $user->forceFill(['meta' => ['permissions' => []]])->save();

        $this->assertSame([], $this->grants($user->fresh())['reports'],
            'Once handed back, the module follows the role again.');
    }
}
