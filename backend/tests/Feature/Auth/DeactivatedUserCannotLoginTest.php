<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deactivating an account must actually stop the person using the CRM.
 *
 * The login gate used to be a denylist of pending / suspended / rejected. It
 * missed 'inactive' — the status the Deactivate button writes — so the one
 * control that exists to remove someone's access was the one status login
 * ignored. Sanctum tokens carry no status check either, so an open session
 * survived regardless.
 *
 * Both halves are covered here: refused at sign-in, and existing sessions ended.
 */
class DeactivatedUserCannotLoginTest extends TestCase
{
    use RefreshDatabase;

    private ?Tenant $tenant = null;

    /** One tenant per test — tenants.slug is unique, and some tests make several users. */
    private function tenant(): Tenant
    {
        return $this->tenant ??= Tenant::create([
            'name' => 'Test Co', 'slug' => 'test-co', 'status' => 'active',
        ]);
    }

    private function user(string $status, string $role = 'staff'): User
    {
        return User::create([
            'tenant_id' => $this->tenant()->id,
            'name'      => 'Test Person',
            'email'     => $status . '-' . $role . '@example.test',
            'password'  => Hash::make('Password123!'),
            'role'      => $role,
            'status'    => $status,
        ]);
    }

    private function login(User $user)
    {
        return $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'Password123!',
            // Required by LoginRequest, and the lookup is scoped by it.
            'role'     => $user->role,
        ]);
    }

    public function test_an_active_user_can_still_log_in(): void
    {
        $this->login($this->user('active'))->assertOk();
    }

    /** The regression this whole file exists for. */
    public function test_an_inactive_user_is_refused(): void
    {
        $this->login($this->user('inactive'))
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Your account has been deactivated. Contact your administrator.']);
    }

    public function test_suspended_pending_and_rejected_are_still_refused(): void
    {
        foreach (['suspended', 'pending', 'rejected'] as $status) {
            $this->login($this->user($status))->assertStatus(403);
        }
    }

    /**
     * The allowlist's whole point: a status nobody has admitted yet must fail
     * closed. If this ever starts returning 200, someone has reintroduced a
     * denylist.
     */
    public function test_an_unrecognised_status_fails_closed(): void
    {
        $user = $this->user('active');
        $user->forceFill(['status' => 'archived'])->save();

        $this->login($user)->assertStatus(403);
    }

    public function test_deactivating_a_staff_member_ends_their_open_sessions(): void
    {
        $tenant = $this->tenant();

        $staff = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Staff', 'email' => 'staff@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'staff', 'status' => 'active',
        ]);
        $admin = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => Hash::make('Password123!'), 'role' => 'admin', 'status' => 'active',
        ]);

        // The staff member is signed in and holds a working token.
        $token = $staff->createToken('phone')->plainTextToken;
        $this->assertSame(1, $staff->tokens()->count());

        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $this->patchJson("/api/admin/staff/{$staff->id}/toggle-status")->assertOk();

        $this->assertSame('inactive', $staff->fresh()->status);

        // The token row is gone, so the credential the phone was holding no
        // longer resolves to anyone.
        $this->assertSame(0, $staff->fresh()->tokens()->count(), 'Deactivation must revoke existing tokens.');
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id'   => $staff->id,
            'tokenable_type' => User::class,
        ]);

        // Asserted on the database rather than by replaying $token over HTTP:
        // Sanctum::actingAs() above installs a guard resolver for the rest of the
        // test, so a follow-up request would authenticate as the admin whatever
        // Authorization header it carried — and would pass while proving nothing.
        $this->assertNotEmpty($token);
    }
}
