<?php

namespace Sire\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sire\Contracts\SireUserProvider;
use Sire\Support\SireLoginType;

/**
 * SIRE — only ADMIN and INTERNAL_USER accounts reach SIRE.
 *
 * Runs after the host's authentication middleware, which has proved WHO the
 * caller is. This decides whether that kind of account may see SIRE at all.
 *
 * WHY THIS IS NOT OPTIONAL AND NOT CONFIGURABLE
 *
 * A SIRE issue says what is broken, how badly, since when, and how far the fix
 * has got — with reproduction steps for defects that are still unfixed. That is
 * information a customer should hear from an account manager rather than read
 * raw, and information an outside supplier should not see at all.
 *
 * The MAPPING is configurable: SIRE cannot know whether a host's "partner" role
 * means a colleague or a competitor's supplier, so an administrator declares
 * that during installation. Whether the boundary is ENFORCED is not
 * configurable, because "customers can read the defect backlog" must not be
 * reachable by editing a config file.
 *
 * TWO LOCKS ON THE SAME DOOR
 *
 * SireAccessService::can() performs the same check before every capability.
 * This middleware is the outer lock: it stops an unauthorised account before a
 * controller runs, so a route that forgot its capability check is still closed.
 *
 * 403, never 401. An SPA signs the user out on an auth-shaped 401, and a
 * customer clicking a SIRE link should be told no — not logged out of the
 * product they are paying for.
 */
class SireRequireEngineeringLogin
{
    public function handle(Request $request, Closure $next)
    {
        $user = app(SireUserProvider::class)->currentUser();

        if ($user === null) {
            // Authentication middleware should have caught this. If it did not,
            // the stack is misconfigured and the safe reading is "no".
            abort(401, 'Not authenticated.');
        }

        if (! SireLoginType::permits($user)) {
            abort(403, SireLoginType::isConfigured()
                ? 'This account type does not have access to SIRE.'
                : implode(' ', [
                    'SIRE has no login-type mapping configured, so no account has access.',
                    "Set config('sire.login_types'), or run: php artisan sire:install",
                ]));
        }

        return $next($request);
    }
}
