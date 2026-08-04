<?php

namespace App\Support\Hr;

use Illuminate\Database\Eloquent\Builder;

/**
 * Review comment #3 — "Filter option in every listing. Ex. HIRING MANAGER".
 *
 * ONE definition of "belongs to this hiring manager", applied by six listings.
 *
 * Only `hr_manpower_requests` stores `hiring_manager_id`; everything downstream
 * inherits it through the chain the recruitment workflow already models:
 *
 *     Manpower Request ← Job Posting ← Candidate ← Interview / Offer / Onboarding
 *
 * Each listing therefore differs only in how far it sits from the requisition,
 * which is exactly what `$path` expresses. Writing that `whereHas` six times is
 * how the six drift apart — one gets `orWhereNull`, another forgets the tenant,
 * and "filtered by hiring manager" quietly means six different things.
 */
class HiringManagerFilter
{
    /**
     * Narrow a query to one hiring manager.
     *
     * @param  Builder      $query
     * @param  mixed        $hiringManagerId  raw request input; 'All'/''/null are no-ops
     * @param  string|null  $path             relation path to the JOB POSTING, or null
     *                                        when the model IS the manpower request
     */
    public static function apply(Builder $query, $hiringManagerId, ?string $path): Builder
    {
        if (! self::wanted($hiringManagerId)) {
            return $query;
        }

        $id = (int) $hiringManagerId;

        // The manpower request itself — no relation to walk.
        if ($path === null) {
            return $query->where('hiring_manager_id', $id);
        }

        // `manpowerRequest` is appended here rather than by each caller, so a
        // listing can never point the filter at the wrong end of the chain.
        return $query->whereHas(
            $path === '' ? 'manpowerRequest' : $path.'.manpowerRequest',
            fn ($mr) => $mr->where('hiring_manager_id', $id)
        );
    }

    /**
     * Is a filter actually being asked for?
     *
     * 'All' is what every filter bar sends for "no filter", and an absent or
     * empty value means the same. Treating those as a real id would return an
     * empty list on a screen the user never filtered.
     */
    public static function wanted($value): bool
    {
        return $value !== null && $value !== '' && $value !== 'All' && (int) $value > 0;
    }
}
