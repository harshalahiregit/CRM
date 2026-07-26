<?php

namespace App\Services\Notifications\Channels;

use App\Models\Notifications\HrNotification;
use Illuminate\Support\Facades\Log;

/**
 * Prepared (not-yet-live) channel (Central Notification Engine) — SMS, WhatsApp,
 * Microsoft Teams, Slack, Push. The architecture is complete: queue items are
 * created and processed, and a real provider drops in by replacing this class in
 * ChannelManager. Until then it records intent and reports the channel as pending
 * so the Queue Monitor shows the honest state (no false "delivered").
 */
class PreparedChannel implements ChannelContract
{
    public function __construct(private string $channel)
    {
    }

    public function key(): string
    {
        return $this->channel;
    }

    public function send(HrNotification $notification): array
    {
        Log::channel('hr')->info('Notification channel prepared (provider pending)', [
            'channel' => $this->channel, 'notification_id' => $notification->id, 'module' => $notification->module,
        ]);

        return ['ok' => false, 'error' => ucfirst($this->channel).' channel is prepared — provider not configured.'];
    }
}
