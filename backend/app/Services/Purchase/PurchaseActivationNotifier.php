<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseNotificationLog as LogEntry;
use App\Models\Purchase\PurchaseVendor;
use App\Services\Notifications\NotificationService;
use App\Support\Purchase\PurchaseRegistrationType;
use Illuminate\Support\Facades\DB;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Vendor activation notification.
 *
 * Called from the activation service (never a controller, never the frontend)
 * and only once the surrounding transaction has committed — a rolled-back
 * activation must not e-mail anyone. Delivery goes through the generic channel
 * abstraction (email live; SMS/WhatsApp stubs), and every attempt is written to
 * purchase_notification_logs, which is what makes "exactly once" enforceable.
 *
 * Purchase-owned: no TPV import, no shared Vendor model.
 */
class PurchaseActivationNotifier
{
    public const SUBJECT = 'Your Purchase Vendor Account Has Been Activated';

    public function __construct(private NotificationService $channels)
    {
    }

    /**
     * Queue the activation notice for after-commit dispatch.
     *
     * @param  string|null  $tempPassword  Only set when the system generated one.
     *                                     A vendor who chose their own password
     *                                     never receives a password by e-mail.
     */
    public function onActivated(PurchaseVendor $vendor, ?string $tempPassword = null): void
    {
        $id = $vendor->id;
        $temp = $tempPassword;

        // Runs immediately when no transaction is open, otherwise after commit.
        DB::afterCommit(function () use ($id, $temp) {
            $fresh = PurchaseVendor::find($id);
            if ($fresh) {
                $this->dispatch($fresh, $temp, false);
            }
        });
    }

    /**
     * "You are now a permanent vendor."
     *
     * The counterpart of the TPV notice, and the reason it matters is that the
     * vendor has been watching a countdown. Nothing else on their portal
     * announces that it has gone, so a conversion nobody tells them about looks
     * from their side exactly like a system that has forgotten to expire them.
     */
    public function onConvertedToPermanent(PurchaseVendor $vendor): void
    {
        $id = $vendor->id;

        DB::afterCommit(function () use ($id) {
            $fresh = PurchaseVendor::find($id);
            if (! $fresh || ! $fresh->email) {
                return;
            }

            $ctx = $this->context($fresh, null);
            $plain = 'Your account with '.$ctx['companyName'].' is now a permanent vendor account. '
                .'The temporary access period no longer applies, and your vendor code is '
                .$fresh->purchase_vendor_code.'.';

            $status = $this->channels->emailHtml(
                $fresh->email,
                'You are now a permanent vendor',
                view('emails.purchase.converted_to_permanent', $ctx)->render(),
                ['vendor_id' => $fresh->id, 'event' => 'converted_to_permanent'],
                $plain,
                $fresh->tenant_id,
            );

            if ($status !== 'sent') {
                Log::channel('purchase')->warning('Purchase conversion e-mail not delivered', [
                    'purchase_vendor_id' => $fresh->id, 'status' => $status,
                ]);
            }
        });
    }

    /**
     * "Your access now runs to <date>."
     *
     * Sent because the vendor has been watching a countdown and may already have
     * had warnings about it. Moving the date without saying so leaves them
     * planning around an expiry that is no longer there.
     */
    public function onAccessExtended(PurchaseVendor $vendor): void
    {
        $id = $vendor->id;

        DB::afterCommit(function () use ($id) {
            $fresh = PurchaseVendor::find($id);
            if (! $fresh || ! $fresh->email) {
                return;
            }

            $until = optional($fresh->access_expires_at)->format('d M Y');
            $ctx = $this->context($fresh, null) + ['until' => $until];
            $plain = 'Your temporary access to the '.$ctx['companyName']
                .' procurement portal has been extended to '.$until.'.';

            $status = $this->channels->emailHtml(
                $fresh->email,
                'Your temporary access has been extended',
                view('emails.purchase.access_extended', $ctx)->render(),
                ['vendor_id' => $fresh->id, 'event' => 'access_extended'],
                $plain,
                $fresh->tenant_id,
            );

            if ($status !== 'sent') {
                Log::channel('purchase')->warning('Purchase extension notice not delivered', [
                    'purchase_vendor_id' => $fresh->id, 'status' => $status,
                ]);
            }
        });
    }

