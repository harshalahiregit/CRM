<?php

namespace App\Services\Notifications;

use App\Models\Notifications\HrNotification;
use App\Models\Notifications\HrNotificationQueueItem;
use App\Models\Notifications\HrNotificationTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Notification Engine (Central Notification Engine) — the ONE entry point every
 * module uses. dispatch() resolves the tenant template (falling back to the config
 * catalog), renders {{placeholders}}, creates in-app notification rows for each
 * recipient (users and/or roles) and enqueues the enabled delivery channels. No
 * module contains its own notification logic. Idempotent when `dedupe` is set
 * (used by the reminder engine to avoid duplicate reminders). Tenant-scoped, audited.
 */
class NotificationEngine
{
    public function __construct(private TemplateRenderer $renderer)
    {
    }

    /**
     * @param  array{entity_type?:string, entity_id?:int, recipient_user_ids?:int[],
     *   recipient_roles?:string[], context?:array, priority?:string,
     *   notification_type?:string, action_url?:string, action_label?:string,
     *   sender_id?:int, expires_at?:string, dedupe?:bool}  $opts
     * @return HrNotification[]
     */
    public function dispatch(int $tenantId, string $module, string $event, array $opts = [], ?User $actor = null): array
    {
        $def = config("hr_notifications.modules.{$module}.{$event}");
        $template = HrNotificationTemplate::where('tenant_id', $tenantId)
            ->where('module', $module)->where('event', $event)->where('is_active', true)->first();

        // Nothing configured for this event → skip silently (never breaks the caller).
        if (! $template && ! $def) {
            return [];
        }

        $context = array_merge(['module' => $module, 'event' => $event], $opts['context'] ?? []);
        $subjectTpl = $template->subject ?? ($def['subject'] ?? $event);
        $bodyTpl = $template->body ?? ($def['body'] ?? '');
        $rendered = $this->renderer->renderTemplate($subjectTpl, $bodyTpl, $context);

        $channels = $template ? $template->enabledChannels() : ['in_app', 'email'];
        $priority = $opts['priority'] ?? ($def['priority'] ?? 'Info');

        $recipients = [];
        foreach ($opts['recipient_user_ids'] ?? [] as $uid) {
            $recipients[] = ['user_id' => (int) $uid, 'role' => null];
        }
        foreach ($opts['recipient_roles'] ?? [] as $role) {
            $recipients[] = ['user_id' => null, 'role' => $role];
        }
        if (! $recipients) {
            return []; // no one to notify
        }

        $created = [];
        foreach ($recipients as $r) {
            if (! empty($opts['dedupe']) && $this->alreadySentToday($tenantId, $module, $event, $opts['entity_id'] ?? null, $r)) {
                continue;
            }

            $notification = HrNotification::create([
                'tenant_id' => $tenantId,
                'module' => $module,
                'entity_type' => $opts['entity_type'] ?? null,
                'entity_id' => $opts['entity_id'] ?? null,
                'event' => $event,
                'priority' => in_array($priority, HrNotification::PRIORITIES, true) ? $priority : 'Info',
                'notification_type' => $opts['notification_type'] ?? 'event',
                'title' => $rendered['subject'] ?: $event,
                'message' => $rendered['body'],
                'sender_id' => $opts['sender_id'] ?? $actor?->id,
                'recipient_user_id' => $r['user_id'],
                'recipient_role' => $r['role'],
                'action_url' => $opts['action_url'] ?? null,
                'action_label' => $opts['action_label'] ?? null,
                'expires_at' => ! empty($opts['expires_at']) ? Carbon::parse($opts['expires_at']) : null,
            ]);
            $notification->recordAudit('Notification Created', $actor, null, ['module' => $module, 'event' => $event, 'type' => $notification->notification_type]);

            $this->enqueue($notification, $channels, $r['user_id'] !== null);
            $created[] = $notification;
        }

        return $created;
    }

    /** Create queue items for the notification's channels (email only when a user recipient exists). */
    private function enqueue(HrNotification $notification, array $channels, bool $hasUser): void
    {
        $rows = [];
        foreach ($channels as $channel) {
            if ($channel === 'email' && ! $hasUser) {
                continue; // role-targeted notifications deliver in-app; no single email address
            }
            $rows[] = [
                'tenant_id' => $notification->tenant_id,
                'notification_id' => $notification->id,
                'channel' => $channel,
                'status' => $channel === 'in_app' ? HrNotificationQueueItem::SENT : HrNotificationQueueItem::PENDING,
                'sent_at' => $channel === 'in_app' ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        if ($rows) {
            HrNotificationQueueItem::insert($rows); // bulk insert
        }
    }

    /** Idempotency for reminders: one notification per (module,event,entity,recipient) per day. */
    private function alreadySentToday(int $tenantId, string $module, string $event, ?int $entityId, array $recipient): bool
    {
        return HrNotification::where('tenant_id', $tenantId)
            ->where('module', $module)->where('event', $event)
            ->when($entityId !== null, fn ($q) => $q->where('entity_id', $entityId))
            ->when($recipient['user_id'] !== null, fn ($q) => $q->where('recipient_user_id', $recipient['user_id']))
            ->when($recipient['role'] !== null, fn ($q) => $q->where('recipient_role', $recipient['role']))
            ->whereDate('created_at', now()->toDateString())
            ->exists();
    }
}
