<?php

namespace Tests\Feature\Notifications;

use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vendor activation / kickoff / HR notices are rendered as raw HTML rather than
 * a Mailable, so they used to call the global Mail:: facade directly — which
 * meant Settings -> Email was silently ignored and every one of those mails went
 * out over the .env SMTP account instead of the tenant's own.
 */
class TenantMailRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
    }

    public function test_it_sends_through_the_tenants_own_smtp_when_configured(): void
    {
        $tenant = $this->tenant();
        TenantMailSetting::create([
            'tenant_id' => $tenant->id,
            'host' => 'smtp.tenant-example.test', 'port' => 587,
            'username' => 'acme@tenant-example.test', 'password' => 'secret',
            'encryption' => 'tls', 'from_email' => 'noreply@tenant-example.test',
            'from_name' => 'Acme Corp', 'enabled' => true,
        ]);

        app(NotificationService::class)->emailHtml(
            'vendor@example.test', 'Your Account Has Been Activated',
            '<p>Welcome</p>', [], null, $tenant->id,
        );

        // The dynamic per-tenant transport was built from the saved settings,
        // rather than the mail.default mailer being used.
        $this->assertSame('smtp.tenant-example.test', config('mail.mailers.tenant.host'));
        $this->assertSame(587, config('mail.mailers.tenant.port'));
    }

    public function test_it_falls_back_to_the_global_mailer_when_the_tenant_has_no_settings(): void
    {
        $tenant = $this->tenant();

        $status = app(NotificationService::class)->emailHtml(
            'vendor@example.test', 'Activated', '<p>Welcome</p>', [], null, $tenant->id,
        );

        $this->assertSame('sent', $status);
        $this->assertNull(config('mail.mailers.tenant'));
    }

    public function test_a_disabled_tenant_config_is_ignored_rather_than_used(): void
    {
        $tenant = $this->tenant();
        TenantMailSetting::create([
            'tenant_id' => $tenant->id,
            'host' => 'smtp.disabled.test', 'port' => 587,
            'from_email' => 'noreply@disabled.test', 'enabled' => false,
        ]);

        $status = app(NotificationService::class)->emailHtml(
            'vendor@example.test', 'Activated', '<p>Welcome</p>', [], null, $tenant->id,
        );

        $this->assertSame('sent', $status);
        $this->assertNull(config('mail.mailers.tenant'));
    }

    /** The service records a status; a broken tenant SMTP must not throw. */
    public function test_it_never_throws_when_the_tenant_smtp_is_unreachable(): void
    {
        $tenant = $this->tenant();
        TenantMailSetting::create([
            'tenant_id' => $tenant->id,
            'host' => '127.0.0.1', 'port' => 1,   // nothing listening
            'from_email' => 'noreply@dead.test', 'enabled' => true,
        ]);

        $status = app(NotificationService::class)->emailHtml(
            'vendor@example.test', 'Activated', '<p>Welcome</p>', [], null, $tenant->id,
        );

        $this->assertSame('failed', $status);
    }
}
