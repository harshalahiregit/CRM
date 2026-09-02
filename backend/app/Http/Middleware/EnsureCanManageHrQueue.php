<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The HR queue gate, as a route middleware.
 *
 * canManageHrQueue() is called from 91 places inside controllers. That works
 * until somebody adds a method that does not call it — which is exactly how a
 * list-everything endpoint ends up ungated. Applied to a route group instead, a
 * new route is covered by default rather than by remembering.
 */
class EnsureCanManageHrQueue
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        // Portal identities carry their own columns and must never satisfy a
        // staff gate — the same guard EnsureUserHasRole makes.
        if (! $user instanceof User || ! $user->canManageHrQueue()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You are not authorised to manage this.',
            ], 403);
        }

        return $next($request);
    }
}
