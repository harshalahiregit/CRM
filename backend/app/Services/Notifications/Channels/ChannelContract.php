<?php

namespace App\Services\Notifications\Channels;

use App\Models\Notifications\HrNotification;

/**
 * A delivery channel (Central Notification Engine). Implementations deliver one
 * notification and report the outcome. New channels (a real SMS/WhatsApp provider,
 * push, etc.) implement this interface and register in ChannelManager — no engine
 * or caller changes.
 */
interface ChannelContract
{
    public function key(): string;

    /** @return array{ok: bool, error: ?string} */
    public function send(HrNotification $notification): array;
}
