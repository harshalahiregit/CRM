<?php

namespace Sire\Http\Requests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Sire\Contracts\SireTenantProvider;

/**
 * SIRE — ownership is decided BEFORE validation, not after.
 *
 * Laravel runs authorize() first and rules() second, so a FormRequest that leaves
 * ownership to the controller answers a cross-tenant id with 422 "your payload is
 * invalid" instead of the 404 the rest of SIRE returns. That is not a data leak --
 * the same body fails identically against a record you own -- but it is a
 * different answer for another tenant's id than for your own, and existence
 * hiding is worth keeping total rather than nearly total.
 *
 * Call from authorize(). assertAccess() aborts 404 itself, so there is nothing
 * to return but true.
 */
trait ChecksBoundTenant
{
    /** Abort 404 unless every route-bound SIRE model belongs to the caller's tenant. */
    protected function assertBoundTenant(string ...$parameters): bool
    {
        $tenants = app(SireTenantProvider::class);

        foreach ($parameters as $parameter) {
            $record = $this->route($parameter);

            if ($record instanceof Model) {
                $tenants->assertAccess($record);
            }
        }

        return true;
    }
}