    /** "Your temporary access ends in N." Sent once per threshold. */
    public function onAccessExpiring(PurchaseVendor $vendor, string $threshold): void
    {
        $labels = ['7d' => '7 days', '3d' => '3 days', '1d' => '1 day', '6h' => '6 hours'];
        $when = $labels[$threshold] ?? $threshold;
        $id = $vendor->id;

        DB::afterCommit(function () use ($id, $when, $threshold) {
            $fresh = PurchaseVendor::find($id);
            if (! $fresh || ! $fresh->email) {
                return;
            }

            $ctx = $this->context($fresh, null) + ['when' => $when];
            $plain = 'Your temporary access to the '.$ctx['companyName'].' procurement portal ends in '
                .$when.'. Contact your administrator if you need it extended.';

            $status = $this->channels->emailHtml(
                $fresh->email,
                'Your temporary access ends in '.$when,
                view('emails.purchase.access_expiring', $ctx)->render(),
                ['vendor_id' => $fresh->id, 'event' => 'access_expiring', 'threshold' => $threshold],
                $plain,
                $fresh->tenant_id,
            );

            if ($status !== 'sent') {
                Log::channel('purchase')->warning('Purchase expiry reminder not delivered', [
                    'purchase_vendor_id' => $fresh->id, 'threshold' => $threshold, 'status' => $status,
                ]);
            }
        });
    }

    /**
     * "Your temporary access has expired."
     *
     * Sent after the fact, deliberately: the vendor is already locked out by the
     * time this lands, and a message explaining why beats a login screen that
     * refuses them with no reason.
     */
    public function onAccessExpired(PurchaseVendor $vendor): void
    {
        $id = $vendor->id;

        DB::afterCommit(function () use ($id) {
            $fresh = PurchaseVendor::find($id);
            if (! $fresh || ! $fresh->email) {
                return;
            }

            $ctx = $this->context($fresh, null);
            $plain = 'Your temporary access to the '.$ctx['companyName'].' procurement portal has expired. '
                .'Please contact your administrator.';

            $status = $this->channels->emailHtml(
                $fresh->email,
                'Your temporary access has expired',
                view('emails.purchase.access_expired', $ctx)->render(),
                ['vendor_id' => $fresh->id, 'event' => 'access_expired'],
                $plain,
                $fresh->tenant_id,
            );

            if ($status !== 'sent') {
                Log::channel('purchase')->warning('Purchase expiry notice not delivered', [
                    'purchase_vendor_id' => $fresh->id, 'status' => $status,
                ]);
            }
        });
    }

    /** Admin-triggered resend. Always sends, always logged. */
    public function resend(PurchaseVendor $vendor): LogEntry
    {
        return $this->dispatch($vendor, null, true);
    }

    /**
     * Welcome + login credentials, sent the moment a vendor is added (before
     * activation) so they can sign in and complete onboarding. Mirrors the TPV
     * TpvActivationNotifier::onCredentialsIssued.
     */
    public function onCredentialsIssued(PurchaseVendor $vendor, string $password): void
    {
        $id = $vendor->id;
        $pw = $password;

        DB::afterCommit(function () use ($id, $pw) {
            $fresh = PurchaseVendor::find($id);
            if (! $fresh || ! $fresh->email) {
                return;
            }
            $ctx = $this->context($fresh, $pw);
            $status = $this->channels->emailHtml(
                $fresh->email,
                'Welcome to the '.$ctx['companyName'].' Procurement Portal — your login details',
                view('emails.purchase.welcome_credentials', $ctx)->render(),
                ['vendor_id' => $fresh->id, 'event' => 'credentials_issued'],
                $this->welcomePlainText($fresh, $pw, $ctx),
                $fresh->tenant_id,
            );
            if ($status !== 'sent') {
                Log::channel('purchase')->warning('Purchase welcome-credentials e-mail not delivered', [
                    'purchase_vendor_id' => $fresh->id, 'status' => $status,
                ]);
            }
        });
    }

    private function welcomePlainText(PurchaseVendor $vendor, string $password, array $ctx): string
    {
        return implode("\n", [
            "Hello {$vendor->company_name},",
            '',
            'Your vendor account has been created. Sign in to complete your onboarding.',
            '',
            "Login URL:  {$ctx['portalUrl']}",
            "Login ID:   {$vendor->email}",
            "Password:   {$password}",
            "Vendor Code: {$vendor->purchase_vendor_code}",
            '',
            'Please change your password after your first login.',
            '',
            "Need help? Contact {$ctx['supportEmail']}",
            '',
            'Regards,',
            $ctx['companyName'],
        ]);
    }

    /** The most recent notification for the Vendor Detail dashboard. */
    public function latestFor(PurchaseVendor $vendor): ?LogEntry
    {
        return LogEntry::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->latest('id')
            ->first();
    }

