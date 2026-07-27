<?php

namespace App\Http\Middleware;

use App\Models\Vendor\Vendor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the vendor self-service portal.
 *
 * The whole security model of the portal rests on one idea: a vendor never names
 * their own id. This middleware resolves the caller's Vendor record from the
 * authenticated user and attaches it to the request, so every portal endpoint
 * reads its subject from `portalVendor` rather than from a route parameter a
 * client could tamper with. There is simply no vendor id in the URL to forge.
 *
 * Denials here are 403 ("you may not enter this area"): wrong role, or a login
 * with no linked vendor profile. Cross-vendor access to a SPECIFIC record (this
 * invoice isn't yours) is handled separately as a 404 by the ownership helper —
 * existence-hiding, so the portal never confirms another vendor's records exist.
 */
class EnsureVendorPortalAccess
{
    /** Roles that own a vendor profile — Purchase-side and TPV-side logins. */
    private const PORTAL_ROLES = ['vendor', 'third_party_vendor'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        if (! in_array($user->role, self::PORTAL_ROLES, true)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This area is for vendor accounts only.',
            ], 403);
        }

        // Resolve within the caller's tenant — a vendor login is bound to one
        // tenant, and the record must belong to it.
        $vendor = Vendor::forTenant($user->tenant_id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if (! empty($user->email)) {
                    $q->orWhere('email', $user->email);
                }
            })
            ->first();

        if (! $vendor) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your account is not linked to a vendor profile yet. Please contact the procurement team.',
            ], 403);
        }

        if (! $vendor->user_id) {
            $vendor->update(['user_id' => $user->id]);
        }

        // Ambient identity for the request — endpoints and the ownership helper
        // read this, never a parameter.
        $request->attributes->set('portalVendor', $vendor);

        return $next($request);
    }
}
