<?php

namespace App\Services\Notifications\Channels;

/**
 * Channel registry (Central Notification Engine). Resolves a channel key to its
 * handler — in_app, email, push and whatsapp are live; sms/teams/slack are
 * prepared. Register a real provider by mapping its key here; nothing else
 * changes.
 */
class ChannelManager
{
    // Push left this list when the Firebase service account arrived, and
    // WhatsApp when the Cloud API credentials did. The rest still have no
    // provider and say so rather than pretending to deliver.
    private const PREPARED = ['sms', 'teams', 'slack'];

    public function __construct(
        private InAppChannel $inApp,
        private EmailChannel $email,
        private PushChannel $push,
        private WhatsAppChannel $whatsapp,
    ) {
    }

    public function for(string $channel): ChannelContract
    {
        return match ($channel) {
            'in_app' => $this->inApp,
            'email'  => $this->email,
            // Real, since the Firebase service account arrived. Previously fell
            // through to PreparedChannel, which honestly reported push as not
            // configured rather than pretending to deliver.
            'push'   => $this->push,
            // Real since the Cloud API credentials arrived. Business-initiated
            // messages go as an approved template; see WhatsAppChannel.
            'whatsapp' => $this->whatsapp,
            default  => new PreparedChannel($channel),
        };
    }

    public function isPrepared(string $channel): bool
    {
        return in_array($channel, self::PREPARED, true);
    }
}
