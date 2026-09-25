<?php

namespace App\Support\Transport;

/**
 * Put the driver's name into a response — D-144.
 *
 * `DriverProfile::name` is an accessor and is deliberately NOT appended, so it
 * resolves for a PHP reader and never appears in `toArray()`. That keeps P2's
 * rule — *"a copy is what goes stale"* — because a derived value that lands in
 * an array becomes a copy the moment that array is cached or queued.
 *
 * Which means exposing it is a decision, and this is where the decision is
 * made: at the API boundary, explicitly, per response. Not in the model, where
 * it would leak into every array anyone ever builds.
 *
 * Called on the way out, after the last write. `append()` only affects the
 * instance being serialised.
 */
trait WithDriverName
{
    /**
     * @template T
     *
     * @param  T  $carrier  a model with a loaded `driver` relation, or null
     * @return T
     */
    protected function withDriverName($carrier)
    {
        $driver = $carrier?->driver ?? null;

        // A profile whose `source` is missing cannot be resolved, and append()
        // would make the accessor run anyway and return "#id" — which is the
        // right answer, so it is appended either way. The name is never blank.
        if ($driver) {
            $driver->append('name');
        }

        return $carrier;
    }
}
