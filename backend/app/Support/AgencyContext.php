<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * Resolves the single "agency" tenant that external client companies belong to.
 *
 * External companies are clients of the recruitment agency, not independent
 * tenants — so a self-registering company is bound to the agency tenant at
 * registration. The agency tenant is configurable per deployment; when unset it
 * resolves to the tenant actually running the recruitment desk (its HR staff).
 */
class AgencyContext
{
    public static function tenantId(): int
    {
        $configured = config('recruitment.agency_tenant_id');
        if ($configured) {
            return (int) $configured;
        }

        $tid = User::query()->where('status', 'active')
                ->whereIn('internal_role', ['hr_executive', 'hr_recruiter'])
                ->orderBy('id')->value('tenant_id')
            ?? User::query()->where('status', 'active')->where('role', 'admin')
                ->orderBy('id')->value('tenant_id')
            ?? Tenant::query()->orderBy('id')->value('id');

        return (int) $tid;
    }
}
