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

        // Only a staff User may pass a role gate.
        //
        // This compares $user->role as a string, and other authenticatable
        // models have a `role` column too — client_contacts.role is a free-text
        // field on the customer contact form, holding things like "Procurement"
        // or "Finance". A contact whose role was typed as "admin" or "staff"
        // would therefore satisfy role:admin,staff and reach the entire staff
        // API with a customer-portal token.
        //
        // No qualifying row exists today, which is the only reason this was not
        // already an incident: it needed one person to type one word into a
        // field that invites free text. The portals have their own middleware
        // (client.portal and the purchase equivalent), so nothing legitimate
        // reaches a role: gate as anything other than a User.
        if (! $user instanceof \App\Models\User) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This endpoint is not available to portal accounts.',
            ], 403);
        }

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
