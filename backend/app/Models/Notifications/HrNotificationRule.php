<?php

namespace App\Models\Notifications;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reminder rule (Central Notification Engine). Configuration read by the
 * ReminderEngine: which day-offsets to remind on, whether to repeat daily while
 * overdue, and a configuration-driven escalation ladder (day → role). No hardcoded
 * schedules or escalations. Tenant-scoped, audited.
 */
class HrNotificationRule extends Model
{
    use Auditable;

    protected $table = 'hr_notification_rules';

    protected $fillable = [
        'tenant_id', 'module', 'event', 'reminder_days', 'repeat_daily',
        'escalation_days', 'priority', 'enabled', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'reminder_days'   => 'array',
        'escalation_days' => 'array',
        'repeat_daily'    => 'boolean',
        'enabled'         => 'boolean',
    ];
}
