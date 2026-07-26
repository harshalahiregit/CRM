<?php

namespace App\Services\Notifications\Reminders;

/**
 * A reminder source (Central Notification Engine). Each module contributes one
 * source per time-based event that returns the entities currently "due" (read-only
 * over that module's existing tables — no business logic changed, no data
 * duplicated). The ReminderEngine matches a source to a rule and generates
 * deduped, escalated reminders. A future module plugs in by adding a source class
 * to ReminderSourceRegistry — the engine never changes.
 */
interface ReminderSource
{
    public function module(): string;

    public function event(): string;

    /**
     * Entities due for a reminder. Each item:
     *   ['entity_type'=>string, 'entity_id'=>int, 'due_date'=>?string,
     *    'recipient_user_ids'=>int[], 'recipient_roles'=>string[], 'context'=>array]
     *
     * @return iterable<array>
     */
    public function due(int $tenantId): iterable;
}
