<?php

namespace App\Services\Notifications\Reminders;

use App\Models\Notifications\HrNotificationRule;
use App\Services\Notifications\EscalationResolver;
use App\Services\Notifications\NotificationEngine;
use Illuminate\Support\Carbon;

/**
 * Reminder Engine (Central Notification Engine). Reads enabled reminder rules,
 * asks each event's ReminderSource for due entities, and generates reminders at the
 * configured day-offsets (or daily while overdue). Escalates configuration-driven
 * when set. Idempotent — one reminder per (event, entity, recipient) per day — so
 * running it repeatedly (daily or hourly) never duplicates. Tenant-scoped, audited.
 */
class ReminderEngine
{
    public function __construct(
        private ReminderSourceRegistry $registry,
        private NotificationEngine $engine,
        private EscalationResolver $escalation,
    ) {
    }

    /** Run for one tenant (or all tenants that have rules). Returns generated count. */
    public function run(?int $tenantId = null): array
    {
        $tenants = $tenantId !== null
            ? [$tenantId]
            : HrNotificationRule::where('enabled', true)->distinct()->pluck('tenant_id')->filter()->all();

        $generated = 0; $escalated = 0;
        foreach ($tenants as $tid) {
            $rules = HrNotificationRule::where('tenant_id', $tid)->where('enabled', true)->get();
            foreach ($rules as $rule) {
                $source = $this->registry->for($rule->module, $rule->event);
                if (! $source) {
                    continue; // event-triggered only (no scheduled source)
                }
                [$g, $e] = $this->runRule($tid, $rule, $source);
                $generated += $g; $escalated += $e;
            }
        }

        return ['generated' => $generated, 'escalated' => $escalated];
    }

    private function runRule(int $tenantId, HrNotificationRule $rule, ReminderSource $source): array
    {
        $today = Carbon::today();
        $reminderDays = array_map('intval', (array) ($rule->reminder_days ?: []));
        $generated = 0; $escalated = 0;

        foreach ($source->due($tenantId) as $entity) {
            $dueDate = ! empty($entity['due_date']) ? Carbon::parse($entity['due_date'])->startOfDay() : null;
            $offset = $dueDate ? $today->diffInDays($dueDate, false) : 0; // +ve = days until due, -ve = overdue

            $isReminderDay = in_array($offset, $reminderDays, true);
            $isOverdueRepeat = $rule->repeat_daily && $offset <= 0;
            if (! $isReminderDay && ! $isOverdueRepeat) {
                continue;
            }

            $daysOverdue = max(0, -$offset);
            $escalationRole = null;
            if ($daysOverdue > 0 && ! empty($rule->escalation_days)) {
                $escalationRole = $this->escalation->roleForOverdue($daysOverdue, $rule->escalation_days);
            }

            $context = array_merge($entity['context'] ?? [], [
                'remaining_days' => max(0, $offset),
                'date' => $dueDate ? $dueDate->toDateString() : $today->toDateString(),
            ]);

            $roles = $escalationRole ? [$escalationRole] : ($entity['recipient_roles'] ?? ['hr']);

            $created = $this->engine->dispatch($tenantId, $rule->module, $rule->event, [
                'entity_type' => $entity['entity_type'] ?? null,
                'entity_id' => $entity['entity_id'] ?? null,
                'recipient_user_ids' => $entity['recipient_user_ids'] ?? [],
                'recipient_roles' => $roles,
                'context' => $context,
                'priority' => $rule->priority,
                'notification_type' => $escalationRole ? 'escalation' : 'reminder',
                'action_url' => $entity['action_url'] ?? null,
                'action_label' => $entity['action_label'] ?? null,
                'dedupe' => true,
            ]);

            foreach ($created as $n) {
                $n->recordAudit($escalationRole ? 'Escalation Triggered' : 'Reminder Generated', null, null, [
                    'offset' => $offset, 'overdue' => $daysOverdue, 'role' => $escalationRole,
                ]);
                $generated++;
                if ($escalationRole) {
                    $escalated++;
                }
            }
        }

        return [$generated, $escalated];
    }
}
