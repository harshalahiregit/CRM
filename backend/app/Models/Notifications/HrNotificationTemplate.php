<?php

namespace App\Models\Notifications;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Notification template (Central Notification Engine). Per-tenant, per module+event
 * subject/body with `{{placeholder}}` tokens and per-channel toggles. Rendered by
 * TemplateRenderer at dispatch time. Tenant-scoped, audited.
 */
class HrNotificationTemplate extends Model
{
    use Auditable;

    protected $table = 'hr_notification_templates';

    public const CHANNEL_FLAGS = ['email_enabled', 'in_app_enabled', 'sms_enabled', 'whatsapp_enabled', 'teams_enabled', 'slack_enabled'];

    protected $fillable = [
        'tenant_id', 'module', 'event', 'subject', 'body',
        'email_enabled', 'in_app_enabled', 'sms_enabled', 'whatsapp_enabled', 'teams_enabled', 'slack_enabled',
        'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'email_enabled'    => 'boolean',
        'in_app_enabled'   => 'boolean',
        'sms_enabled'      => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'teams_enabled'    => 'boolean',
        'slack_enabled'    => 'boolean',
        'is_active'        => 'boolean',
    ];

    /** Channels enabled on this template, as engine channel keys. */
    public function enabledChannels(): array
    {
        $map = [
            'email_enabled' => 'email', 'in_app_enabled' => 'in_app', 'sms_enabled' => 'sms',
            'whatsapp_enabled' => 'whatsapp', 'teams_enabled' => 'teams', 'slack_enabled' => 'slack',
        ];
        $out = [];
        foreach ($map as $flag => $channel) {
            if ($this->{$flag}) {
                $out[] = $channel;
            }
        }

        return $out;
    }
}
