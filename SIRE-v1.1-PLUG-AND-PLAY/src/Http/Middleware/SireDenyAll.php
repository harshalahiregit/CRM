<?php

namespace Sire\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * SIRE — refuses every request, and explains why.
 *
 * Bound only when `config('sire.host.auth_middleware')` is empty. That is not a
 * state anyone reaches deliberately; it is what happens when a key is emptied
 * during debugging, mistyped, or never set because installation was skipped.
 *
 * The alternative — serving SIRE with no authentication — would publish an
 * organisation's entire defect backlog, including reproduction steps for
 * unfixed bugs, to anyone who found the URL. So the failure is total and
 * self-describing rather than silent and convenient.
 */
class SireDenyAll
{
    public function handle(Request $request, Closure $next)
    {
        abort(503, implode(' ', [
            'SIRE is not configured: no authentication middleware is set.',
            "Set config('sire.host.auth_middleware'), for example ['auth:sanctum'],",
            'or run: php artisan sire:install',
        ]));
    }
}
