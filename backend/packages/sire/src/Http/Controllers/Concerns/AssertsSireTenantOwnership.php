<?php

namespace Sire\Http\Controllers\Concerns;

use Sire\Contracts\SireTenantProvider;

/**
 * SIRE — the controller-level tenant guard, called 55 times across SIRE.
 *
 * Route-model binding resolves {report} by primary key alone; the key is a
 * global sequence, so binding will happily hand a controller another tenant's
 * record. Every SIRE controller action that receives a bound model calls this
 * before doing anything with it.
 *
 * IT MUST 404, NOT 403
 *
 * 403 says "this exists but is not yours", which turns any id field into a
 * cross-tenant existence oracle: increment the id, watch the status codes, and
 * learn how many issues another tenant has. 404 makes another tenant's records
 * indistinguishable from records that were never created.
 */
trait AssertsSireTenantOwnership
{
    protected function assertTenantOwnership(object ...$records): void
    {
        $tenants = app(SireTenantProvider::class);

        foreach ($records as $record) {
            $tenants->assertAccess($record);
        }
    }
}
