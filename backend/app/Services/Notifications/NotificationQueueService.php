<?php

namespace App\Services\Notifications;

use App\Exceptions\BusinessException;
use App\Models\Notifications\HrNotificationQueueItem;
use App\Models\User;
use App\Services\Notifications\Channels\ChannelManager;
use Illuminate\Support\Facades\Log;

/**
 * Notification delivery worker (Central Notification Engine). Processes Pending
 * queue items across channels, retrying Failed ones up to the configured ceiling,
 * and records every outcome (Email Queued/Sent/Failed, Reminder Sent) on the parent
 * notification's audit trail. Called by the scheduler command and by manual retry.
 * Tenant-scoped, idempotent.
 */
class NotificationQueueService
{
    public function __construct(
        private ChannelManager $channels,
        private ModuleEventCatalog $catalog,
    ) {
    }

    /** Process due queue items. Returns ['sent'=>x,'failed'=>y,'processed'=>n]. */
    public function process(?int $tenantId = null, int $limit = 500, ?User $actor = null): array
    {
        $items = HrNotificationQueueItem::with('notification')
            ->where('status', HrNotificationQueueItem::PENDING)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('id')->limit($limit)->get();

        $sent = 0; $failed = 0;
        foreach ($items as $item) {
            [$ok] = $this->deliver($item, $actor);
            $ok ? $sent++ : $failed++;
        }

        return ['processed' => $items->count(), 'sent' => $sent, 'failed' => $failed];
    }

    /** Retry one failed queue item (respects the retry ceiling). */
    public function retry(int $id, int $tenantId, ?User $actor = null): array
    {
        $item = HrNotificationQueueItem::with('notification')->where('tenant_id', $tenantId)->find($id);
        if (! $item) {
            throw new BusinessException('Queue item not found', 404);
        }
        if ($item->status === HrNotificationQueueItem::SENT) {
            throw new BusinessException('This item was already delivered.');
        }
        if ($item->retry_count >= $this->catalog->maxRetries()) {
            throw new BusinessException('Maximum retry attempts reached for this item.');
        }
        [$ok] = $this->deliver($item, $actor, true);
        $item->notification?->recordAudit('Reminder Resent', $actor, null, ['channel' => $item->channel, 'ok' => $ok]);

        return ['ok' => $ok];
    }

    /** Re-queue and deliver an email for an existing notification (Resend Email action). */
    public function resend(\App\Models\Notifications\HrNotification $notification, ?User $actor = null): array
    {
        $item = HrNotificationQueueItem::create([
            'tenant_id' => $notification->tenant_id,
            'notification_id' => $notification->id,
            'channel' => 'email',
            'status' => HrNotificationQueueItem::PENDING,
        ]);
        [$ok] = $this->deliver($item, $actor);

        return ['ok' => $ok];
    }

    /** Deliver a single queue item via its channel and record the outcome. */
    private function deliver(HrNotificationQueueItem $item, ?User $actor, bool $isRetry = false): array
    {
        $notification = $item->notification;
        if (! $notification) {
            $item->update(['status' => HrNotificationQueueItem::FAILED, 'error_message' => 'Orphan queue item']);

            return [false];
        }

        $item->update(['status' => HrNotificationQueueItem::PROCESSING]);
        if ($item->channel === 'email' && ! $isRetry) {
            $notification->recordAudit('Email Queued', $actor, null, ['channel' => 'email']);
        }

        try {
            $result = $this->channels->for($item->channel)->send($notification);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
            Log::channel('hr')->warning('Notification channel threw', ['channel' => $item->channel, 'id' => $item->id, 'error' => $e->getMessage()]);
        }

        if ($result['ok']) {
            $item->update(['status' => HrNotificationQueueItem::SENT, 'sent_at' => now(), 'error_message' => null]);
            $action = $notification->notification_type === 'reminder' ? 'Reminder Sent' : ($item->channel === 'email' ? 'Email Sent' : 'Notification Read');
            if ($item->channel === 'email') {
                $notification->recordAudit('Email Sent', $actor, null, ['channel' => 'email']);
            } elseif ($notification->notification_type === 'reminder') {
                $notification->recordAudit('Reminder Sent', $actor, null, ['channel' => $item->channel]);
            }
        } else {
            $item->increment('retry_count');
            $item->update(['status' => HrNotificationQueueItem::FAILED, 'error_message' => $result['error']]);
            if ($item->channel === 'email') {
                $notification->recordAudit('Email Failed', $actor, null, ['channel' => 'email', 'error' => $result['error']]);
            }
        }

        return [(bool) $result['ok']];
    }
}
