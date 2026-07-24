<?php

namespace App\Http\Middleware;

use App\Models\Vendor\Vendor;
use App\Services\Tpv\TpvAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request expiry gate for Temporary TPVs.
 *
 * Resolves the caller's vendor; if it is temporary and the access window has run
 * out, lazily marks it Expired (revoking sessions) and returns a 403 with the
 * `access_expired` code. A no-op for permanent vendors and non-vendor users, so
 * it is safe to add to any vendor-facing group.
 */
class EnsureTemporaryAccessNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $vendor = $request->attributes->get('portalVendor')
                ?? Vendor::forTenant($user->tenant_id)->where('user_id', $user->id)->first();

            if ($vendor && $vendor->isAccessExpired()) {
                app(TpvAccessService::class)->lazyExpire($vendor);

                return response()->json([
                    'status'  => 'error',
                    'code'    => 'access_expired',
                    'message' => 'Your temporary access has expired. Please contact your administrator.',
                ], 403);
            }
        }

        return $next($request);
    }
}
