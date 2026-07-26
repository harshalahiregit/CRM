<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Agency tenant
    |--------------------------------------------------------------------------
    | This platform is a Recruitment Agency CRM: external companies are CLIENTS
    | of the agency, not independent tenants. A self-registering company is bound
    | to the agency's tenant at registration time (never null, never reassigned).
    |
    | Set RECRUITMENT_AGENCY_TENANT_ID per deployment. If unset, it resolves to
    | the agency tenant that owns the recruitment operation (see AgencyContext).
    */
    'agency_tenant_id' => env('RECRUITMENT_AGENCY_TENANT_ID'),
];
