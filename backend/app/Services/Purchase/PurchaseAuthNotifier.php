<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Services\Notifications\NotificationService;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Vendor self-service auth mail — e-mail verification and password
 * reset.
 *
 * These two mails were never implemented: register() and forgotPassword()
 * minted a token and wrote it to purchase-*.log instead of sending it, so a
 * vendor could register and never hear back, and password reset was dead
 * end-to-end despite the UI shipping.
 *
 * Delivery goes through NotificationService, which resolves the tenant's own
 * SMTP (Settings -> Email) and falls back to the global mailer — so this is
 * governed by the same configuration as vendor activation. Sending happens
 * after commit so a rolled-back registration never mails a live token, and a
 * delivery failure never breaks the surrounding action.
 *
 * Purchase-owned: no TPV import.
 */
class PurchaseAuthNotifier
{
    public const VERIFY_SUBJECT = 'Confirm your email address';
    public const RESET_SUBJECT  = 'Reset your password';

    public function __construct(private NotificationService $channels)
    {
    }

    /** Verification link sent immediately after self-registration. */
    public function onRegistered(PurchaseVendor $vendor): void
    {
        $this->afterCommit($vendor->id, function (PurchaseVendor $v) {
            if (! $v->email_verification_token) {
                return;   // already verified in the meantime
            }

            $url = FrontendUrl::to('/purchase-portal/verify-email?token='.urlencode($v->email_verification_token));

            $this->deliver($v, self::VERIFY_SUBJECT, 'emails.purchase.verify-email', [
                'actionUrl' => $url,
            ], 'email_verification');
        });
    }

    /** Reset link. The page prefills from ?email= and ?token=. */
    public function onPasswordResetRequested(PurchaseVendor $vendor): void
    {
        $this->afterCommit($vendor->id, function (PurchaseVendor $v) {
            if (! $v->password_reset_token) {
                return;
            }

            $url = FrontendUrl::to('/purchase-portal/reset-password'
                .'?email='.urlencode((string) $v->email)
                .'&token='.urlencode($v->password_reset_token));

            $this->deliver($v, self::RESET_SUBJECT, 'emails.purchase.password-reset', [
                'actionUrl' => $url,
                'expiresAt' => optional($v->password_reset_expires_at)->format('d M Y, H:i'),
            ], 'password_reset');
        });
    }

    /** Re-read the row after commit so a rolled-back write never gets mailed. */
    private function afterCommit(int $id, callable $fn): void
    {
        DB::afterCommit(function () use ($id, $fn) {
            $fresh = PurchaseVendor::find($id);
            if ($fresh) {
                $fn($fresh);
            }
        });
    }

    private function deliver(PurchaseVendor $vendor, string $subject, string $view, array $extra, string $event): void
    {
        $ctx = $extra + [
            'vendor'       => $vendor,
            'companyName'  => config('app.name', 'Our Company'),
            'logoUrl'      => config('mail.logo_url'),
            'supportEmail' => config('mail.support_address', config('mail.from.address', 'support@example.com')),
        ];

        $status = $this->channels->emailHtml(
            $vendor->email,
            $subject,
            view($view, $ctx)->render(),
            ['purchase_vendor_id' => $vendor->id, 'event' => $event],
            $this->plainText($subject, $ctx),
            // Explicit: no authenticated user exists during self-registration or
            // a forgotten-password request, so the tenant must come from the row.
            $vendor->tenant_id,
        );

        if ($status !== 'sent') {
            // Deliberately no token in this log line — see the class docblock.
            Log::channel('purchase')->warning('Purchase vendor auth mail not delivered', [
                'purchase_vendor_id' => $vendor->id, 'event' => $event, 'status' => $status,
            ]);
        }
    }

    private function plainText(string $subject, array $ctx): string
    {
        return implode("\n", [
            $subject,
            '',
            'Hello '.($ctx['vendor']->company_name ?: 'there').',',
            '',
            'Open this link to continue:',
            $ctx['actionUrl'],
            '',
            'If you did not request this, you can ignore this email.',
            '',
            'Need help? Contact '.$ctx['supportEmail'],
            '',
            'Regards,',
            $ctx['companyName'],
        ]);
    }
}
