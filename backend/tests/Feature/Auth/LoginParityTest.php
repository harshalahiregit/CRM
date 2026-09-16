<?php

namespace Tests\Feature\Auth;

use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The website takes what the app takes.
 *
 * The app posts an email and a password. The website ALSO required a role from
 * a dropdown, and looked the account up by email AND role — so picking the wrong
 * entry failed a login whose credentials were perfectly correct, and the same
 * person could sign in on their phone and not on the website.
 *
 * users.email is globally unique, so the role never added precision to a lookup
 * that could not return two rows. It is still honoured when sent.
 */
class LoginParityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct!2026';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'parity', 'status' => 'active']);

        $this->staff = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Ravi', 'email' => 'ravi@parity.test',
            'password' => Hash::make(self::PASSWORD), 'role' => 'staff', 'status' => 'active',
        ]);

        HrEmployee::create([
            'tenant_id' => $tenant->id, 'employee_code' => 'E1', 'name' => 'Ravi',
            'department' => 'Ops', 'designation' => 'Analyst', 'joining_date' => '2024-01-01',
            'status' => 'Active', 'user_id' => $this->staff->id, 'app_login_enabled' => true,
        ]);
    }

    public function test_the_website_signs_you_in_with_just_an_email_and_a_password(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ravi@parity.test', 'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('data.user.email', 'ravi@parity.test');
    }

    /** Exactly what the app posts, against the app's own door. */
    public function test_the_app_signs_you_in_with_the_same_two_fields(): void
    {
        $this->postJson('/api/Hrm/login', [
            'email' => 'ravi@parity.test', 'password' => self::PASSWORD,
        ])->assertOk()->assertJsonPath('status', 1);
    }

    /** Sending a role still works — every existing caller keeps doing so. */
    public function test_a_role_is_still_honoured_when_it_is_sent(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ravi@parity.test', 'password' => self::PASSWORD, 'role' => 'staff',
        ])->assertOk();
    }

    /** A wrong one still refuses, so the door has not been widened. */
    public function test_a_role_that_does_not_match_is_still_refused(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ravi@parity.test', 'password' => self::PASSWORD, 'role' => 'admin',
        ])->assertStatus(401);
    }

    public function test_a_wrong_password_is_still_refused_without_a_role(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'ravi@parity.test', 'password' => 'nope',
        ])->assertStatus(401);
    }

    /** Signing out ends this session on both, and only this one. */
    public function test_signing_out_works_the_same_way_on_both(): void
    {
        $web = $this->postJson('/api/auth/login', [
            'email' => 'ravi@parity.test', 'password' => self::PASSWORD,
        ])->json('data.access_token');

        $app = $this->postJson('/api/Hrm/login', [
            'email' => 'ravi@parity.test', 'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$web}")
            ->postJson('/api/auth/logout')->assertOk();

        // The phone is still signed in — signing out of the website must not
        // knock somebody off their own attendance app mid-shift.
        $this->withHeader('Authorization', "Bearer {$app}")
            ->postJson('/api/Hrm/home')->assertOk();
    }
}
