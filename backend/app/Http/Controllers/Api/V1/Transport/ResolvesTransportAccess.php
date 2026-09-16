<?php

namespace App\Http\Controllers\Api\V1\Transport;

use Illuminate\Http\Request;

/**
 * The two things every STOS controller needs, in one place.
 *
 * ── On `?company_id=` ────────────────────────────────────────────────────
 * The M2 brief specifies `GET .../eligible?company_id=1`. That parameter is
 * NOT trusted here and never will be: a company id taken from the query string
 * is a cross-tenant read for anyone who can edit a URL. The workspace is
 * resolved from the signed-in user, and a supplied company_id is only allowed
 * to AGREE with it — a mismatch is refused rather than quietly ignored, so a
 * caller relying on the parameter finds out immediately instead of silently
 * receiving someone else's fleet.
 */
trait ResolvesTransportAccess
{
    private const EXTERNAL_ROLES = ['client', 'vendor', 'third_party_vendor', 'company', 'doctor'];

    protected function companyId(Request $request): int
    {
        $session = (int) ($request->user()->company_id ?? $request->user()->tenant_id);
        $asked = $request->query('company_id');

        abort_if(
            $asked !== null && (int) $asked !== $session,
            403,
            'You cannot read another company\'s fleet.'
        );

        return $session;
    }

    protected function denyExternal(Request $request): void
    {
        abort_if(
            in_array($request->user()?->role, self::EXTERNAL_ROLES, true),
            403,
            'You do not have access to the fleet.'
        );
    }

    protected function isAdmin(Request $request): bool
    {
        return $request->user()?->role === 'admin';
    }
}
