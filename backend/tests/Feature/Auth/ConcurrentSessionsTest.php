<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing in somewhere must not sign you out everywhere else.
 *
 * The default was one session per user, so a new login deleted every prior
 * token. In practice that meant a phone login killed the desktop, and two
 * browsers open together fought — each refresh re-authenticating and revoking
 * the other, which users experienced as being logged out every minute or so.
 *
 * The unit test covers the eviction rule in isolation; this covers what
 * actually matters, that the earlier token still authenticates afterwards.
 */
class ConcurrentSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'multi@x.test'): User
    {
        Tenant::firstOrCreate(['id' => 1], [
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);

        return User::create([
            'tenant_id' => 1, 'name' => 'Multi', 'email' => $email,
            'password' => bcrypt('secret123'), 'role' => 'admin', 'status' => 'active',
        ]);
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', [
                'email' => $email, 'password' => 'secret123', 'role' => 'admin',
            ])
            ->assertOk()
            ->json('data.access_token');
    }

    private function worksWith(string $token): bool
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')->status() === 200;
    }

    public function test_a_second_login_does_not_kill_the_first(): void
    {
        $u = $this->user();

        $first  = $this->login($u->email);
        $second = $this->login($u->email);

        $this->assertNotSame($first, $second);
        $this->assertTrue($this->worksWith($first),  'the first session was revoked by the second');
        $this->assertTrue($this->worksWith($second), 'the second session does not work');
    }

    public function test_many_devices_can_be_signed_in_at_once(): void
    {
        $u = $this->user();

        $tokens = [];
        for ($i = 0; $i < 6; $i++) {
            $tokens[] = $this->login($u->email);
        }

        foreach ($tokens as $i => $t) {
            $this->assertTrue($this->worksWith($t), "session {$i} was revoked");
        }
    }

    public function test_an_explicit_cap_still_evicts_the_oldest(): void
    {
        // Unlimited is the default, not a removal of the capability.
        config(['auth_sessions.concurrency' => 'multi', 'auth_sessions.max_devices' => 2]);
        $u = $this->user('capped@x.test');

        $first  = $this->login($u->email);
        $second = $this->login($u->email);
        $third  = $this->login($u->email);

        $this->assertFalse($this->worksWith($first), 'the oldest session should have been evicted');
        $this->assertTrue($this->worksWith($second));
        $this->assertTrue($this->worksWith($third));
    }

    public function test_single_still_revokes_everything_when_chosen(): void
    {
        config(['auth_sessions.concurrency' => 'single', 'auth_sessions.max_devices' => 1]);
        $u = $this->user('single@x.test');

        $first  = $this->login($u->email);
        $second = $this->login($u->email);

        $this->assertFalse($this->worksWith($first));
        $this->assertTrue($this->worksWith($second));
    }
}
