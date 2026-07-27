<?php

namespace App\Http\Middleware;

use App\Models\Hr\HrExternalCompany;
use App\Support\Hr\CompanyAccountStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the external company portal (mirrors EnsureVendorPortalAccess).
 *
 * A company user never names their own company id. This resolves the caller's
 * company from users.external_company_id and attaches it as the ambient
 * `portalCompany`; every endpoint reads its subject from there, never from a
 * route parameter. Denials are 403 (wrong role / unlinked / inactive); per-record
 * ownership is 404 via the ownership helper (existence-hiding).
 */
class EnsureCompanyPortalAccess
{
    private const PORTAL_ROLE = 'company';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        }

        if ($user->role !== self::PORTAL_ROLE) {
            return response()->json(['status' => 'error', 'message' => 'This area is for company accounts only.'], 403);
        }

        $company = HrExternalCompany::where('id', $user->external_company_id)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (! $company) {
            return response()->json(['status' => 'error', 'message' => 'Your account is not linked to a company profile.'], 403);
        }

        if ($company->status !== CompanyAccountStatus::ACTIVE) {
            return response()->json(['status' => 'error', 'message' => 'Your company account is not active.'], 403);
        }

        $request->attributes->set('portalCompany', $company);

        return $next($request);
    }
}
