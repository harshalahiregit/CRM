<?php

namespace App\Services\Notifications\Channels;

use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrWhatsAppLog;
use App\Models\Notifications\HrNotification;
use App\Services\Settings\SettingsService;
use App\Services\WhatsApp\TenantWhatsApp;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp, via whichever number the tenant sends from.
 *
 * Replaces the PreparedChannel placeholder that reported "provider not
 * configured" — the architecture always expected this class; only the
 * credential was missing.
 *
 * THE TEMPLATE IS NOT A STYLE CHOICE. WhatsApp only carries free-form text
 * inside the 24-hour window that opens when the PERSON messages the business.
 * Everything a CRM sends is business-initiated — an approval, a rejection, a
 * clock-out nudge — so it must go as an approved template or Meta refuses it.
 * That is why this channel sends a template and not $notification->message,
 * and why an unapproved template name is reported as a configuration problem
 * rather than retried forever.
 *
 * The free-text path is still tried FIRST when the window is known to be open,
 * because a real message reads better than a templated one; the template is the
 * fallback, not the other way round. Meta tells us which case we are in
 * (131047), so we do not have to track windows ourselves.
 */
class WhatsAppChannel implements ChannelContract
{
    public function __construct(
        private TenantWhatsApp $tenants,
        private SettingsService $settings,
    ) {
    }

    public function key(): string
    {
        return 'whatsapp';
    }

    public function send(HrNotification $notification): array
    {
        $tenantId = (int) $notification->tenant_id;

        $client = $this->tenants->clientFor($tenantId);
        if (! $client) {
            return ['ok' => false, 'error' => 'WhatsApp is not configured for this workspace.'];
        }

        $to = $this->numberFor($notification);
        if ($to === null) {
            // Not an error worth alarming anybody about: plenty of people on the
            // payroll have no mobile number recorded.
            return ['ok' => false, 'error' => 'No mobile number on file for this person.'];
        }

        $title = trim((string) $notification->title);
        $body  = trim((string) $notification->message);

        $log = HrWhatsAppLog::create([
            'tenant_id'  => $tenantId,
            'to_number'  => $to,
            'event_type' => trim($notification->module.' '.$notification->event) ?: 'notification',
            'message'    => trim($title."\n\n".$body),
            'status'     => 'queued',
        ]);

        // Always a template, never free text.
        //
        // Everything here is business-initiated — nobody messages the CRM to
        // start a thread — so WhatsApp only carries it as an approved template.
        // This used to try free text first and fall back on error 131047, on
        // the assumption that Meta rejects text outside the service window. It
        // does not do so reliably: measured on 2026-09-07, Graph ACCEPTED every
        // free-text send with a real wamid and no error, then delivered none of
        // them. Because there was no error, the fallback never ran and the row
        // was logged 'sent'. Every notification since the window closed had
        // been vanishing, and the logs said the integration was healthy.
        $template = $this->templateFor($notification, $title, $body);
        $result = $client->sendTemplate(
            $to, $template['name'], $template['language'], $template['params'], $template['header_image'],
        );

        if ($result->ok) {
            $log->update(['message_sid' => $result->messageId, 'status' => 'sent', 'sent_at' => now()]);

            return ['ok' => true, 'error' => null];
        }

        $log->update(['status' => 'failed', 'error_message' => $result->error]);

        if ($result->isAuthProblem()) {
            // Worth its own line in the log: every WhatsApp in the workspace is
            // failing for one reason, and it is not the recipient's fault.
            Log::channel('errors')->error('WhatsApp token rejected', [
                'tenant_id' => $tenantId, 'code' => $result->code, 'error' => $result->error,
            ]);
        }

        return ['ok' => false, 'error' => $result->error];
    }

    /**
     * Their WhatsApp number.
     *
     * The employee record wins over the login: HR keeps the employee's mobile
     * current and a user row's phone is often whatever they typed at signup.
     */
    private function numberFor(HrNotification $notification): ?string
    {
        $userId = $notification->recipient_user_id;
        if (! $userId) {
            // A role-addressed notification has no one number to send to.
            return null;
        }

        $phone = HrEmployee::where('tenant_id', $notification->tenant_id)
            ->where('user_id', $userId)
            ->value('phone');

        $phone = $phone ?: $notification->recipient?->phone;

        return filled($phone) ? (string) $phone : null;
    }

    /**
     * Which approved template carries this notification, and what fills it.
     *
     * A template's parameter count must match EXACTLY -- Meta refuses the send
     * otherwise -- so the map names its values rather than everything guessing
     * at two. sangoe_clockout_reminder takes one (the person's name); the
     * generic fallback takes two (title, body).
     *
     * @return array{name: string, language: string, params: array<int, string>}
     */
    private function templateFor(HrNotification $notification, string $title, string $body): array
    {
        $tenantId = (int) $notification->tenant_id;
        $key = trim($notification->module.' '.$notification->event);
        $map = (array) config('whatsapp.cloud.templates', []);

        if (! isset($map[$key])) {
            // With a public logo configured, the branded template puts the
            // company's mark across the top of the message. Without one the
            // plain template still sends — a plainer message beats none.
            $logo = $this->publicLogo($tenantId);

            return [
                'name' => (string) ($logo
                    ? config('whatsapp.cloud.notification_template_with_logo')
                    : config('whatsapp.cloud.notification_template', 'sangoe_notification')),
                'language' => (string) config('whatsapp.cloud.notification_template_language', 'en'),
                'params'   => [
                    $title !== '' ? $title : 'Update',
                    $body !== '' ? $body : 'Open the Sangoe app for details.',
                ],
                'header_image' => $logo,
            ];
        }

        $entry = $map[$key];
        $values = [
            'name'  => (string) ($notification->recipient?->name ?: 'there'),
            'title' => $title !== '' ? $title : 'Update',
            'body'  => $body !== '' ? $body : 'Open the Sangoe app for details.',
        ];

        return [
            'name'         => (string) $entry['name'],
            'language'     => (string) ($entry['language'] ?? 'en'),
            'params'       => array_map(fn ($t) => $values[$t] ?? '', (array) ($entry['params'] ?? ['title', 'body'])),
            // A mapped template was approved with whatever header it has; we do
            // not bolt an image onto one that has no slot for it.
            'header_image' => $entry['header_image'] ?? null,
        ];
    }

    /**
     * The branding logo, but only if Meta could actually fetch it.
     *
     * http, localhost and a LAN address all look fine in the settings screen
     * and are all unreachable from Meta's side, which shows as a message with a
     * blank header rather than as an error.
     */
    private function publicLogo(int $tenantId): ?string
    {
        try {
            $url = (string) $this->settings->get($tenantId, 'branding', 'logo_url');
        } catch (\Throwable) {
            return null;
        }

        if (! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';

        return preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i', $host)
            ? null
            : $url;
    }
}
