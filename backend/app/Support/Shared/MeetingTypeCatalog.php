<?php

namespace App\Support\Shared;

use App\Models\Shared\MeetingType;

/**
 * The effective meeting-type catalogue for a tenant: the config/meetings.php
 * baseline with the tenant's DB rows layered on top (Meeting.docx — admin
 * Types/Templates settings).
 *
 * A DB row whose key matches a built-in overrides that type's label and template
 * for the tenant; a new key adds a type. An inactive row hides a built-in type
 * from the pickers without deleting anything. An empty table therefore reproduces
 * exactly the config-only behaviour that shipped before this existed.
 *
 * Bound as a singleton so the per-tenant merge is computed once per request — the
 * KickoffMeeting label accessor reads it on every row, so this must not re-query.
 */
class MeetingTypeCatalog
{
    /** @var array<string, array{types: array<string,string>, templates: array<string,array>}> keyed "<configBase>:<tenantId>" */
    private array $cache = [];

    /*
     * $base names the CONFIG BASELINE to merge the tenant's rows over —
     * 'meetings' for the shared engine, 'purchase_meetings' for Purchase. The
     * meeting_types table is tenant-scoped and carries nothing module-specific,
     * so both engines share those rows; only the built-in list underneath
     * differs. Defaulting to 'meetings' leaves every existing caller unchanged.
     */

    /** Effective key → label map for the tenant (built-ins + active DB rows). */
    public function types(int $tenantId, string $base = 'meetings'): array
    {
        return $this->resolve($tenantId, $base)['types'];
    }

    /** Effective key → agenda-template map for the tenant. */
    public function templates(int $tenantId, string $base = 'meetings'): array
    {
        return $this->resolve($tenantId, $base)['templates'];
    }

    /** Valid type keys for the tenant — for the meeting_type validation rule. */
    public function keys(int $tenantId, string $base = 'meetings'): array
    {
        return array_keys($this->types($tenantId, $base));
    }

    /** Label for one key, falling back to a humanised key. */
    public function label(int $tenantId, ?string $key, string $base = 'meetings'): string
    {
        $key = $key ?: config($base.'.default_type', 'kickoff');

        return $this->types($tenantId, $base)[$key]
            ?? ucfirst(str_replace('_', ' ', (string) $key));
    }

    /** Drop a tenant's memoised merge (call after a settings write). */
    public function forget(int $tenantId): void
    {
        // Every baseline for this tenant — a settings write changes the rows
        // both engines read, so clearing only one would leave the other stale.
        foreach (array_keys($this->cache) as $k) {
            if (str_ends_with((string) $k, ':'.$tenantId)) {
                unset($this->cache[$k]);
            }
        }
    }

    /**
     * @return array{types: array<string,string>, templates: array<string,array>}
     */
    private function resolve(int $tenantId, string $base = 'meetings'): array
    {
        $cacheKey = $base.':'.$tenantId;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $types = config($base.'.types', []);
        $templates = config($base.'.templates', []);

        $rows = MeetingType::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if (! $row->is_active) {
                // Hide a built-in (or a disabled custom) type from the pickers.
                unset($types[$row->key], $templates[$row->key]);

                continue;
            }
            $types[$row->key] = $row->label;
            if (is_array($row->templates)) {
                $templates[$row->key] = $row->templates;
            }
        }

        return $this->cache[$cacheKey] = ['types' => $types, 'templates' => $templates];
    }
}
