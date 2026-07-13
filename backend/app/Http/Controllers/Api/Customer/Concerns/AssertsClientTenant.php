<?php

namespace App\Http\Controllers\Api\Customer\Concerns;

use App\Models\Customer\Client;

/**
 * Shared guard for customer sub-resource controllers: a route-bound Client must
 * belong to the acting user's tenant. Tenant scoping is opt-in in this codebase
 * (no global scope), so every sub-resource endpoint asserts it explicitly.
 */
trait AssertsClientTenant
{
    protected function assertClientTenant(Client $client, int $tenantId): void
    {
        abort_if($client->tenant_id !== $tenantId, 403, 'Unauthorized');
    }
}
