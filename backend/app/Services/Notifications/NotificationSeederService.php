<?php

namespace App\Services\Notifications;

use App\Models\Notifications\HrNotificationRule;
use App\Models\Notifications\HrNotificationTemplate;

/**
 * Seeds a tenant's notification templates + reminder rules from the module event
 * catalog (config/hr_notifications.php). Idempotent — only creates what's missing,
 * never overwrites tenant edits. Runs on demand and is safe to re-run when new
 * module events are registered.
 */
class NotificationSeederService
{
    public function __construct(private ModuleEventCatalog $catalog)
    {
    }

    public function seed(int $tenantId): array
    {
        $templates = 0; $rules = 0;
        foreach ($this->catalog->all() as $entry) {
            $module = $entry['module']; $event = $entry['event']; $def = $entry['def'];

            $exists = HrNotificationTemplate::where('tenant_id', $tenantId)->where('module', $module)->where('event', $event)->exists();
            if (! $exists) {
                HrNotificationTemplate::create([
                    'tenant_id' => $tenantId, 'module' => $module, 'event' => $event,
                    'subject' => $def['subject'] ?? $event, 'body' => $def['body'] ?? '',
                    'email_enabled' => true, 'in_app_enabled' => true, 'is_active' => true,
                ]);
                $templates++;
            }

            if (! empty($def['reminder'])) {
                $ruleExists = HrNotificationRule::where('tenant_id', $tenantId)->where('module', $module)->where('event', $event)->exists();
                if (! $ruleExists) {
                    $rem = $def['reminder'];
                    HrNotificationRule::create([
                        'tenant_id' => $tenantId, 'module' => $module, 'event' => $event,
                        'reminder_days' => $rem['days'] ?? [0],
                        'repeat_daily' => (bool) ($rem['repeat'] ?? false),
                        'escalation_days' => ! empty($rem['escalation']) ? $this->catalog->escalationLadder() : null,
                        'priority' => $def['priority'] ?? 'Warning',
                        'enabled' => true,
                    ]);
                    $rules++;
                }
            }
        }

        return ['templates_created' => $templates, 'rules_created' => $rules];
    }
}
