<?php

namespace App\Services\Notifications\Channels;

/**
 * Channel registry (Central Notification Engine). Resolves a channel key to its
 * handler — in_app + email are live; sms/whatsapp/teams/slack/push are prepared.
 * Register a real provider by mapping its key here; nothing else changes.
 */
class ChannelManager
{
    private const PREPARED = ['sms', 'whatsapp', 'teams', 'slack', 'push'];

    public function __construct(
        private InAppChannel $inApp,
        private EmailChannel $email,
    ) {
    }

    public function for(string $channel): ChannelContract
    {
        return match ($channel) {
            'in_app' => $this->inApp,
            'email'  => $this->email,
            default  => new PreparedChannel($channel),
        };
    }

    public function isPrepared(string $channel): bool
    {
        return in_array($channel, self::PREPARED, true);
    }
}
