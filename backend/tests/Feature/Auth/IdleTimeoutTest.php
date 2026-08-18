<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\SessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Pins down what EnforceIdleTimeout does, so the client-side idle timer in
 * IdleTimeoutWarning.jsx can be held to the same contract.
 *
 * The two halves of the session drifted apart: the middleware exempts
 * remember-me sessions from the idle timeout, while the browser timer knew
 * nothing about remember-me and signed those users out at 30 minutes anyway.
 * The frontend now reads the same flag. Nothing in the middleware changed —
 * these tests exist to make sure it stays that way.
 *
 * Sanctum::actingAs is deliberately not used: the middleware resolves the
 * bearer token itself, ahead of the auth pipeline, so it only does anything for
 * a request carrying a real token.
 */
class IdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();

        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active',
        ])->save();

        // The default the frontend mirrors. Set explicitly so a machine with
        // AUTH_SESSION_IDLE_MINUTES exported cannot change what these assert.
        config(['auth_sessions.idle_minutes' => 30]);
    }

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Staffer', 'email' => 'staff'.uniqid().'@test.com',
            'password' => bcrypt('secret'), 'role' => 'staff', 'status' => 'active',
        ]);
    }

    /** A real login: plaintext token plus its user_sessions row. */
    private function signIn(User $user, bool $remember): string
    {
        return app(SessionService::class)->establish($user, $remember, [
            'ip' => '127.0.0.1', 'browser' => 'Chrome', 'device' => 'Desktop',
        ]);
    }

    private function sessionRow(User $user): UserSession
    {
        return UserSession::where('user_id', $user->id)->active()->firstOrFail();
    }

    private function idleFor(User $user, int $minutes): void
    {
        $this->sessionRow($user)->forceFill(['last_activity_at' => now()->subMinutes($minutes)])->saveQuietly();
    }

    private function me(string $token)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/auth/me');
    }

    /* ── Normal session ───────────────────────────────────────────────── */

    public function test_a_normal_session_times_out_after_the_configured_idle_window(): void
    {
        $user  = $this->user();
        $token = $this->signIn($user, remember: false);

        $this->idleFor($user, 31);

        $this->me($token)
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_timed_out');
    }

    public function test_timing_out_revokes_the_token_and_the_session(): void
    {
        // The client only clears local storage; the server has to make the
        // token unusable, or the session is over in the UI alone.
        $user  = $this->user();
        $token = $this->signIn($user, remember: false);
        $id    = $this->sessionRow($user)->token_id;

        $this->idleFor($user, 31);
        $this->me($token)->assertStatus(401);

        $this->assertNull(PersonalAccessToken::find($id), 'the timed-out token should be deleted');
        $this->assertNotNull(UserSession::where('token_id', $id)->first()->revoked_at);
    }

    public function test_a_normal_session_survives_inside_the_window(): void
    {
        $user  = $this->user();
        $token = $this->signIn($user, remember: false);

        $this->idleFor($user, 29);

        $this->me($token)->assertOk();
    }

    public function test_a_request_refreshes_the_activity_stamp(): void
    {
        // This is why an open tab rarely hits the server-side timeout: the
        // notification poll keeps touching it. The browser timer is what
        // actually measures human inactivity.
        $user  = $this->user();
        $token = $this->signIn($user, remember: false);

        $this->idleFor($user, 10);
        $this->me($token)->assertOk();

        $this->assertTrue(
            $this->sessionRow($user)->last_activity_at->gt(now()->subMinutes(1)),
            'last_activity_at should have been touched by the request',
        );
    }

    /* ── Remember me ──────────────────────────────────────────────────── */

    public function test_a_remembered_session_is_never_idled_out(): void
    {
        // The behaviour the frontend now matches.
        $user  = $this->user();
        $token = $this->signIn($user, remember: true);

        $this->assertTrue($this->sessionRow($user)->remember_me);

        $this->idleFor($user, 60 * 24 * 7);

        $this->me($token)->assertOk();
        $this->assertNull($this->sessionRow($user)->revoked_at);
    }

    /* ── Configuration ────────────────────────────────────────────────── */

    public function test_zero_disables_the_idle_timeout(): void
    {
        config(['auth_sessions.idle_minutes' => 0]);

        $user  = $this->user();
        $token = $this->signIn($user, remember: false);
        $this->idleFor($user, 60 * 24);

        $this->me($token)->assertOk();
    }

    public function test_the_window_follows_the_configured_value(): void
    {
        config(['auth_sessions.idle_minutes' => 120]);

        $user  = $this->user();
        $token = $this->signIn($user, remember: false);

        // Past the 30-minute default, inside the configured 120.
        $this->idleFor($user, 45);
        $this->me($token)->assertOk();

        $this->idleFor($user, 121);
        $this->me($token)->assertStatus(401)->assertJsonPath('code', 'session_timed_out');
    }

    /* ── Untracked tokens ─────────────────────────────────────────────── */

    public function test_a_token_with_no_session_row_is_left_alone(): void
    {
        // Legacy tokens issued before session tracking, and the token minted by
        // registration, have no user_sessions row. The middleware must not
        // invent an idle window for them.
        $user  = $this->user();
        $token = $user->createToken('legacy', ['*'], now()->addDays(30))->plainTextToken;

        $this->me($token)->assertOk();
    }
}
