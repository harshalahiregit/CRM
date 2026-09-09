<?php

namespace Tests\Feature\Settings;

use App\Exceptions\BusinessException;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Models\User;
use App\Services\Mail\TenantMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mail goes through the tenant's own SMTP, or it does not go.
 *
 * The old rule was "tenant SMTP if configured, otherwise the global .env
 * mailer", and that fallback is how mail disappeared without a word:
 * config('mail.default') is env('MAIL_MAILER', 'log'), so a deployment that
 * never set MAIL_MAILER wrote every message to a log file and told the person
 * who pressed Send that it had worked. Even where it IS set, sending from the
 * .env account rather than the tenant's own domain breaks SPF/DKIM alignment
 * and lands the mail in spam -- a slower, stranger version of the same failure.
 *
 * The strict path cannot be reached through configureMailer() under `php
 * artisan test` (the transport there is `array`, and refusing would break every
 * send path in the suite), so the rule lives in requireSettings() and is
 * asserted directly.
 *
 * Every host here ends in .test on purpose. A resolvable host would make the
 * suite open a real SMTP connection and, with the right password, actually send.
 */
class MailNeverFallsBackToEnvTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill([
            'id' => self::TENANT, 'name' => 'Sangoe OS', 'slug' => 't1',
            'subdomain' => 't1', 'status' => 'active',
        ])->save();

        Sanctum::actingAs(User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'a-'.Str::random(6).'@t.local',
            'password' => bcrypt('x'), 'status' => 'active',
        ]));
    }

    /** array_merge, not `+` -- union keeps the LEFT side and drops the overrides. */
    private function settings(array $overrides = []): TenantMailSetting
    {
        return TenantMailSetting::create(array_merge([
            'tenant_id' => self::TENANT,
            'enabled' => true,
            'host' => 'mail.sangoe.test', 'port' => 465,
            'encryption' => 'ssl', 'verify_peer' => false,
            'username' => 'support@sangoe.test', 'password' => 'secret',
            'from_email' => 'support@sangoe.test', 'from_name' => 'Sangoe OS',
        ], $overrides));
    }

    /** The config the mailer would build, without building a transport. */
    private function tenantMailerConfig(): array
    {
        $mailer = app(TenantMailer::class);
        $m = new \ReflectionMethod($mailer, 'configureMailer');
        $m->setAccessible(true);
        $name = $m->invoke($mailer, $mailer->settingsFor(self::TENANT));

        return config('mail.mailers.'.$name);
    }

    public function test_no_smtp_at_all_is_refused_with_something_to_do_about_it(): void
    {
        try {
            app(TenantMailer::class)->requireSettings(self::TENANT);
            $this->fail('mail was allowed to fall through to the .env account');
        } catch (BusinessException $e) {
            // The message has to name the screen. "Mail failed" sends somebody
            // to the logs; this sends them to the setting.
            $this->assertStringContainsString('Settings', $e->getMessage());
            $this->assertStringContainsString('Email', $e->getMessage());
        }
    }

    public function test_smtp_that_is_switched_off_is_refused_too(): void
    {
        $this->settings(['enabled' => false]);

        $this->expectException(BusinessException::class);
        app(TenantMailer::class)->requireSettings(self::TENANT);
    }

    public function test_half_configured_smtp_is_refused(): void
    {
        // A host with no From address cannot produce a deliverable message, and
        // silently borrowing the .env From is exactly the SPF misalignment that
        // sends the mail to spam.
        $this->settings(['from_email' => null]);

        $this->expectException(BusinessException::class);
        app(TenantMailer::class)->requireSettings(self::TENANT);
    }

    public function test_a_configured_tenant_is_accepted(): void
    {
        $this->settings();

        $s = app(TenantMailer::class)->requireSettings(self::TENANT);

        $this->assertSame('mail.sangoe.test', $s->host);
        $this->assertSame('support@sangoe.test', $s->from_email);
    }

    public function test_the_transport_is_built_from_the_tenant_row_not_the_env(): void
    {
        $this->settings();

        $cfg = $this->tenantMailerConfig();

        $this->assertSame('mail.sangoe.test', $cfg['host']);
        $this->assertSame(465, (int) $cfg['port']);
        // ssl on 465 is implicit TLS, which Symfony spells smtps. Getting this
        // wrong falls back to plaintext and the handshake fails outright.
        $this->assertSame('smtps', $cfg['scheme']);
        // Panel-managed servers present a certificate for a different hostname;
        // this is the switch that keeps encryption but skips the name check.
        $this->assertFalse($cfg['verify_peer']);
    }

    public function test_the_send_timeout_leaves_room_for_a_real_server(): void
    {
        $this->settings();

        // Measured against the live host: ~6s for the TCP+TLS handshake and
        // ~11s for a complete send. 15s left almost nothing spare, and a send
        // that times out half way looks exactly like a button that does nothing.
        $this->assertGreaterThanOrEqual(30, (int) $this->tenantMailerConfig()['timeout']);
    }

    /**
     * The ratchet.
     *
     * The fallback is one line away from coming back, and it reads perfectly
     * sensibly when you write it.
     */
    public function test_the_mailer_does_not_reach_for_the_global_default(): void
    {
        $src = file_get_contents(app_path('Services/Mail/TenantMailer.php'));

        // The guarded test-suite escape hatch, and nothing else.
        $this->assertSame(1, substr_count($src, "return config('mail.default');"),
            'TenantMailer returns the .env mailer in more than the one guarded place');
        $this->assertStringContainsString('runningUnitTests', $src,
            'the only permitted fallback is the one that exists because tests have no transport');
    }
}
