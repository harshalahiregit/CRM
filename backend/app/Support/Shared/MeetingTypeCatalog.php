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
    /** @var array<int, array{types: array<string,string>, templates: array<string,array>}> */
    private array $cache = [];

    /** Effective key → label map for the tenant (built-ins + active DB rows). */
    public function types(int $tenantId): array
    {
        return $this->resolve($tenantId)['types'];
    }

    /** Effective key → agenda-template map for the tenant. */
    public function templates(int $tenantId): array
    {
        return $this->resolve($tenantId)['templates'];
    }

    /** Valid type keys for the tenant — for the meeting_type validation rule. */
    public function keys(int $tenantId): array
    {
        return array_keys($this->types($tenantId));
    }

    /** Label for one key, falling back to a humanised key. */
    public function label(int $tenantId, ?string $key): string
    {
        $key = $key ?: config('meetings.default_type', 'kickoff');

        return $this->types($tenantId)[$key]
            ?? ucfirst(str_replace('_', ' ', (string) $key));
    }

    /** Drop a tenant's memoised merge (call after a settings write). */
    public function forget(int $tenantId): void
    {
        unset($this->cache[$tenantId]);
    }

    /**
     * @return array{types: array<string,string>, templates: array<string,array>}
     */
    private function resolve(int $tenantId): array
    {
        if (isset($this->cache[$tenantId])) {
            return $this->cache[$tenantId];
        }

        $types = config('meetings.types', []);
        $templates = config('meetings.templates', []);

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

        return $this->cache[$tenantId] = ['types' => $types, 'templates' => $templates];
    }
}
