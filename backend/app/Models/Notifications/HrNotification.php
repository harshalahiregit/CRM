<?php

namespace App\Models\Notifications;

use App\Models\Traits\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A notification (Central Notification Engine). One row = one in-app notification
 * for one recipient. Modules never write their own notification tables — they call
 * NotificationEngine::dispatch(), which creates these rows and enqueues channels.
 * Entities are referenced polymorphically (module + entity_type + entity_id), so
 * no business data is duplicated. Tenant-scoped.
 */
class HrNotification extends Model
{
    use Auditable;

    protected $table = 'hr_notifications';

    public const PRIORITIES = ['Info', 'Success', 'Warning', 'Critical'];
    public const TYPES = ['event', 'reminder', 'escalation'];

    protected $fillable = [
        'tenant_id', 'module', 'entity_type', 'entity_id', 'event', 'priority', 'notification_type',
        'title', 'message', 'sender_id', 'recipient_user_id', 'recipient_role',
        'action_url', 'action_label', 'is_read', 'read_at', 'expires_at',
    ];

    protected $casts = [
        'is_read'    => 'boolean',
        'read_at'    => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function queueItems()
    {
        return $this->hasMany(HrNotificationQueueItem::class, 'notification_id');
    }
}
