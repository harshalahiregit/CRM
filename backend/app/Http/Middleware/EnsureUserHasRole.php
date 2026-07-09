<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = $request->user();
        $userRole = $user->role;
        $internalRole = $user->internal_role;

        // Check if user's primary role matches
        if (in_array($userRole, $roles)) {
            return $next($request);
        }

        // For staff members, also check their internal_role
        // This allows routes like ->middleware('role:hr_executive') to work for staff with internal_role=hr_executive
        if ($userRole === 'staff' && $internalRole && in_array($internalRole, $roles)) {
            return $next($request);
        }

        // Allow 'staff' role to match when any internal role is specified in route
        if (in_array('staff', $roles) && $userRole === 'staff') {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Unauthorized. Required role: ' . implode(' or ', $roles),
            'user_role' => $userRole,
            'internal_role' => $internalRole,
        ], 403);
    }
}
