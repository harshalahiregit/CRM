<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireTenantProvider;
use App\Support\Sire\Sdk\SireTenantIdentity;
use RuntimeException;

/**
 * HostTenantProvider — CONNECT YOUR APPLICATION HERE.
 *
 * FIND IN YOUR APP: however a request learns which tenant it belongs to — a column on the user, a subdomain, a middleware
 *
 * This is the only kind of file you write to install SIRE. Implement the
 * 4 methods below, then point config/sire.php at this class:
 *
 *     'providers' => ['tenant' => \App\Sire\Host\HostTenantProvider::class],
 *
 * Until you do, SIRE runs on its own implementation and everything keeps
 * working. Wiring one provider at a time is the expected path, not a compromise.
 *
 * Every method throws until you replace it. That is deliberate: a half-finished
 * provider should stop with a message naming the method, not quietly return an
 * empty array that the UI renders as "nothing here".
 *
 * WATCH OUT
 *
 * IMPLEMENT THIS FIRST, and never add a fallback. Every other provider fails
 * loudly; this one fails silently by returning another tenant's data on a page
 * that renders perfectly normally. assertAccess() must abort 404, not 403 -- a
 * 403 confirms the record exists and turns any id field into a cross-tenant
 * existence oracle.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostTenantProvider implements SireTenantProvider
{
    public function currentTenant(): SireTenantIdentity
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostTenantProvider::currentTenant() is not implemented. Either finish it, or point '
            ."config('sire.providers.tenant') back at SIRE's own provider."
        );
    }

    public function hasTenant(): bool
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostTenantProvider::hasTenant() is not implemented. Either finish it, or point '
            ."config('sire.providers.tenant') back at SIRE's own provider."
        );
    }

    public function exists(int $tenantId): bool
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostTenantProvider::exists() is not implemented. Either finish it, or point '
            ."config('sire.providers.tenant') back at SIRE's own provider."
        );
    }

    public function assertAccess(object $record): void
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostTenantProvider::assertAccess() is not implemented. Either finish it, or point '
            ."config('sire.providers.tenant') back at SIRE's own provider."
        );
    }
}
