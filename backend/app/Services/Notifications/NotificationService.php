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
}
