<?php

namespace App\Services\Notifications\Channels;

use App\Models\Notifications\HrNotification;
use App\Models\User;
use App\Services\Notifications\NotificationService;

/**
 * Email channel (Central Notification Engine). Resolves the recipient user's email
 * and delivers via the existing App\Services\Notifications\NotificationService
 * (no duplicated Mail logic). Fully working.
 */
class EmailChannel implements ChannelContract
{
    public function __construct(private NotificationService $mailer)
    {
    }

    public function key(): string
    {
        return 'email';
    }

    public function send(HrNotification $notification): array
    {
        $email = $notification->recipient_user_id
            ? optional(User::find($notification->recipient_user_id))->email
            : null;

        if (! $email) {
            return ['ok' => false, 'error' => 'No recipient email address on file.'];
        }

        $status = $this->mailer->email($email, $notification->title, (string) $notification->message, [
            'module' => $notification->module, 'event' => $notification->event, 'notification_id' => $notification->id,
        ]);

        return $status === 'failed'
            ? ['ok' => false, 'error' => 'Mail transport failed.']
            : ['ok' => true, 'error' => null];
    }
}
