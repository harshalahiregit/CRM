<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\StaffPermissionService;
use App\Support\Hr\StaffPermission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for the staff permission grid.
 *
 *     ->middleware('permission:invoices,create')
 *     ->middleware('permission:reports,view_global')
 *
 * The grid has been stored since Staff Management shipped and never consulted.
 * This is how a module starts consulting it, one route at a time — the whole
 * point of moving gradually is that each adoption can be reviewed on its own,
 * rather than 23 modules changing behaviour on the same deploy.
 *
 * An admin passes everything (StaffPermissionService::bypasses), matching the old
 * CRM's tblstaff.admin flag. So attaching this to a route that is already
 * role:admin changes nothing today — which is exactly what makes it a safe first
 * adopter and a truthful statement of intent.
 */
class EnsureStaffPermission
{
    public function __construct(private StaffPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next, string $module, string $capability): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        // Same reasoning as EnsureUserHasRole: other authenticatable models carry
        // their own columns, and a portal identity must never satisfy a staff gate.
        if (! $user instanceof User) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This endpoint is not available to portal accounts.',
            ], 403);
        }

        // A route naming a module or capability that does not exist is a bug in
        // the route, not a reason to let the request through. Fail closed and say
        // so plainly, because the alternative is a gate that silently permits
        // everything after a rename.
        if (! StaffPermission::isModule($module) || ! StaffPermission::isCapability($capability)) {
            return response()->json([
                'status'  => 'error',
                'message' => "This route is gated on an unknown permission ({$capability} on {$module}).",
            ], 500);
        }

        if (! $this->permissions->can($user, $capability, $module)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'You do not have permission to do that.',
            ], 403);
        }

        return $next($request);
    }
}
