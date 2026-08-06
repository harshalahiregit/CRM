<?php

namespace App\Support\Hr;

use App\Models\Hr\HrDesignation;

/**
 * Resolve a designation filter to the NAME the records actually store.
 *
 * There is no designation_id foreign key on candidates, job postings or manpower
 * requests — designation lives as a plain string (`title`, `position_title`), and
 * candidates carry none at all (they reach one through their job posting). So a
 * `designation_id` from the UI is translated to its master name here, once, and
 * every caller filters on that name.
 *
 * Both inputs are accepted:
 *   designation_id — the master row id (what the dropdown sends)
 *   designation    — a literal name, so legacy/off-master values stay filterable
 *
 * Returns null when neither is supplied or the id does not resolve, which callers
 * treat as "no filter" — an unknown id must not silently return an empty list.
 */
final class DesignationFilter
{
    public static function resolve(array $filters, int $tenantId): ?string
    {
        $name = trim((string) ($filters['designation'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $id = $filters['designation_id'] ?? null;
        if (! $id) {
            return null;
        }

        return HrDesignation::where('tenant_id', $tenantId)
            ->whereKey($id)
            ->value('name');
    }
}
