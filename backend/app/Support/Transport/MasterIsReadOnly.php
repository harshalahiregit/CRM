<?php

namespace App\Support\Transport;

use App\Exceptions\BusinessException;

/**
 * The legacy vehicle and driver masters are read-only — D-143.
 *
 * ── WHY A CONTROLLER REFUSAL AND NOT AN UNREGISTERED ROUTE ───────────────
 * Unrouting was the first attempt, and it contradicted itself: a route that
 * does not exist returns a bare 404 from the router and cannot name anything.
 * Three reasons the sentence matters more than the absence:
 *
 *   · A guard that refuses correctly with an unreadable message is half a
 *     guard. That is the standard set on D-136, where a broken lock was
 *     indistinguishable from no lock because the loser saw a deadlock instead
 *     of a sentence.
 *   · The screens still exist, because they still show history. Somebody will
 *     have a stale form open. A 404 tells them the product is broken; this
 *     tells them where to go.
 *   · "No role bypasses this" is a real assertion against a controller
 *     refusal and a vacuous one against a route that is not there.
 *
 * 409 rather than 403: the caller's permissions are not the problem, and 403
 * would send an operations lead to an administrator who cannot help.
 */
trait MasterIsReadOnly
{
    /**
     * @param  'vehicle'|'driver'  $noun
     *
     * @throws BusinessException always
     */
    protected function refuseMasterWrite(string $noun): never
    {
        $where = $noun === 'vehicle'
            ? 'Add or edit a vehicle in Fleet — Transport OS › Fleet › Add vehicle.'
            : 'Add or edit a driver in Fleet — Transport OS › Drivers.';

        throw new BusinessException(
            ucfirst($noun).'s are no longer created or edited here. This screen now shows history '
            .'only, and the records it lists are still complete. '.$where,
            409,
        );
    }
}
