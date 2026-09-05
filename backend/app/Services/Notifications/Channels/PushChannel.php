<?php

namespace App\Services\Notifications\Channels;

use App\Models\Hr\HrDeviceToken;
use App\Models\Notifications\HrNotification;
use App\Services\Notifications\FcmSender;
use Illuminate\Support\Facades\Log;

/**
 * Push, to every device the recipient has registered.
 *
 * Replaces the PreparedChannel placeholder that stood here reporting "provider
 * not configured" — the architecture always expected this class; only the
 * credential was missing.
 *
 * Delivery is per device, and a person may have several. One succeeding is
 * success: somebody with a working phone and a stale tablet has been reached,
 * and failing the whole notification because of the tablet would hide that.
 *
 * Tokens FCM declares dead are deleted as we go. The alternative is retrying an
 * uninstalled app forever and a queue that looks broken when it is healthy —
 * and after a Firebase project change EVERY old token is dead at once, so this
 * is the path that clears them.
 */
class PushChannel implements ChannelContract
{
    public function __construct(private FcmSender $fcm)
    {
    }

    public function key(): string
    {
        return 'push';
    }

    public function send(HrNotification $notification): array
    {
        if (! $this->fcm->configured()) {
            return ['ok' => false, 'error' => 'Push is not configured on this server.'];
        }

        $userId = $notification->recipient_user_id;
        if (! $userId) {
            // A role-addressed notification has no single device to reach.
            return ['ok' => false, 'error' => 'Notification has no individual recipient to push to.'];
        }

        $tokens = HrDeviceToken::forUser((int) $notification->tenant_id, (int) $userId);

        if ($tokens === []) {
            return ['ok' => false, 'error' => 'No registered device for this person.'];
        }

        $sent = 0;
        $retired = 0;
        $lastError = null;

        foreach ($tokens as $token) {
            $result = $this->fcm->send(
                $token,
                (string) $notification->title,
                (string) $notification->message,
                array_filter([
                    'notification_id' => (string) $notification->id,
                    'module'          => (string) $notification->module,
                    'event'           => (string) $notification->event,
                    'action_url'      => (string) $notification->action_url,
                ], fn ($v) => $v !== ''),
            );

            if ($result['ok']) {
                $sent++;
                continue;
            }

            $lastError = $result['error'];

            if ($result['retire']) {
                HrDeviceToken::forget($token);
                $retired++;
            }
        }

        if ($retired > 0) {
            Log::channel('hr')->info('Retired dead push tokens', [
                'notification_id' => $notification->id, 'count' => $retired,
            ]);
        }

        if ($sent > 0) {
            return ['ok' => true, 'error' => null];
        }

        return ['ok' => false, 'error' => $lastError ?: 'No device accepted the message.'];
    }
}