    private function dispatch(PurchaseVendor $vendor, ?string $tempPassword, bool $force): LogEntry
    {
        // Idempotence: never a second automatic activation e-mail. Refresh, edit
        // and re-activation attempts all land here and stop.
        if (! $force && LogEntry::alreadySent($vendor->tenant_id, $vendor->id, LogEntry::TYPE_ACTIVATED)) {
            return $this->record($vendor, 'skipped', 'Already notified — duplicate suppressed', null);
        }

        $subject = self::SUBJECT;

        // The channel service never throws; it returns sent|failed|skipped.
        $status = $this->channels->emailHtml(
            $vendor->email,
            $subject,
            $this->render($vendor, $tempPassword),
            ['purchase_vendor_id' => $vendor->id, 'event' => LogEntry::TYPE_ACTIVATED],
            $this->plainText($vendor, $tempPassword),
            $vendor->tenant_id,
        );

        if ($status !== 'sent') {
            // Activation stands regardless — the failure is recorded so an admin
            // can resend. Transport detail is deliberately not surfaced.
            Log::channel('purchase')->warning('Purchase activation e-mail not delivered', [
                'purchase_vendor_id' => $vendor->id, 'status' => $status,
            ]);
        }

        return $this->record(
            $vendor,
            $status === 'sent' ? 'sent' : 'failed',
            $status === 'sent' ? 'Delivered to mail transport' : 'Mail transport did not accept the message',
            $subject,
        );
    }

    private function record(PurchaseVendor $vendor, string $status, string $response, ?string $subject): LogEntry
    {
        return LogEntry::create([
            'tenant_id' => $vendor->tenant_id,
            'vendor_id' => $vendor->id,
            'type'      => LogEntry::TYPE_ACTIVATED,
            'channel'   => 'email',
            'subject'   => $subject ?? self::SUBJECT,
            'recipient' => $vendor->email,
            'status'    => $status,
            'sent_at'   => $status === 'sent' ? now() : null,
            'response'  => $response,
        ]);
    }

    /**
     * Render the Purchase activation template.
     *
     * $tempPassword is non-null ONLY in Scenario B (admin-created account whose
     * password the system just generated). Scenario A — the vendor chose their
     * own password at registration — passes null and the template omits the
     * whole password block. A stored hash is never read here, let alone sent.
     */
    /**
     * Plain-text alternative sent alongside the HTML part, so clients that block
     * HTML still get every fact the card carries. Mirrors the template's content
     * exactly — including omitting the password block in Scenario A.
     */
    private function plainText(PurchaseVendor $vendor, ?string $tempPassword): string
    {
        $ctx = $this->context($vendor, $tempPassword);

        $lines = [
            "Hello {$vendor->company_name},",
            '',
            'Your account has been approved and activated. You can now sign in to the procurement portal.',
            '',
            "Login URL:         {$ctx['portalUrl']}",
            "Registered Email:  {$vendor->email}",
            "Vendor Code:       {$vendor->purchase_vendor_code}",
            "Registration Type: {$ctx['registrationType']}",
            "Activation Date:   {$ctx['activationDate']}",
        ];

        if ($tempPassword) {
            $lines[] = '';
            $lines[] = "Temporary Password: {$tempPassword}";
            $lines[] = 'First login: sign in with the email and temporary password above.';
            $lines[] = 'For security reasons please change your password immediately after your first login.';
        }

        $lines[] = '';
        $lines[] = "Need help? Contact {$ctx['supportEmail']}";
        $lines[] = '';
        $lines[] = 'Regards,';
        $lines[] = $ctx['companyName'];

        return implode("\n", $lines);
    }

    /** Everything both renderings need, resolved once. */
    private function context(PurchaseVendor $vendor, ?string $tempPassword): array
    {
        return [
            'vendor'           => $vendor,
            'companyName'      => config('app.name', 'Our Company'),
            'logoUrl'          => config('mail.logo_url'),
            'supportEmail'     => config('mail.support_address', config('mail.from.address', 'support@example.com')),
            // The single sign-in page, with the identity preselected. The portal
            // no longer has a login screen of its own; /purchase-portal/login
            // still redirects here for links already in the wild.
            'portalUrl'        => FrontendUrl::to('/auth/login?role=purchase_vendor'),
            'registrationType' => PurchaseRegistrationType::label($vendor->registration_type),
            'activationDate'   => now()->format('d M Y, H:i'),
            'tempPassword'     => $tempPassword,
        ];
    }

    private function render(PurchaseVendor $vendor, ?string $tempPassword): string
    {
        return view('emails.purchase.activation', $this->context($vendor, $tempPassword))->render();
    }
}
