<?php

namespace App\Models\Traits;

use App\Casts\BusinessDateTime;
use App\Support\Shared\BusinessTime;
use DateTimeInterface;

/**
 * Keeps a {@see BusinessDateTime} column on the tenant's clock even before the
 * model has been refreshed.
 *
 * Eloquent caches the value handed to a class-cast attribute **verbatim when it
 * is an object** — `setClassCastableAttribute()` stores `$value` itself rather
 * than what the cast would read back. So assigning a UTC Carbon and then
 * reading the attribute returns that UTC Carbon, and only a `refresh()` reveals
 * the tenant's clock.
 *
 * That gap is not theoretical. `PurchaseKickoffService::update()` snapshots the
 * schedule, applies the edit, refreshes, and compares the two to decide whether
 * the meeting moved. Across the refresh the *same* instant rendered in two
 * different zones, so a plain rename looked like a reschedule: the roster was
 * re-notified and the fired-reminder ledger was wiped. An invitation built from
 * an unrefreshed model would have quoted the wrong hour for the same reason.
 *
 * Normalising on the way in closes it: whatever zone a caller hands over, the
 * attribute reads back on the tenant's clock immediately and after a reload.
 */
trait NormalisesBusinessTimes
{
    public function setAttribute($key, $value)
    {
        if ($value instanceof DateTimeInterface
            && ($this->getCasts()[$key] ?? null) === BusinessDateTime::class) {
            $value = BusinessTime::parse($value, $this->tenant_id ?? null);
        }

        return parent::setAttribute($key, $value);
    }
}
