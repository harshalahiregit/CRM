<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvNotificationLog as LogEntry;
use App\Models\Vendor\Vendor;
use App\Services\Notifications\NotificationService;
use App\Support\Tpv\TpvRegistrationType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TPV activation notification.
 *
 * Called from the activation service (never a controller, never the frontend)
 * and only once the surrounding transaction has committed — a rolled-back
 * activation must not e-mail anyone. Delivery goes through the generic channel
 * abstraction (email live; SMS/WhatsApp stubs), and every attempt is written to
 * tpv_notification_logs, which is what makes "exactly once" enforceable.
 *
 * TPV-owned: no Purchase import.
 */
class TpvActivationNotifier
{
    public const SUBJECT = 'Your TPV Account Has Been Activated';

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
    public function onActivated(Vendor $vendor, ?string $tempPassword = null): void
    {
        $id = $vendor->id;
        $temp = $tempPassword;

        DB::afterCommit(function () use ($id, $temp) {
            $fresh = Vendor::find($id);
            if ($fresh) {
                $this->dispatch($fresh, $temp, false);
            }
        });
    }

    /** Admin-triggered resend. Always sends, always logged. */
    public function resend(Vendor $vendor): LogEntry
    {
        return $this->dispatch($vendor, null, true);
    }

    /** The most recent notification for the Vendor Detail dashboard. */
    public function latestFor(Vendor $vendor): ?LogEntry
    {
        return LogEntry::forTenant($vendor->tenant_id)
            ->where('vendor_id', $vendor->id)
            ->latest('id')
            ->first();
    }

    private function dispatch(Vendor $vendor, ?string $tempPassword, bool $force): LogEntry
    {
        if (! $force && LogEntry::alreadySent($vendor->tenant_id, $vendor->id, LogEntry::TYPE_ACTIVATED)) {
            return $this->record($vendor, 'skipped', 'Already notified — duplicate suppressed', null);
        }

        $subject = self::SUBJECT;

        $status = $this->channels->emailHtml(
            $vendor->email,
            $subject,
            $this->render($vendor, $tempPassword),
            ['vendor_id' => $vendor->id, 'event' => LogEntry::TYPE_ACTIVATED],
            $this->plainText($vendor, $tempPassword),
        );

        if ($status !== 'sent') {
            Log::channel('vendor')->warning('TPV activation e-mail not delivered', [
                'vendor_id' => $vendor->id, 'status' => $status,
            ]);
        }

        return $this->record(
            $vendor,
            $status === 'sent' ? 'sent' : 'failed',
            $status === 'sent' ? 'Delivered to mail transport' : 'Mail transport did not accept the message',
            $subject,
        );
    }

    private function record(Vendor $vendor, string $status, string $response, ?string $subject): LogEntry
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
     * Render the TPV activation template.
     *
     * $tempPassword is non-null ONLY in Scenario B (admin-created account whose
     * password the system just generated). Scenario A — the vendor chose their
     * own password at registration — passes null and the template omits the
     * whole password block. A stored hash is never read here, let alone sent.
     */
    private function render(Vendor $vendor, ?string $tempPassword): string
    {
        return view('emails.tpv.activation', $this->context($vendor, $tempPassword))->render();
    }

    /**
     * Plain-text alternative sent alongside the HTML part, so clients that block
     * HTML still get every fact the card carries. Mirrors the template's content
     * exactly — including omitting the password block in Scenario A.
     */
    private function plainText(Vendor $vendor, ?string $tempPassword): string
    {
        $ctx = $this->context($vendor, $tempPassword);

        $lines = [
            "Hello {$vendor->company_name},",
            '',
            'Your account has been approved and activated. You can now sign in to the vendor portal.',
            '',
            "Login URL:         {$ctx['portalUrl']}",
            "Registered Email:  {$vendor->email}",
            "TPV Code:          {$vendor->vendor_code}",
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
    private function context(Vendor $vendor, ?string $tempPassword): array
    {
        return [
            'vendor'           => $vendor,
            'companyName'      => config('app.name', 'Our Company'),
            'logoUrl'          => config('mail.logo_url'),
            'supportEmail'     => config('mail.support_address', config('mail.from.address', 'support@example.com')),
            'portalUrl'        => rtrim(config('app.frontend_url', config('app.url', '')), '/').'/vendor-portal/login',
            'registrationType' => TpvRegistrationType::label($vendor->registration_type),
            'activationDate'   => now()->format('d M Y, H:i'),
            'tempPassword'     => $tempPassword,
        ];
    }
}
