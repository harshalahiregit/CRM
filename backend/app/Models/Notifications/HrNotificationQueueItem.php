<?php

namespace App\Models\Notifications;

use Illuminate\Database\Eloquent\Model;

/**
 * Notification delivery queue item (Central Notification Engine). One row per
 * (notification × channel). The queue worker/command processes Pending items,
 * retries Failed ones, and records the outcome — this is the multi-channel
 * delivery ledger. Tenant-scoped.
 */
class HrNotificationQueueItem extends Model
{
    protected $table = 'hr_notification_queue';

    public const PENDING = 'Pending';
    public const PROCESSING = 'Processing';
    public const SENT = 'Sent';
    public const FAILED = 'Failed';

    protected $fillable = [
        'tenant_id', 'notification_id', 'channel', 'status', 'retry_count', 'sent_at', 'error_message',
    ];

    protected $casts = [
        'retry_count' => 'integer',
        'sent_at'     => 'datetime',
    ];

    public function notification()
    {
        return $this->belongsTo(HrNotification::class, 'notification_id');
    }
}
