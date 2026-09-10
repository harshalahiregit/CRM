<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireSettingsProvider;
use Sire\Models\Setting;

/**
 * The shipped settings provider: SIRE's own per-tenant table.
 *
 * Resolution order, first hit wins:
 *
 *   1. the tenant's row in sire_settings
 *   2. config('sire.defaults.<key>')
 *   3. the $default the caller passed
 *
 * SIRE owning this table is what lets it install into a host with no settings
 * system at all. A host WITH one implements SireSettingsProvider and this table
 * stays empty; exactly one is ever bound, so there is never a second
 * configuration system to keep in sync.
 *
 * Reads never throw. A settings backend that is missing or broken means SIRE
 * runs on its documented defaults — SLA off, AI off, gates on — all of which are
 * the safe choice. Configuration that cannot be read should quieten SIRE, not
 * break it.
 *
 * Cached per request: rendering one dashboard reads the same handful of keys
 * many times, and this is a hot path.
 */
class SireLocalSettingsProvider implements SireSettingsProvider
{
    /** @var array<string, mixed> "tenant:key" => decoded value (null = stored miss) */
    private array $cache = [];

    public function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        $cacheKey = $tenantId.':'.$key;

        if (! array_key_exists($cacheKey, $this->cache)) {
            try {
                $this->cache[$cacheKey] = Setting::query()
                    ->forTenant($tenantId)
                    ->where('key', $key)
                    ->value('value');
            } catch (\Throwable $e) {
                report($e);
                $this->cache[$cacheKey] = null;
            }
        }

        $stored = $this->cache[$cacheKey];

        return $stored === null ? $this->fallback($key, $default) : $this->decode($stored);
    }

    public function set(int $tenantId, string $key, mixed $value): void
    {
        // forTenant() on the lookup as well as tenant_id in the match: the scope
        // is what the tenant lint reads, and it keeps this consistent with every
        // other query in SIRE rather than being the one special case.
        Setting::query()->forTenant($tenantId)->updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value)],
        );

        unset($this->cache[$tenantId.':'.$key]);
    }

    public function all(int $tenantId, string $prefix = 'sire.'): array
    {
        try {
            return Setting::query()
                ->forTenant($tenantId)
                ->where('key', 'like', $prefix.'%')
                ->pluck('value', 'key')
                ->map(fn ($v) => $this->decode($v))
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function forget(int $tenantId, string $key): void
    {
        Setting::query()->forTenant($tenantId)->where('key', $key)->delete();

        unset($this->cache[$tenantId.':'.$key]);
    }

    private function fallback(string $key, mixed $default): mixed
    {
        // config() uses dots for nesting, and SIRE keys contain dots. Wrapping the
        // key keeps 'sire.sla.policies' a single key rather than a path into
        // config('sire')['sla']['policies'].
        $configured = config('sire.defaults')[$key] ?? null;

        return $configured ?? $default;
    }

    /** Scalars come back untouched; JSON structures are decoded. */
    private function decode(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
