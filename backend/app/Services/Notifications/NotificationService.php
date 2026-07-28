<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
    /**
     * Send an email notification. Returns the delivery status ('sent'|'skipped'|'failed').
     * Never throws — a notification failure must not break the business action.
     */
    public function email(?string $to, string $subject, string $body, array $context = []): string
    {
        if (! $to) {
            Log::channel('hr')->warning('Notification skipped: no recipient', ['subject' => $subject] + $context);

            return 'skipped';
        }

        try {
            Mail::html(nl2br(e($body)), function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject);
            });

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
    public function emailHtml(?string $to, string $subject, string $html, array $context = [], ?string $text = null): string
    {
        if (! $to) {
            Log::channel('hr')->warning('Notification skipped: no recipient', ['subject' => $subject] + $context);

            return 'skipped';
        }

        try {
            // multipart/alternative when a text part is supplied, so clients that
            // refuse HTML (and screen readers) still get readable content.
            Mail::send([], [], function ($m) use ($to, $subject, $html, $text) {
                $m->to($to)->subject($subject)->html($html);
                if ($text !== null && $text !== '') {
                    $m->text($text);
                }
            });

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
        Log::channel('hr')->info('WhatsApp notification queued (provider pending)', ['to' => $to] + $context);

        return 'queued';
    }

    /** SMS / text channel. Stubbed like WhatsApp — logs intent, provider plugs in here. */
    public function sms(?string $to, string $body, array $context = []): string
    {
        if (! $to) {
            return 'skipped';
        }
        Log::channel('hr')->info('SMS notification queued (provider pending)', ['to' => $to] + $context);

        return 'queued';
    }
}
