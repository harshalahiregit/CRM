<?php

namespace Tests\Feature\Shared;

use App\Models\Purchase\PurchaseVendor;
use App\Models\Shared\DocumentProviderRequest;
use App\Services\Settings\SettingsService;
use App\Services\Shared\ProviderCallbackService;
use App\Support\Shared\ComplianceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

/**
 * A vendor's callback request must actually reach the agency.
 *
 * The Service Providers panel shipped as decoration: the form pushed itself
 * into React state, the row read "Callback requested from X — pending", and a
 * refresh lost it. No endpoint, no table, no mail — nobody at BusinessBadhega,
 * LegalDesk or VakilSearch ever heard about a single request, and the vendor
 * sat waiting for a call no one had been asked to make.
 *
 * These assert the three things that make it real: the agency is only offered
 * when we can reach them, the lead arrives with the vendor's details, and
 * hitting reply reaches the VENDOR rather than us — because the handover is the
 * whole feature. Everything after it is between those two.
 *
 * Reply-To is asserted on the Symfony message the transport actually produced.
 * Asserting on rendered HTML would prove nothing about a header.
 */
class ProviderCallbackReachesTheProviderTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = 1;

    private function vendor(): PurchaseVendor
    {
        return PurchaseVendor::create([
            'tenant_id'            => self::TENANT,
            'purchase_vendor_code' => 'PV-7001',
            'company_name'         => 'Vionews',
            'email'                => 'vendor@example.test',
            'status'               => 'Active',
            'vendor_type'          => 'standard',
        ]);
    }

    private function configure(?string $email): void
    {
        app(SettingsService::class)->setGroup(self::TENANT, 'compliance_providers', [
            'business_badhega_email' => $email,
        ]);
    }

    private function payload(): array
    {
        return [
            'document_type'  => 'pan',
            'document_label' => 'Company PAN Card',
            'contact_name'   => 'Shiv',
            'contact_email'  => 'shiv@vionews.test',
            'contact_mobile' => '0987654321',
            'company_name'   => 'Vionews',
            'notes'          => 'Need a PAN for the company.',
        ];
    }

    private function raise(): DocumentProviderRequest
    {
        return app(ProviderCallbackService::class)->raise(
            self::TENANT,
            DocumentProviderRequest::KIND_PURCHASE,
            $this->vendor()->id,
            'business_badhega',
            $this->payload(),
        );
    }

    /**
     * The messages the array transport actually produced.
     *
     * Deliberately the real Symfony message and not a rendered template: a
     * header like Reply-To exists only on the message, and rendering proves
     * nothing about it.
     *
     * @return SentMessage[]
     */
    private function sent(): array
    {
        $messages = app('mailer')->getSymfonyTransport()->messages();

        return $messages instanceof \Illuminate\Support\Collection
            ? $messages->all()
            : (array) $messages;
    }

    public function test_an_agency_with_no_address_is_never_offered(): void
    {
        $this->configure(null);

        $this->assertSame([], app(ProviderCallbackService::class)->contactable(self::TENANT),
            'an agency we cannot reach must not be offered — promising a callback we have no way '
            .'to deliver is what this feature was before it worked');
    }

    public function test_configuring_an_address_puts_the_agency_on_the_list(): void
    {
        $this->configure('leads@businessbadhega.test');

        $this->assertSame(
            [['id' => 'business_badhega', 'name' => 'BusinessBadhega.com']],
            app(ProviderCallbackService::class)->contactable(self::TENANT),
        );
    }

    public function test_a_request_raised_before_an_address_exists_is_kept_not_lost(): void
    {
        $this->configure(null);
        Mail::fake();

        $request = $this->raise();

        $this->assertSame(DocumentProviderRequest::QUEUED, $request->status,
            'with nowhere to send it, the lead is held rather than dropped');
        $this->assertNull($request->sent_at);
        $this->assertDatabaseCount('document_provider_requests', 1);
    }

    public function test_the_lead_carries_the_vendors_details(): void
    {
        $this->configure('leads@businessbadhega.test');

        $request = $this->raise();

        $this->assertSame(DocumentProviderRequest::SENT, $request->status);
        $this->assertNotNull($request->sent_at);

        $body = (string) $this->sent()[0]->getOriginalMessage()->getHtmlBody();

        foreach (['Shiv', 'Vionews', 'shiv@vionews.test', '0987654321', 'Company PAN Card'] as $needle) {
            $this->assertStringContainsString($needle, $body,
                "the agency cannot act on a lead that does not carry {$needle}");
        }
    }

    /**
     * The point of the whole thing: the agency talks to the vendor, not to us.
     */
    public function test_reply_goes_to_the_vendor_not_to_us(): void
    {
        $this->configure('leads@businessbadhega.test');

        $this->raise();

        $message = $this->sent()[0]->getOriginalMessage();

        $to = array_map(fn ($a) => $a->getAddress(), $message->getTo());
        $this->assertSame(['leads@businessbadhega.test'], $to);

        $replyTo = array_map(fn ($a) => $a->getAddress(), $message->getReplyTo());
        $this->assertSame(['shiv@vionews.test'], $replyTo,
            'the agency must be able to hit reply and reach the vendor — a reply that lands in our '
            .'inbox puts us back in the middle of a conversation that is not ours');
    }

    /** The vendor sees exactly what was handed over, and to whom. */
    public function test_the_vendor_gets_a_copy(): void
    {
        $this->configure('leads@businessbadhega.test');

        $this->raise();

        $recipients = [];
        foreach ($this->sent() as $m) {
            foreach ($m->getOriginalMessage()->getTo() as $a) {
                $recipients[] = $a->getAddress();
            }
        }

        $this->assertContains('shiv@vionews.test', $recipients,
            'the vendor just gave their phone number to an outside company and should be able to '
            .'see what was sent without asking anybody');
    }

    public function test_an_unknown_agency_is_refused(): void
    {
        $this->configure('leads@businessbadhega.test');

        $this->expectException(\App\Exceptions\BusinessException::class);

        app(ProviderCallbackService::class)->raise(
            self::TENANT,
            DocumentProviderRequest::KIND_PURCHASE,
            $this->vendor()->id,
            'some_random_co',
            $this->payload(),
        );
    }

    /** Consent is recorded, because it is evidence and not a formality. */
    public function test_consent_is_stamped_on_the_record(): void
    {
        $this->configure('leads@businessbadhega.test');

        $this->assertNotNull($this->raise()->consented_at);
    }

    /**
     * The backend list and the frontend catalog are keyed on the same ids — the
     * settings key for an agency's address is derived from its id, so a rename
     * on one side silently stops delivering on the other.
     */
    public function test_the_two_provider_lists_agree_on_ids(): void
    {
        $catalog = (string) file_get_contents(
            base_path('../frontend/src/components/vendor/documentCatalog.js'),
        );

        $start = strpos($catalog, 'export const COMPLIANCE_PROVIDERS');
        $this->assertNotFalse($start, 'COMPLIANCE_PROVIDERS has moved — this guard needs repointing');

        preg_match_all("/id:\s*'([^']+)'/", substr($catalog, $start), $m);

        $this->assertSame(array_keys(ComplianceProviders::ALL), $m[1],
            'the screen offers agencies the server does not know, or the reverse — the settings key '
            .'for a lead address is derived from the id, so a mismatch sends the lead nowhere');
    }
}
