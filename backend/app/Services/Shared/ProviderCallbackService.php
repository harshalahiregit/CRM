<?php

namespace App\Services\Shared;

use App\Exceptions\BusinessException;
use App\Models\Shared\DocumentProviderRequest;
use App\Services\Mail\TenantMailer;
use App\Services\Settings\SettingsService;
use App\Support\Shared\ComplianceProviders;
use Illuminate\Support\Facades\Log;

/**
 * Hands a vendor's callback request to a compliance agency, then stops.
 *
 * A vendor who does not hold a statutory registration cannot discharge the
 * obligation, and it is the commonest reason onboarding stalls. The Documents
 * screen offers agencies who sell that registration; this is what happens when
 * one is picked.
 *
 * The deal is deliberately small. We send the agency the vendor's details, set
 * Reply-To to the vendor, and step out — everything after that is between the
 * two of them. We keep the row only so that a vendor who says "nobody called
 * me" can be answered from a record rather than from memory.
 *
 * Before this existed the screen was a decoration: the form pushed itself into
 * React state, told the vendor a callback was "pending", and lost it on the
 * next refresh. No endpoint, no table, no mail. Nobody at any agency ever heard
 * about a single request.
 */
class ProviderCallbackService
{
    public function __construct(
        private SettingsService $settings,
        private TenantMailer $mailer,
    ) {
    }

    /**
     * Which agencies can actually be offered to this tenant's vendors.
     *
     * An agency with no lead address configured is not listed at all. Blank is
     * the default and blank is meaningful: offering a callback we have no way
     * to deliver is the bug this feature started as.
     *
     * @return array<int, array{id:string,name:string}>
     */
    public function contactable(int $tenantId): array
    {
        $out = [];

        foreach (ComplianceProviders::ALL as $id => $name) {
            if ($this->addressFor($tenantId, $id)) {
                $out[] = ['id' => $id, 'name' => $name];
            }
        }

        return $out;
    }

    /**
     * Record the lead and send it on.
     *
     * @param  array{document_type:string,document_label?:string,contact_name:string,contact_email:string,contact_mobile?:string,company_name?:string,notes?:string}  $data
     */
    public function raise(
        int $tenantId,
        string $vendorKind,
        int $vendorId,
        string $providerId,
        array $data,
    ): DocumentProviderRequest {
        if (! ComplianceProviders::exists($providerId)) {
            throw new BusinessException('Unknown service provider.', 422);
        }

        $address = $this->addressFor($tenantId, $providerId);

        $request = DocumentProviderRequest::create([
            'tenant_id'      => $tenantId,
            'vendor_kind'    => $vendorKind,
            'vendor_id'      => $vendorId,
            'provider_id'    => $providerId,
            'provider_name'  => ComplianceProviders::name($providerId),
            'provider_email' => $address,
            'document_type'  => $data['document_type'],
            'document_label' => $data['document_label'] ?? $data['document_type'],
            'contact_name'   => $data['contact_name'],
            'contact_email'  => $data['contact_email'],
            'contact_mobile' => $data['contact_mobile'] ?? null,
            'company_name'   => $data['company_name'] ?? null,
            'notes'          => $data['notes'] ?? null,
            // The caller has already refused the request without consent; this
            // is the evidence of it, stamped at the moment it was given.
            'consented_at'   => now(),
            'status'         => DocumentProviderRequest::QUEUED,
        ]);

        // No address yet: keep the request rather than lose it, and say so.
        // This should be unreachable from the portal, which only offers
        // contactable providers — but a provider can be unconfigured between
        // the page loading and the form being submitted.
        if (! $address) {
            return $request;
        }

        $this->dispatch($request);

        return $request->fresh();
    }

    /** Send one queued request. Records the outcome; never throws at the caller. */
    public function dispatch(DocumentProviderRequest $request): void
    {
        try {
            $this->mailer->sendRawHtml(
                $request->tenant_id,
                $request->provider_email,
                'Callback request: '.$request->document_label.' — '.($request->company_name ?: $request->contact_name),
                view('emails.shared.provider_callback', ['r' => $request])->render(),
                $this->plainText($request),
                [],
                // The whole point: the agency answers the vendor, not us.
                $request->contact_email,
            );

            $request->update([
                'status'         => DocumentProviderRequest::SENT,
                'sent_at'        => now(),
                'failure_reason' => null,
            ]);

            $this->copyToVendor($request);
        } catch (\Throwable $e) {
            $request->update([
                'status'         => DocumentProviderRequest::FAILED,
                'failure_reason' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::warning('Provider callback not delivered', [
                'request_id' => $request->id,
                'provider'   => $request->provider_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * The vendor's own copy.
     *
     * They have just handed their phone number to an outside company. They
     * should be able to see exactly what was sent and to whom, without asking
     * anybody — and if the agency never calls, they have the address to chase.
     */
    private function copyToVendor(DocumentProviderRequest $request): void
    {
        try {
            $this->mailer->sendRawHtml(
                $request->tenant_id,
                $request->contact_email,
                'Your callback request to '.$request->provider_name,
                view('emails.shared.provider_callback_copy', ['r' => $request])->render(),
                $this->plainText($request),
                [],
                $request->provider_email,
            );
        } catch (\Throwable $e) {
            // The lead reached the agency, which is the part that matters. A
            // failed courtesy copy must not mark the request as failed.
            Log::info('Provider callback copy not delivered to vendor', [
                'request_id' => $request->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function plainText(DocumentProviderRequest $r): string
    {
        return implode("\n", array_filter([
            $r->contact_name.' is looking for help with: '.$r->document_label,
            $r->company_name ? 'Company: '.$r->company_name : null,
            'Email: '.$r->contact_email,
            $r->contact_mobile ? 'Phone: '.$r->contact_mobile : null,
            $r->notes ? 'Notes: '.$r->notes : null,
            '',
            'Reply to this email to reach them directly.',
        ]));
    }

    /** The configured lead address for an agency, or null if none is set. */
    private function addressFor(int $tenantId, string $providerId): ?string
    {
        $value = $this->settings->get(
            $tenantId,
            'compliance_providers',
            ComplianceProviders::emailKey($providerId),
        );

        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }
}
