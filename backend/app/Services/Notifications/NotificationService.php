<?php

namespace App\Services\Notifications;

use App\Services\Mail\TenantMailer;
use App\Services\Settings\SettingsService;
use App\Support\Settings\SettingRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Channel-abstracted notification dispatcher (Company Portal).
 *
 * Today only the Email channel is implemented; In-App / WhatsApp / SMS are future
 * channels that plug in here without changing any caller. Callers describe an
 * event + recipient + rendered copy; the service picks channels and records the
 * outcome. WhatsApp would reuse the existing WhatsAppService; In-App a future
 * notifications table.
 */
class NotificationService
{
    public function __construct(
        private TenantMailer $mailer,
        private SettingsService $settings,
    ) {
    }

    /**
     * Categories whose delivery is NOT suppressible.
     *
     * Security mail is how people get back into their accounts — a password
     * reset, an invite, a login alert. An admin switching "Security → Email" off
     * in a preferences grid should not be able to lock their workspace out, so
     * that combination is ignored with a log rather than honoured.
     */
    private const ALWAYS_DELIVER = ['Security'];

    /** Per-request cache: one settings read serves a whole payroll run. */
    private array $prefs = [];

    /**
     * Whether this tenant wants `$channel` for `$category`.
     *
     * The preferences page has always written a five-channel master row plus an
     * 11x11 category matrix into the `notifications` group, reported success,
     * and been read by nothing — so turning Email off for Payroll changed
     * nothing and the admin had no way to tell. This is the read side.
     *
     * A call with NO category is transactional (an acknowledgement, a reset) and
     * is always delivered: those are not the "notifications" this grid governs,
     * and silently dropping them would break flows the user started.
     */
    public function allows(string $channel, ?string $category, ?int $tenantId = null): bool
    {
        if ($category === null) {
            return true;
        }
        if (in_array($category, self::ALWAYS_DELIVER, true)) {
            return true;
        }

        $tenantId = $this->resolveTenantId($tenantId);
        if ($tenantId === null) {
            return true;   // no tenant context — nothing to consult
        }

        if (! array_key_exists($tenantId, $this->prefs)) {
            try {
                $this->prefs[$tenantId] = $this->settings->getGroup($tenantId, 'notifications');
            } catch (\Throwable $e) {
                // A preferences read must never stop a notification. Fail OPEN:
                // a missed email is a worse outcome than an unwanted one.
                Log::channel('hr')->warning('Notification preferences unreadable; delivering anyway', [
                    'tenant_id' => $tenantId, 'error' => $e->getMessage(),
                ]);
                $this->prefs[$tenantId] = [];
            }
        }

        $prefs = $this->prefs[$tenantId];

        // Master switch for the channel. Absent means the registry default.
        if (array_key_exists($channel, $prefs) && ! filter_var($prefs[$channel], FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $row = $prefs['categories'][$category] ?? null;
        if (is_array($row) && array_key_exists($channel, $row)) {
            return (bool) filter_var($row[$channel], FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    }

    /** Log and return 'suppressed' so a caller can tell this apart from a failure. */
    private function suppressed(string $channel, string $category, array $context): string
    {
        Log::channel('hr')->info('Notification suppressed by tenant preference', [
            'channel' => $channel, 'category' => $category,
        ] + $context);

        return 'suppressed';
    }

    /**
     * Which tenant's SMTP settings to send with.
     *
     * Explicit wins (a notifier that knows the subject's tenant should say so —
     * it stays correct in queued/console context where there is no user), then
     * the acting user's tenant. Null means "no tenant context", and TenantMailer
     * falls back to the global .env mailer.
     */
    private function resolveTenantId(?int $tenantId): ?int
    {
        return $tenantId ?? Auth::user()?->tenant_id;
    }

    /**
     * Send an email notification. Returns the delivery status ('sent'|'skipped'|'failed').
     * Never throws — a notification failure must not break the business action.
     */
    public function email(?string $to, string $subject, string $body, array $context = [], ?int $tenantId = null): string
    {
        if (! $to) {
            Log::channel('hr')->warning('Notification skipped: no recipient', ['subject' => $subject] + $context);

            return 'skipped';
        }

        $category = $context['category'] ?? null;
        if ($category !== null && ! $this->allows('email', $category, $tenantId)) {
            return $this->suppressed('email', $category, ['subject' => $subject] + $context);
        }

        try {
            $this->mailer->sendRawHtml($this->resolveTenantId($tenantId), $to, $subject, nl2br(e($body)));

            return 'sent';
        } catch (\Throwable $e) {
            Log::channel('hr')->warning('Notification email failed', ['subject' => $subject, 'error' => $e->getMessage()] + $context);

            return 'failed';
        }
    }

    /**
     * Send a pre-rendered HTML e-mail (e.g. a Blade template). Same contract as
     * email(): never throws, returns 'sent'|'skipped'|'failed'. Kept separate
     * from email() because that one escapes its body for plain-text callers.
     */
    public function emailHtml(?string $to, string $subject, string $html, array $context = [], ?string $text = null, ?int $tenantId = null, array $attachments = []): string
    {
        if (! $to) {
            Log::channel('hr')->warning('Notification skipped: no recipient', ['subject' => $subject] + $context);

            return 'skipped';
        }

        $category = $context['category'] ?? null;
        if ($category !== null && ! $this->allows('email', $category, $tenantId)) {
            return $this->suppressed('email', $category, ['subject' => $subject] + $context);
        }

        try {
            // multipart/alternative when a text part is supplied, so clients that
            // refuse HTML (and screen readers) still get readable content.
            $this->mailer->sendRawHtml($this->resolveTenantId($tenantId), $to, $subject, $html, $text, $attachments);

            return 'sent';
        } catch (\Throwable $e) {
            Log::channel('hr')->warning('Notification email failed', ['subject' => $subject, 'error' => $e->getMessage()] + $context);

            return 'failed';
        }
    }

    /**
     * WhatsApp channel. Stubbed until a provider (e.g. WhatsAppService/Meta Cloud
     * API) is wired — records intent so the multi-channel flow is complete and the
     * provider drops in here without touching callers. Returns 'queued'|'skipped'.
     */
    public function whatsapp(?string $to, string $body, array $context = []): string
    {
        if (! $to) {
            return 'skipped';
        }
        $category = $context['category'] ?? null;
        // These two have no $tenantId parameter; a caller that knows the tenant
        // passes it in context, exactly as it does for logging.
        if ($category !== null && ! $this->allows('whatsapp', $category, $context['tenant_id'] ?? null)) {
            return $this->suppressed('whatsapp', $category, ['to' => $to] + $context);
        }
        Log::channel('hr')->info('WhatsApp notification queued (provider pending)', ['to' => $to] + $context);

        return 'queued';
    }

    /** SMS / text channel. Stubbed like WhatsApp — logs intent, provider plugs in here. */
    public function sms(?string $to, string $body, array $context = []): string
    {
        if (! $to) {
            return 'skipped';
        }
        $category = $context['category'] ?? null;
        if ($category !== null && ! $this->allows('sms', $category, $context['tenant_id'] ?? null)) {
            return $this->suppressed('sms', $category, ['to' => $to] + $context);
        }
        Log::channel('hr')->info('SMS notification queued (provider pending)', ['to' => $to] + $context);

        return 'queued';
    }
}
