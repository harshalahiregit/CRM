<?php

namespace App\Services\Customer;

use App\Models\Customer\ClientContact;
use App\Services\Notifications\NotificationService;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Log;

/**
 * Customer portal mail — set-password invitations and resets.
 *
 * Goes through NotificationService, which resolves the tenant's own SMTP from
 * Settings -> Email and falls back to the global mailer. That is the same path
 * vendor activation uses, so a workspace configures its mail once and every
 * outgoing message obeys it.
 *
 * The tenant is taken from the contact rather than the acting user: a forgotten
 * password arrives with nobody signed in, so there is no session to infer it from.
 */
class ClientPortalNotifier
{
    public function __construct(private NotificationService $channels)
    {
    }

    public function sendSetPassword(ClientContact $contact, bool $isReset = false): void
    {
        $url = FrontendUrl::to('/portal/set-password?token='.urlencode($contact->password_reset_token));

        $company = $contact->client?->company ?? 'your account';
        $name    = trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: 'there';

        $subject = $isReset
            ? 'Reset your portal password'
            : 'Your customer portal access';

        $status = $this->channels->emailHtml(
            $contact->email,
            $subject,
            view('emails.customer.set-password', [
                'contact'   => $contact,
                'name'      => $name,
                'company'   => $company,
                'actionUrl' => $url,
                'isReset'   => $isReset,
                'companyName'  => config('app.name', 'Our Company'),
                'logoUrl'      => config('mail.logo_url'),
                'supportEmail' => config('mail.support_address', config('mail.from.address', 'support@example.com')),
            ])->render(),
            ['client_contact_id' => $contact->id, 'event' => $isReset ? 'portal_reset' : 'portal_invite'],
            $this->plainText($name, $company, $url, $isReset),
            // Explicit: a reset request has no authenticated user to infer from.
            $contact->tenant_id,
        );

        if ($status !== 'sent') {
            // The token is deliberately absent from this log line — a link in a
            // logfile is a working credential for anyone with log access.
            Log::channel('daily')->warning('Customer portal mail not delivered', [
                'client_contact_id' => $contact->id, 'status' => $status,
            ]);
        }
    }

    private function plainText(string $name, string $company, string $url, bool $isReset): string
    {
        return implode("\n", [
            $isReset ? 'Reset your portal password' : 'Your customer portal access',
            '',
            "Hello {$name},",
            '',
            $isReset
                ? "We received a request to reset the password for your {$company} portal account."
                : "You have been given access to the {$company} customer portal, where you can see your invoices, estimates, projects and support tickets.",
            '',
            'Open this link to choose a password:',
            $url,
            '',
            'The link is valid for 24 hours. If you did not expect this email you can ignore it.',
        ]);
    }
}
