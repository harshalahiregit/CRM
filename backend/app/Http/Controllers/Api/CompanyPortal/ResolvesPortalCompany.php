<?php

namespace App\Http\Controllers\Api\CompanyPortal;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExternalCompany;
use App\Models\Hr\HrHiringRequest;
use Illuminate\Http\Request;

/**
 * Shared plumbing for company-portal controllers: read the ambient company the
 * middleware resolved, assert ownership of sub-resources (404, existence-hiding),
 * and enforce company sub-role write permission.
 */
trait ResolvesPortalCompany
{
    /** The caller's own company (guaranteed present by the portal middleware). */
    protected function portalCompany(Request $request): HrExternalCompany
    {
        return $request->attributes->get('portalCompany');
    }

    /** 404 unless the hiring request belongs to the caller's company. */
    protected function assertRequestOwned(Request $request, ?HrHiringRequest $hiringRequest): void
    {
        $company = $this->portalCompany($request);

        abort_if(
            ! $hiringRequest || (int) $hiringRequest->external_company_id !== (int) $company->id,
            404,
            'Hiring request not found',
        );
    }

    /** 403 for read-only company sub-roles (Company Manager) attempting a mutation. */
    protected function assertCanManage(Request $request): void
    {
        if (! $request->user()->canManageCompany()) {
            throw new BusinessException('Your role has read-only access.', 403);
        }
    }
}
