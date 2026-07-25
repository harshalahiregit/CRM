<?php

namespace App\Services\Task;

use App\Exceptions\BusinessException;
use App\Models\Task\TaskSetting;

/**
 * Which task events reach people, and by which channel.
 *
 * Every key has a declared default here, so reading a setting a workspace has
 * never touched returns a sane value instead of null and the UI never has to
 * guess what "unset" means. Unknown keys are refused rather than quietly stored,
 * which is what stops a typo'd switch looking saved but doing nothing.
 *
 * `email_enabled` is the master outbound switch: off keeps every in-app bell
 * working while nothing leaves the building. That's the right default shape for
 * a workspace that lives inside the app.
 */
class TaskConfigService
{
    /** key => default. This list IS the contract for what's configurable. */
    public const DEFAULTS = [
        'notify_assigned'   => true,   // you've been given a task or subtask
        'notify_activity'   => true,   // status changed, commented, mentioned
        'notify_completed'  => true,   // a subtask under you was finished
        'notify_due'        => true,   // due soon / overdue
        'email_enabled'     => true,   // send the above by email as well as in-app
        'alert_email_extra' => null,   // optional shared inbox copied on everything
    ];

    public function all(int $tenantId): array
    {
        $saved = TaskSetting::forTenant($tenantId)->pluck('value', 'key')->all();

        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $out[$key] = array_key_exists($key, $saved) ? $this->unwrap($saved[$key]) : $default;
        }

        return $out;
    }

    public function get(int $tenantId, string $key)
    {
        return $this->all($tenantId)[$key] ?? null;
    }

    /** True unless the workspace has explicitly turned this category off. */
    public function on(int $tenantId, string $key): bool
    {
        try {
            return (bool) $this->get($tenantId, $key);
        } catch (\Throwable $e) {
            // A broken settings read must not silence every notification in the
            // workspace — failing loud is better than failing quiet here.
            return true;
        }
    }

    public function save(int $tenantId, array $values): array
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                throw new BusinessException("Unknown task setting: {$key}", 422);
            }

            // The one free-text setting that gets posted to the outside world —
            // a typo means alerts silently bounce, so refuse it up front.
            if ($key === 'alert_email_extra') {
                $value = trim((string) $value) ?: null;
                if ($value !== null && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new BusinessException('That alert email address is not valid.', 422);
                }
            }

            TaskSetting::updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => ['v' => $value]],
            );
        }

        return $this->all($tenantId);
    }

    private function unwrap($stored)
    {
        return is_array($stored) && array_key_exists('v', $stored) ? $stored['v'] : $stored;
    }
}
