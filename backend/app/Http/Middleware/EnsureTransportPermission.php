<?php

namespace App\Http\Middleware;

use App\Services\Transport\TransportPermissionService;
use App\Support\Transport\TransportPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Transport permission gate (SNG-TRN-028) — `transport.permission:<key>`.
 *
 * Applied to route GROUPS rather than inside methods, for the reason
 * EnsureCanManageHrQueue records: a check called from inside each method is one
 * somebody eventually forgets to add, and the method they forget is usually the
 * index() that lists the whole tenant.
 *
 * Fails closed in three separate ways, all deliberate:
 *
 *   No authenticated user            -> 401
 *   Route names an unknown permission -> 500. A route gated on a key that does
 *     not exist is a bug in the route, not a reason to let the request through.
 *     Copied from EnsureStaffPermission, which fails closed for the same reason:
 *     the alternative is a gate that silently permits everything after a rename.
 *   User holds no scope for the key   -> 403
 */
class EnsureTransportPermission
{
    public function __construct(private TransportPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! TransportPermission::isPermission($permission)) {
            return response()->json([
                'status'  => 'error',
                'message' => "This route is gated on an unknown transport permission ({$permission}).",
            ], 500);
        }

        if (! $this->permissions->can($user, $permission)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You do not have permission to do that.',
            ], 403);
        }

        return $next($request);
    }
}
