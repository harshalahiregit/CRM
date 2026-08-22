<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The signed-in user's own profile.
 *
 * The global user menu has always offered "My Profile" and navigated to
 * /app/settings/profile — a route that does not exist. Every user, on every
 * screen, got a 404 from the most obvious item in the header, and there was no
 * endpoint behind it either.
 *
 * The interesting part is what a user may NOT change about themselves: role,
 * tenant, status and access expiry all sit on the same model, and a mass
 * assignment would be a straight privilege escalation.
 */
class MyProfileTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Zafar', 'email' => 'z@x.test',
            'password' => Hash::make('secret123'), 'role' => 'staff', 'status' => 'active',
        ]);
        Sanctum::actingAs($this->user);
    }

    public function test_a_user_can_correct_their_own_details(): void
    {
        $this->putJson('/api/auth/profile', [
            'name' => 'Zafar Farooque', 'designation' => 'Account Manager',
            'department' => 'Customer Success', 'phone' => '9820045678',
        ])->assertOk();

        $u = $this->user->fresh();
        $this->assertSame('Zafar Farooque', $u->name);
        $this->assertSame('Account Manager', $u->designation);
    }

    public function test_a_user_cannot_promote_themselves(): void
    {
        $this->putJson('/api/auth/profile', [
            'name' => 'Zafar', 'role' => 'admin', 'internal_role' => 'admin',
            'tenant_id' => 999, 'status' => 'active', 'access_expires_at' => '2099-01-01',
        ])->assertOk();

        $u = $this->user->fresh();
        // Everything above is fillable on the model. Only the validated keys
        // may be written, so none of it took.
        $this->assertSame('staff', $u->role);
        $this->assertNull($u->internal_role);
        $this->assertSame($this->tenant->id, $u->tenant_id);
    }

    public function test_a_user_cannot_change_their_own_email(): void
    {
        $this->putJson('/api/auth/profile', ['name' => 'Zafar', 'email' => 'someone@else.test'])
            ->assertOk();

        // The email is the login identity — changing it is an administrative
        // act with verification attached, not a profile edit.
        $this->assertSame('z@x.test', $this->user->fresh()->email);
    }

    public function test_a_bad_phone_number_is_refused(): void
    {
        $this->putJson('/api/auth/profile', ['name' => 'Zafar', 'phone' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_the_name_is_required(): void
    {
        $this->putJson('/api/auth/profile', ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // ── password ─────────────────────────────────────────────────────────────

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'not-it',
            'password' => 'newsecret123', 'password_confirmation' => 'newsecret123',
        ])->assertStatus(422);

        // A token alone is not proof of the person: an unattended session must
        // not be enough to lock the owner out of their own account.
        $this->assertTrue(Hash::check('secret123', $this->user->fresh()->password));
    }

    public function test_a_correct_current_password_changes_it(): void
    {
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'secret123',
            'password' => 'newsecret123', 'password_confirmation' => 'newsecret123',
        ])->assertOk();

        $this->assertTrue(Hash::check('newsecret123', $this->user->fresh()->password));
    }

    public function test_the_confirmation_must_match(): void
    {
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'secret123',
            'password' => 'newsecret123', 'password_confirmation' => 'different123',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'secret123',
            'password' => 'short', 'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_both_endpoints_need_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->putJson('/api/auth/profile', ['name' => 'x'])->assertStatus(401);
        $this->postJson('/api/auth/change-password', [
            'current_password' => 'a', 'password' => 'bbbbbbbb', 'password_confirmation' => 'bbbbbbbb',
        ])->assertStatus(401);
    }
}
