<?php

namespace Transport\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Transport\Access\AccessGate;
use Transport\Access\PermissionRegistry;

/**
 * Route gate for transport permissions.
 *
 *     ->middleware('transport.permission:trip,approve')
 *     ->middleware('transport.permission:pod,submit')
 *
 * The resolved scope is attached to the request as `transport_scope` so the
 * controller does not have to ask the same question twice — and so that a
 * controller which forgets to narrow its query is a visible omission rather
 * than an invisible one.
 *
 * ── Why this does not copy the host's staff-only rule ──
 *
 * EnsureStaffPermission refuses any account whose `users.role` is not admin or
 * staff, because the CRM's grid is a staff grid and a portal identity reaching
 * an HR endpoint is a bug. Transport is different on purpose: Step 11 grants
 * `Own` to Driver and Customer and `Assigned` to Supplier, so portal accounts
 * are expected here and shutting them out would make PERM-001, 006, 008, 010
 * and 012 unreachable for the people they were written for.
 *
 * Portal accounts are still constrained, just by the sheet rather than by the
 * door: an unmapped account resolves to no role and is refused, and a mapped
 * one gets `own` or `assigned`, never `full`.
 */
class EnsureTransportPermission
{
    public function __construct(private AccessGate $gate)
    {
    }

    public function handle(Request $request, Closure $next, string $domain, string $action): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unauthenticated',
            ], 401);
        }

        // A route naming a permission the registry does not define is a bug in
        // the route. Fail closed and name it, rather than letting the request
        // through on the strength of a typo. Same reasoning as the host gate:
        // a permission layer that permits everything after a rename is worse
        // than no permission layer, because it still looks like one.
        if (! PermissionRegistry::defines($domain, $action)) {
            return response()->json([
                'status'  => 'error',
                'message' => $this->gate->denialReason($user, $domain, $action),
            ], 500);
        }

        $scope = $this->gate->scope($user, $domain, $action);

        if ($scope === null) {
            return response()->json([
                'status'  => 'error',
                'message' => $this->gate->denialReason($user, $domain, $action),
            ], 403);
        }

        $request->attributes->set('transport_scope', $scope);
        $request->attributes->set('transport_permission', PermissionRegistry::idFor($domain, $action));

        return $next($request);
    }
}
