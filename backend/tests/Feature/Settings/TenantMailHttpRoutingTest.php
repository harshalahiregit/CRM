<?php

namespace Tests\Feature\Settings;

use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Models\User;
use App\Services\Mail\TenantMailConfigurator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every authenticated request points the mailer at the tenant's Settings → Email
 * SMTP (not .env) via the ConfigureTenantMail middleware, so all outgoing mail —
 * the direct Mail:: sends included — uses the admin-configured account.
 */
class TenantMailHttpRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        (new Tenant())->forceFill(['id' => self::TENANT, 'name' => 'T1', 'slug' => 't1', 'subdomain' => 't1', 'status' => 'active'])->save();
    }

    private function settings(): void
    {
        TenantMailSetting::create([
            'tenant_id' => self::TENANT, 'host' => 'mail.tenant-example.test', 'port' => 465,
            'username' => 'support@tenant-example.test', 'password' => 'secret',
            'encryption' => 'ssl', 'from_name' => 'Tenant Sender', 'from_email' => 'support@tenant-example.test',
            'enabled' => true, 'verify_peer' => false,
        ]);
    }

    private function user(): User
    {
        return User::create([
            'tenant_id' => self::TENANT, 'name' => 'Admin', 'role' => 'admin',
            'email' => 'admin@t.local', 'password' => bcrypt('secret'), 'status' => 'active',
        ]);
    }

    public function test_authenticated_request_routes_mail_through_tenant_settings(): void
    {
        $this->settings();
        Sanctum::actingAs($this->user());

        $this->getJson('/api/notifications')->assertOk();

        // The middleware ran during the request and repointed the default mailer.
        $this->assertSame('tenant', config('mail.default'));
        $this->assertSame('mail.tenant-example.test', config('mail.mailers.tenant.host'));
        $this->assertSame('smtps', config('mail.mailers.tenant.scheme'));
        $this->assertSame('support@tenant-example.test', config('mail.from.address'));
        $this->assertFalse(config('mail.mailers.tenant.verify_peer'));
    }

    public function test_configurator_is_a_noop_without_a_usable_setting(): void
    {
        // No setting row → the .env fallback stays as the default.
        $applied = app(TenantMailConfigurator::class)->applyForTenant(self::TENANT);
        $this->assertFalse($applied);
    }

    public function test_disabled_setting_is_ignored(): void
    {
        TenantMailSetting::create([
            'tenant_id' => self::TENANT, 'host' => 'mail.off.test', 'port' => 587,
            'from_email' => 'x@off.test', 'enabled' => false,
        ]);

        $applied = app(TenantMailConfigurator::class)->applyForTenant(self::TENANT);
        $this->assertFalse($applied);
    }
}
