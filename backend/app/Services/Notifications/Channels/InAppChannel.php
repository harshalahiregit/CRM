<?php

namespace App\Services\Notifications\Channels;

use App\Models\Notifications\HrNotification;

/**
 * In-App channel (Central Notification Engine). The notification row itself IS the
 * in-app delivery (rendered in the bell + Notification Center), so delivery is
 * always immediately successful. Fully working.
 */
class InAppChannel implements ChannelContract
{
    public function key(): string
    {
        return 'in_app';
    }

    public function send(HrNotification $notification): array
    {
        return ['ok' => true, 'error' => null];
    }
}
