<?php

namespace App\Http\Middleware;

use App\Models\Purchase\PurchaseVendor;
use App\Services\Purchase\PurchaseAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the Purchase Vendor self-service portal.
 *
 * Completely independent of the shared vendor portal (EnsureVendorPortalAccess):
 * the authenticated identity MUST be a PurchaseVendor (a Sanctum token whose
 * tokenable is purchase_vendors), never a shared User / TPV account. This is what
 * isolates the two portals — a shared/TPV login is a User, not a PurchaseVendor,
 * and is refused here; a PurchaseVendor token has no `role` and is refused by the
 * shared vendor portal middleware.
 */
class EnsurePurchaseVendorPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = $request->user();

        if (! $vendor instanceof PurchaseVendor) {
            return response()->json([
                'status'  => 'error',
                'message' => 'This area is for Purchase vendor accounts only.',
            ], 403);
        }

        // Expiry BEFORE the portal_status check, because expiring is what sets
        // portal_status to suspended — running them the other way round would
        // report "not active" on the very request that ends the window, which
        // tells the vendor nothing about why.
        //
        // This gate is what makes a temporary Purchase vendor actually temporary.
        // Until it existed, the countdown reached zero, the badge turned red and
        // the vendor carried on using the portal: nothing consulted the expiry,
        // and no sweep set portal_status. The hourly sweep still runs, but it is
        // hourly — this closes the gap on the request itself.
        if ($vendor->isAccessExpired()) {
            app(PurchaseAccessService::class)->lazyExpire($vendor);

            return response()->json([
                'status'  => 'error',
                'code'    => 'access_expired',
                'message' => 'Your temporary access has expired. Please contact your administrator.',
            ], 403);
        }

        if ($vendor->portal_status !== 'active') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Your Purchase portal account is not active.',
            ], 403);
        }

        // Ambient identity — portal endpoints read the authenticated PurchaseVendor
        // directly; there is never a vendor id in the URL to forge.
        $request->attributes->set('purchaseVendor', $vendor);

        return $next($request);
    }
}
