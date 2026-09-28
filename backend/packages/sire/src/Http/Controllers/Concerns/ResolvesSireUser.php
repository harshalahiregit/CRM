<?php

namespace Sire\Http\Controllers\Concerns;

use Sire\Contracts\SireUserProvider;
use Sire\Dto\SireUserIdentity;

/**
 * SIRE — turn the authenticated request into a SireUserIdentity.
 *
 * The single point where a host's user becomes something SIRE will handle. Every
 * SIRE controller calls this instead of `$this->sireUser()`, which is why no SIRE
 * service ever receives a host model.
 *
 * WHY IT ABORTS 401 RATHER THAN RETURNING NULL
 *
 * Every SIRE route already sits behind `auth:sanctum`, so a null user here means
 * the route group was misconfigured — not that an anonymous visitor arrived. A
 * nullable return would push that check into 66 call sites, and the one someone
 * forgot would be an action recorded with no actor.
 *
 * The 401 is deliberate too: it is the only place in SIRE that emits one.
 * Permission failures are 403 and cross-tenant access is 404, because an SPA
 * signs the user out on an auth-shaped 401 and neither of those should log
 * anybody out.
 */
trait ResolvesSireUser
{
    protected function sireUser(): SireUserIdentity
    {
        $user = app(SireUserProvider::class)->currentUser();

        abort_if($user === null, 401, 'Not authenticated.');

        return $user;
    }

    /** For endpoints that legitimately work without an actor. */
    protected function sireUserOrNull(): ?SireUserIdentity
    {
        return app(SireUserProvider::class)->currentUser();
    }
}
