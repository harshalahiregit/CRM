<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "We have your registration."
 *
 * Both vendor self-registration paths — TPV and Purchase — created their
 * records, wrote a log line and told the vendor nothing at all. The form said
 * "Awaiting admin approval" on a page the vendor then closed, and after that
 * there was silence for however long approval took. A vendor who registers on
 * Monday and hears nothing by Wednesday does one of two things: registers
 * again, which is refused because the email is already taken, or calls
 * somebody. Both were happening.
 *
 * Two messages go out, and they are not the same message:
 *
 *   - to the vendor: it arrived, here is your reference, here is what happens
 *     next, and do not register a second time.
 *   - to the workspace's admins: somebody is waiting on you.
 *
 * Nothing here may break a registration. A vendor whose record was created
 * successfully must not be told the registration failed because an SMTP host
 * was unreachable, so every send is wrapped and a failure is logged rather than
 * thrown. Delivery is best-effort; the record is not.
 */
class RegistrationAcknowledgement
{
    public function __construct(private NotificationService $channels)
    {
    }

    /**
     * @param  string       $portalName  the portal the vendor registered for, as they saw it
     * @param  string|null  $reference   a code they can quote — vendor code, where one exists yet
     */
    public function sent(
        ?int $tenantId,
        ?string $email,
        string $vendorName,
        string $portalName,
        ?string $reference = null,
    ): void {
        if (! $email) {
            return;
        }

        // After commit: the vendor row and its user are written in this request,
        // and an email promising "your registration is with our team" must not
        // go out ahead of a transaction that could still roll back.
        DB::afterCommit(function () use ($tenantId, $email, $vendorName, $portalName, $reference) {
            $this->tellVendor($tenantId, $email, $vendorName, $portalName, $reference);
            $this->tellAdmins($tenantId, $vendorName, $portalName, $email);
        });
    }

    private function tellVendor(?int $tenantId, string $email, string $vendorName, string $portalName, ?string $reference): void
    {
        $companyName = config('app.name', 'Our Company');

        $ctx = [
            'companyName'  => $companyName,
            'portalName'   => $portalName,
            'vendorName'   => $vendorName,
            'email'        => $email,
            'reference'    => $reference,
            'receivedAt'   => now()->format('d M Y, H:i'),
            'supportEmail' => config('mail.support_address', config('mail.from.address', 'support@example.com')),
        ];

        try {
            $status = $this->channels->emailHtml(
                $email,
                'We have your registration — '.$companyName,
                view('emails.shared.registration_received', $ctx)->render(),
                ['event' => 'registration_received', 'portal' => $portalName],
                $this->plainText($ctx),
                $tenantId,
            );

            if ($status !== 'sent') {
                Log::channel('auth')->warning('Registration acknowledgement not delivered', [
                    'email' => $email, 'portal' => $portalName, 'status' => $status,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let a mail problem look like a registration problem.
            Log::channel('auth')->error('Registration acknowledgement failed', [
                'email' => $email, 'portal' => $portalName, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell the people who have to act on it.
     *
     * Without this a self-registration is only discoverable by someone opening
     * the vendor list and noticing a new Draft row, which is why registrations
     * sat unapproved for days.
     */
    private function tellAdmins(?int $tenantId, string $vendorName, string $portalName, string $email): void
    {
        if (! $tenantId) {
            return;
        }

        try {
            $admins = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('role', 'admin')
                ->where('status', 'active')
                ->whereNotNull('email')
                ->pluck('email')
                ->all();

            foreach ($admins as $to) {
                $this->channels->emailHtml(
                    $to,
                    'New '.$portalName.' registration awaiting approval — '.$vendorName,
                    '<p>A new registration is waiting for approval.</p>'
                    .'<p><strong>'.e($vendorName).'</strong><br>'.e($email).'<br>'.e($portalName).'</p>'
                    .'<p>Open the vendor list to review and approve it.</p>',
                    ['event' => 'registration_awaiting_approval', 'portal' => $portalName],
                    "A new registration is waiting for approval.\n\n{$vendorName}\n{$email}\n{$portalName}\n\nOpen the vendor list to review and approve it.",
                    $tenantId,
                );
            }
        } catch (\Throwable $e) {
            Log::channel('auth')->error('Registration admin alert failed', [
                'portal' => $portalName, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function plainText(array $ctx): string
    {
        $lines = [
            "Hello {$ctx['vendorName']},",
            '',
            "Thank you for registering with {$ctx['companyName']} ({$ctx['portalName']}).",
            'Your details have been received and are now with our team for review.',
            '',
            "Registered email: {$ctx['email']}",
        ];

        if ($ctx['reference']) {
            $lines[] = "Your reference:   {$ctx['reference']}";
        }

        $lines[] = "Received:         {$ctx['receivedAt']}";
        $lines[] = '';
        $lines[] = 'What happens next';
        $lines[] = '  1. Our team reviews your registration.';
        $lines[] = '  2. Once approved, you receive a second email with your sign-in details.';
        $lines[] = '  3. You sign in and complete your onboarding documents.';
        $lines[] = '';
        $lines[] = 'You do not need to register again — a second registration with the same';
        $lines[] = 'email address will be refused, because that address is already on file.';
        $lines[] = '';
        $lines[] = "Questions? Write to {$ctx['supportEmail']}";
        $lines[] = '';
        $lines[] = 'Regards,';
        $lines[] = $ctx['companyName'];

        return implode("\n", $lines);
    }
}
