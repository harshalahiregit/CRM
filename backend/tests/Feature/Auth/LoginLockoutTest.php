<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Failed-login lockout.
 *
 * The Security Settings page has always offered "Failed logins before lockout"
 * and "Lockout duration (minutes)", saved them, and read them back — while the
 * login path had no throttling of any kind. Ten wrong passwords cost an
 * attacker nothing, and the admin who set it to 3 had no way to discover that.
 *
 * The cases that matter are the two ends: it must actually stop guessing, and
 * it must not lock out a real user who mistyped and then got it right.
 */
class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login:zafar@test.local|127.0.0.1');

        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Zafar', 'role' => 'admin',
            'email' => 'zafar@test.local', 'password' => Hash::make('correct-horse'),
            'status' => 'active',
        ]);
    }

    private function attempt(string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/login', [
            'email' => 'zafar@test.local', 'password' => $password, 'role' => 'admin',
        ]);
    }

    private function setLockout(int $attempts, int $minutes = 15): void
    {
        app(SettingsService::class)->setGroup($this->tenant->id, 'security', [
            'failed_login_lockout' => $attempts, 'lockout_duration_minutes' => $minutes,
        ]);
    }

    public function test_repeated_wrong_passwords_eventually_lock_the_account(): void
    {
        $this->setLockout(3);

        for ($i = 0; $i < 3; $i++) {
            $this->attempt('wrong')->assertStatus(401);
        }

        // The fourth is refused before the password is even checked.
        $this->attempt('wrong')->assertStatus(429);
    }

    public function test_the_lock_holds_even_against_the_correct_password(): void
    {
        $this->setLockout(3);
        for ($i = 0; $i < 3; $i++) {
            $this->attempt('wrong');
        }

        // Otherwise an attacker who guesses right on attempt 20 still gets in.
        $this->attempt('correct-horse')->assertStatus(429);
    }

    public function test_the_message_says_how_long_to_wait(): void
    {
        $this->setLockout(1, 15);
        $this->attempt('wrong');

        $this->attempt('wrong')
             ->assertStatus(429)
             ->assertJsonFragment(['message' => 'Too many failed attempts. Try again in 15 minute(s).']);
    }

    public function test_a_correct_password_clears_the_counter(): void
    {
        $this->setLockout(3);

        $this->attempt('wrong')->assertStatus(401);
        $this->attempt('wrong')->assertStatus(401);
        // Mistyped twice, then got it right — that is not an attack.
        $this->attempt('correct-horse')->assertOk();

        // The two earlier failures must not count toward a later lockout.
        $this->attempt('wrong')->assertStatus(401);
        $this->attempt('wrong')->assertStatus(401);
        $this->attempt('correct-horse')->assertOk();
    }

    public function test_setting_it_to_zero_disables_the_lockout(): void
    {
        // The setting's own rules allow min:0, and 0 must mean "off" rather than
        // "lock immediately", which would bar everyone from the product.
        $this->setLockout(0);

        for ($i = 0; $i < 8; $i++) {
            $this->attempt('wrong')->assertStatus(401);
        }
        $this->attempt('correct-horse')->assertOk();
    }

    public function test_the_default_applies_when_the_tenant_has_set_nothing(): void
    {
        // Registry default is 5.
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('wrong')->assertStatus(401);
        }
        $this->attempt('wrong')->assertStatus(429);
    }

    public function test_another_users_failures_do_not_lock_this_one_out(): void
    {
        $this->setLockout(2);

        User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone', 'role' => 'admin',
            'email' => 'other@test.local', 'password' => Hash::make('x'), 'status' => 'active',
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'other@test.local', 'password' => 'wrong', 'role' => 'admin',
            ]);
        }

        // The counter is per email + IP, so hammering one account must not bar
        // a different one.
        $this->attempt('correct-horse')->assertOk();
    }
}
