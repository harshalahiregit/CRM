<?php

namespace App\Services\Notifications;

/**
 * Module event registry (Central Notification Engine). Reads config/hr_notifications.php
 * — the single place modules register events. Provides lookups for the engine,
 * template/rule seeding, escalation ladder and the reminder-eligible events. A new
 * module registers by adding a config block; nothing here changes.
 */
class ModuleEventCatalog
{
    public function modules(): array
    {
        return array_keys(config('hr_notifications.modules', []));
    }

    /** Flat list of [module, event, def] across all modules. */
    public function all(): array
    {
        $out = [];
        foreach (config('hr_notifications.modules', []) as $module => $events) {
            foreach ($events as $event => $def) {
                $out[] = ['module' => $module, 'event' => $event, 'def' => $def];
            }
        }

        return $out;
    }

    public function def(string $module, string $event): ?array
    {
        return config("hr_notifications.modules.{$module}.{$event}");
    }

    public function exists(string $module, string $event): bool
    {
        return (bool) $this->def($module, $event);
    }

    /** Events that carry a reminder spec (time-based). */
    public function reminderEvents(): array
    {
        return array_values(array_filter($this->all(), fn ($e) => ! empty($e['def']['reminder'])));
    }

    public function escalationLadder(): array
    {
        return config('hr_notifications.escalation_ladder', []);
    }

    public function liveChannels(): array
    {
        return config('hr_notifications.live_channels', ['in_app', 'email']);
    }

    public function maxRetries(): int
    {
        return (int) config('hr_notifications.max_retries', 3);
    }
}
