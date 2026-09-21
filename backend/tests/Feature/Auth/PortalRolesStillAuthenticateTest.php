<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Doctor and Company were removed from the login SELECTOR, not from the system.
 *
 * Both are real Users behind real portals — `doctor` for routes/medical.php,
 * `company` for the external hiring portal (routes/company_portal.php,
 * CompanyRole, hr_hiring_requests). Neither is an HR role, so neither belongs in
 * a dropdown headed "Select access role", but deleting them server-side would
 * lock the only door into two working subsystems.
 *
 * These tests pin the half that must NOT change. If someone later "finishes the
 * job" by removing the roles from LoginRequest, this is what fails — and the
 * failure names the portals rather than leaving a 422 for somebody to diagnose.
 *
 * The frontend half is checked by frontend/scripts/login-roles.check.mjs.
 */
class PortalRolesStillAuthenticateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:portal-doctor@test.local|127.0.0.1');
        RateLimiter::clear('login:portal-company@test.local|127.0.0.1');
        RateLimiter::clear('login:portal-staff@test.local|127.0.0.1');

        $this->tenant = Tenant::create([
            'name' => 'Portals', 'slug' => 'portals', 'subdomain' => 'portals',
            'plan' => 'professional', 'status' => 'active',
        ]);
    }

    private function user(string $role, string $email, ?string $internalRole = null): User
    {
        return User::create(array_filter([
            'tenant_id' => $this->tenant->id, 'name' => ucfirst($role), 'role' => $role,
            'email' => $email, 'password' => Hash::make('correct-horse'),
            'status' => 'active', 'internal_role' => $internalRole,
        ]));
    }

    /** With the selector gone, this is how a doctor and a company now sign in. */
    public function test_a_doctor_authenticates_with_no_role_selected(): void
    {
        $this->user('doctor', 'portal-doctor@test.local');

        $this->postJson('/api/auth/login', [
            'email' => 'portal-doctor@test.local', 'password' => 'correct-horse',
        ])->assertOk()->assertJsonPath('data.user.role', 'doctor');
    }

    public function test_a_company_authenticates_with_no_role_selected(): void
    {
        $this->user('company', 'portal-company@test.local', 'company_admin');

        $this->postJson('/api/auth/login', [
            'email' => 'portal-company@test.local', 'password' => 'correct-horse',
        ])->assertOk()->assertJsonPath('data.user.role', 'company');
    }

    /**
     * The backend still ACCEPTS the role explicitly. Anything already sending it
     * — a saved link, the mobile app, an integration — keeps working; only the
     * dropdown stopped offering it.
     */
    public function test_the_backend_still_accepts_doctor_as_an_explicit_role(): void
    {
        $this->user('doctor', 'portal-doctor@test.local');

        $this->postJson('/api/auth/login', [
            'email' => 'portal-doctor@test.local', 'password' => 'correct-horse', 'role' => 'doctor',
        ])->assertOk();
    }

    public function test_the_backend_still_accepts_company_as_an_explicit_role(): void
    {
        $this->user('company', 'portal-company@test.local', 'company_admin');

        $this->postJson('/api/auth/login', [
            'email' => 'portal-company@test.local', 'password' => 'correct-horse', 'role' => 'company',
        ])->assertOk();
    }

    /** The ordinary HR login is untouched by any of this. */
    public function test_a_staff_login_still_works_both_ways(): void
    {
        $this->user('staff', 'portal-staff@test.local');

        $this->postJson('/api/auth/login', [
            'email' => 'portal-staff@test.local', 'password' => 'correct-horse', 'role' => 'staff',
        ])->assertOk();

        RateLimiter::clear('login:portal-staff@test.local|127.0.0.1');

        $this->postJson('/api/auth/login', [
            'email' => 'portal-staff@test.local', 'password' => 'correct-horse',
        ])->assertOk();
    }

    /**
     * Custom roles are part of the product direction, so the login door must not
     * become an allowlist that a new role has to be added to by a developer.
     * An unknown role is rejected as INPUT — it does not create an account and
     * does not authenticate one — which is the behaviour to preserve.
     */
    public function test_an_unknown_role_is_refused_as_input_not_silently_accepted(): void
    {
        $this->user('staff', 'portal-staff@test.local');

        $this->postJson('/api/auth/login', [
            'email' => 'portal-staff@test.local', 'password' => 'correct-horse', 'role' => 'not_a_role',
        ])->assertStatus(422);
    }
}
