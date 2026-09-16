<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * "Forgot password" has to name its own tenant.
 *
 * Every other caller of NotificationService::email() omits the tenant and lets
 * the service fall back to the SIGNED-IN user's tenant. That fallback cannot
 * work here, and this is the one endpoint where it cannot: nobody is signed in
 * when they have forgotten their password. It resolved to null, the mailer was
 * asked to send on behalf of no tenant, and it refused —
 *
 *   "Email is not set up yet. Add your SMTP server under Settings → Email."
 *
 * — while the workspace's SMTP sat correctly configured one row away.
 *
 * Nothing surfaced. The endpoint answers "if that email is registered, a reset
 * link has been sent" either way (deliberately, so it cannot be used to probe
 * which addresses exist), the token was really written, and the only trace was
 * a warning in a log nobody reads. Every user who asked for a reset was told to
 * check an inbox that would never receive anything.
 */
class ForgotPasswordReachesTheTenantMailerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'S', 'slug' => 'forgot-pw', 'status' => 'active']);

        $this->user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Sam', 'email' => 'sam@forgot.test',
            'password' => Hash::make('x'), 'role' => 'staff', 'status' => 'active',
        ]);
    }

    /** The reset mail is sent for the account's OWN tenant, not for none. */
    public function test_the_reset_email_is_sent_on_behalf_of_the_users_tenant(): void
    {
        $seen = null;

        $spy = Mockery::mock(NotificationService::class);
        $spy->shouldReceive('email')
            ->once()
            ->andReturnUsing(function ($to, $subject, $body, $context = [], $tenantId = null) use (&$seen) {
                $seen = $tenantId;

                return 'sent';
            });
        $this->app->instance(NotificationService::class, $spy);

        $this->postJson('/api/auth/forgot-password', ['email' => 'sam@forgot.test'])
            ->assertOk();

        $this->assertSame(
            $this->user->tenant_id,
            $seen,
            'the reset e-mail was sent with no tenant, so the mailer has no SMTP to use'
        );
    }

    /** An unknown address still answers the same way, and mails nobody. */
    public function test_an_unknown_address_is_answered_identically_and_sends_nothing(): void
    {
        $spy = Mockery::mock(NotificationService::class);
        $spy->shouldNotReceive('email');
        $this->app->instance(NotificationService::class, $spy);

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@forgot.test'])
            ->assertOk();
    }

    /** The token is really issued, so the link in that e-mail works. */
    public function test_a_reset_token_is_recorded_for_the_address(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'sam@forgot.test'])->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'sam@forgot.test']);
    }
}
