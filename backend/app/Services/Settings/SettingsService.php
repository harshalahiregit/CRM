<?php

namespace App\Services\Settings;

use App\Models\TenantSetting;
use App\Support\Settings\SettingRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Reusable, tenant-aware settings access for the generic key/value store.
 *
 *  - get/getGroup merge registry DEFAULTS with stored values, cast to type.
 *  - set/setGroup persist only registry-known keys, encrypting flagged keys.
 *  - a per-tenant cache holds the raw rows; every write flushes it.
 *
 * Unknown keys are ignored on write and fall back to their default on read, so a
 * new key added to the registry is immediately readable (default) before it's ever
 * saved. Fully tenant-scoped — one tenant can never read another's rows.
 */
class SettingsService
{
    /** Cache TTL for the raw settings map (seconds). */
    private const TTL = 3600;

    public function get(int $tenantId, string $group, string $key, $default = null)
    {
        $raw = $this->raw($tenantId);
        if (isset($raw[$group][$key])) {
            return $this->decode($group, $key, $raw[$group][$key]);
        }
        if (SettingRegistry::has($group, $key)) {
            return SettingRegistry::meta($group, $key)['default'] ?? $default;
        }

        return $default;
    }

    /** Full group: registry defaults overlaid with stored values, all cast. */
    public function getGroup(int $tenantId, string $group): array
    {
        $values = SettingRegistry::defaults($group);
        $raw = $this->raw($tenantId);
        foreach ($raw[$group] ?? [] as $key => $stored) {
            if (SettingRegistry::has($group, $key)) {
                $values[$key] = $this->decode($group, $key, $stored);
            }
        }

        return $values;
    }

    public function set(int $tenantId, string $group, string $key, $value): void
    {
        if (! SettingRegistry::has($group, $key)) {
            return;
        }
        $this->persist($tenantId, $group, $key, $value);
        $this->flush($tenantId);
    }

    /** Persist a group's values (only registry keys) and return the fresh group. */
    public function setGroup(int $tenantId, string $group, array $values): array
    {
        foreach ($values as $key => $value) {
            if (SettingRegistry::has($group, $key)) {
                $this->persist($tenantId, $group, (string) $key, $value);
            }
        }
        $this->flush($tenantId);

        return $this->getGroup($tenantId, $group);
    }

    public function flush(int $tenantId): void
    {
        Cache::forget($this->cacheKey($tenantId));
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    private function cacheKey(int $tenantId): string
    {
        return "tenant_settings:{$tenantId}";
    }

    /** Raw stored map: [group][key] => ['value'=>?string, 'enc'=>bool]. Cached per tenant. */
    private function raw(int $tenantId): array
    {
        return Cache::remember($this->cacheKey($tenantId), self::TTL, function () use ($tenantId) {
            $map = [];
            foreach (TenantSetting::forTenant($tenantId)->get() as $row) {
                $map[$row->group][$row->key] = ['value' => $row->value, 'enc' => (bool) $row->is_encrypted];
            }

            return $map;
        });
    }

    private function persist(int $tenantId, string $group, string $key, $value): void
    {
        $encrypted = SettingRegistry::isEncrypted($group, $key);
        $store = $value === null
            ? null
            : ($encrypted ? Crypt::encryptString((string) $value) : SettingRegistry::forStore($group, $key, $value));

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantId, 'group' => $group, 'key' => $key],
            ['value' => $store, 'is_encrypted' => $encrypted, 'autoload' => true],
        );
    }

    private function decode(string $group, string $key, array $stored)
    {
        $value = $stored['value'];
        if ($value !== null && $stored['enc']) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Throwable $e) {
                $value = null;
            }
        }

        return SettingRegistry::cast($group, $key, $value);
    }
}
