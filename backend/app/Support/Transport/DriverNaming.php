<?php

namespace App\Support\Transport;

use App\Domains\Fleet\Contracts\DriverDirectory;
use App\Domains\Fleet\Models\DriverProfile;

/**
 * The one place a driver's name is worked out — D-135.
 *
 * `driver_profiles` has no `name` column, by design: a driver is a reference
 * into a directory (`source` + `source_id`) plus a licence. After the repoint
 * `$trip->driver` became a `DriverProfile`, so every `->name` in the codebase
 * silently started returning null.
 *
 * ── WHY A BLANK IS WORSE THAN AN ID ──────────────────────────────────────
 * The allocation panel read:
 *
 *     assignment?.driver ? assignment.driver.name : `#${assignment.driver_id}`
 *
 * The repoint made `assignment.driver` a real object, so the first branch won
 * and the `#2` fallback never ran. A driver that WAS assigned rendered as
 * empty — indistinguishable from nothing being assigned, which is exactly the
 * report that came in. This never returns null: worst case it returns `#2`,
 * which is worse than a name and far better than a blank.
 *
 * ── WHY IT IS ONE RESOLVER, NOT A PATCH PER CALL SITE ────────────────────
 * D-135 was reported fixed for two screens and a grep found six readers, four
 * still broken. Patching each is how it comes back a third time. This is the
 * one place, reached through `DriverProfile::getNameAttribute()` — an accessor,
 * so it costs a lookup only when something actually asks for a name, and so
 * `toArray()` never carries a derived value that would become a copy the
 * moment it was cached (D-144).
 *
 * The lookup is memoised per request: `DriverDirectory::people()` reads TPV
 * workforce, purchase workforce, vendor and customer contacts, plus the local
 * register, and a hook on every read would otherwise do that per row.
 */
class DriverNaming
{
    /** company => (ref => name), built once per company per request. */
    private array $names = [];

    /** Never null. A name if the directory has one, the id if it does not. */
    public function nameFor(DriverProfile $profile): string
    {
        $ref = $profile->source.':'.$profile->source_id;

        // Keyed off the PROFILE's company, not the signed-in user's: this is
        // called from console commands and jobs where there is no user, and
        // guessing the tenant from auth is how a lookup silently returns
        // nothing in exactly those contexts.
        return $this->names((int) $profile->company_id)[$ref] ?? '#'.$profile->id;
    }

    /** @return array<string,string> */
    private function names(int $companyId): array
    {
        if (isset($this->names[$companyId])) {
            return $this->names[$companyId];
        }

        $map = [];

        foreach (app(DriverDirectory::class)->people($companyId) as $person) {
            if (! empty($person['ref']) && ! empty($person['name'])) {
                $map[$person['ref']] = $person['name'];
            }
        }

        return $this->names[$companyId] = $map;
    }
}
