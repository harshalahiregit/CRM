<?php

namespace Tests\Feature\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Services\Purchase\PurchaseVendorPortalAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registration and password-reset mail used to not exist at all: both minted a
 * token and wrote it to purchase-*.log instead of sending it.
 */
class PurchaseAuthMailTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'subdomain' => 'acme',
            'plan' => 'professional', 'status' => 'active',
        ]);
    }

    /**
     * Messages actually handed to the transport.
     *
     * Mail::fake()/assertSentCount only count Mailable objects, and this path
     * renders its own HTML and does a raw send -- so they report zero even
     * when a message really went out. The array transport sees the real thing.
     */
    private function sent(): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->all();
    }

    /**
     * The rendered HTML of one sent message.
     *
     * Not toString(): quoted-printable inserts soft line breaks ("=\r\n") into
     * long lines, which splits a 48-char token in half and makes a substring
     * assertion fail on mail that is perfectly correct.
     */
    private function htmlOf(int $i = 0): string
    {
        return (string) $this->sent()[$i]->getOriginalMessage()->getHtmlBody();
    }

    private function flushMail(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }

    private function payload(int $tenantId): array
    {
        return [
            'tenant_id'    => $tenantId,
            'company_name' => 'Widget Supplies Ltd',
            'email'        => 'buyer@widget.test',
            'password'     => 'Secret@12345',
            'phone'        => '9999999999',
        ];
    }

    public function test_registering_sends_a_verification_email(): void
    {
        $this->flushMail();
        $tenant = $this->tenant();

        $vendor = app(PurchaseVendorPortalAuthService::class)->register($this->payload($tenant->id));

        $this->assertNotNull($vendor->email_verification_token);

        $messages = $this->sent();
        $this->assertCount(1, $messages);

        $html = $this->htmlOf();
        // The link must carry the real token, or the emailed link is useless.
        $this->assertStringContainsString('/purchase-portal/verify-email', $html);
        $this->assertStringContainsString($vendor->email_verification_token, $html);
    }

    public function test_the_verification_link_carries_the_token_and_resolves_the_account(): void
    {
        $tenant = $this->tenant();
        $svc = app(PurchaseVendorPortalAuthService::class);
        $vendor = $svc->register($this->payload($tenant->id));

        // The emailed link posts this token back; verifying must clear it.
        $verified = $svc->verifyEmail($vendor->email_verification_token);

        $this->assertSame($vendor->id, $verified->id);
        $this->assertNull($verified->fresh()->email_verification_token);
    }

    public function test_forgot_password_sends_a_reset_email(): void
    {
        $tenant = $this->tenant();
        $svc = app(PurchaseVendorPortalAuthService::class);
        $svc->register($this->payload($tenant->id));
        $this->flushMail();   // drop the verification mail; measure only the reset

        $svc->forgotPassword('buyer@widget.test');

        $messages = $this->sent();
        $this->assertCount(1, $messages);

        $token = PurchaseVendor::first()->password_reset_token;
        $this->assertNotNull($token);

        $html = $this->htmlOf();
        $this->assertStringContainsString('/purchase-portal/reset-password', $html);
        $this->assertStringContainsString($token, $html);
    }

    public function test_forgot_password_for_an_unknown_email_sends_nothing(): void
    {
        $this->flushMail();
        $this->tenant();

        app(PurchaseVendorPortalAuthService::class)->forgotPassword('nobody@nowhere.test');

        $this->assertCount(0, $this->sent());
    }

    /** The mail must go through the tenant's SMTP, not the global .env account. */
    public function test_it_uses_the_tenants_own_smtp(): void
    {
        $tenant = $this->tenant();
        TenantMailSetting::create([
            'tenant_id' => $tenant->id,
            'host' => 'smtp.tenant-example.test', 'port' => 587,
            'from_email' => 'noreply@tenant-example.test', 'enabled' => true,
        ]);

        app(PurchaseVendorPortalAuthService::class)->register($this->payload($tenant->id));

        $this->assertSame('smtp.tenant-example.test', config('mail.mailers.tenant.host'));
    }
}
