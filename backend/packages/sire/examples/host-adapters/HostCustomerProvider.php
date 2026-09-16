<?php

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireCustomerProvider;
use App\Support\Sire\Sdk\SireCustomer;
use RuntimeException;

/**
 * HostCustomerProvider — CONNECT YOUR CUSTOMER DIRECTORY HERE.
 *
 * FIND IN YOUR APP: however you list and look up customers/clients/accounts
 *
 * FULLY OPTIONAL. Leave this unbound and isAvailable() is false, the issue page
 * draws no customer picker, and every other SIRE feature carries on unchanged.
 * Bind it and the register gains the one question a backlog cannot answer
 * without it: WHICH CUSTOMERS ARE HITTING THIS. A P3 nobody has mentioned and a
 * P3 three customers raised this month are not the same defect.
 *
 *     'providers' => ['customer' => \App\Sire\Host\HostCustomerProvider::class],
 *
 * WATCH OUT
 *
 * READ-ONLY. SIRE never creates, edits or deletes a customer; it stores an id
 * against a defect and resolves the name on every read, so a renamed company is
 * renamed everywhere rather than leaving a stale copy on last quarter's issues.
 *
 * TENANT-SCOPE BOTH METHODS. find() returning another tenant's customer would
 * let a guessed id confirm that customer exists. Return null instead — absent
 * and not-yours are deliberately indistinguishable everywhere in SIRE.
 *
 * SELECT NAMED COLUMNS. A customer row usually carries balances, addresses and
 * contacts; SireCustomer asks for four fields and SIRE cannot leak what it never
 * receives.
 *
 * Full contract, data shapes and verification steps: docs/HOST-INTEGRATION.md
 */
class HostCustomerProvider implements SireCustomerProvider
{
    public function isAvailable(): bool
    {
        // <PLACEHOLDER: return true once the two methods below are implemented>
        throw new RuntimeException(
            'HostCustomerProvider::isAvailable() is not implemented. Either finish it, or point '
            ."config('sire.providers.customer') back at SIRE's own provider."
        );
    }

    public function search(int $tenantId, string $query, int $limit = 10): array
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostCustomerProvider::search() is not implemented. Either finish it, or point '
            ."config('sire.providers.customer') back at SIRE's own provider."
        );
    }

    public function find(int $tenantId, int|string $customerId): ?SireCustomer
    {
        // <PLACEHOLDER: call your application here, and map the result to the
        //              shape documented in docs/HOST-INTEGRATION.md>
        throw new RuntimeException(
            'HostCustomerProvider::find() is not implemented. Either finish it, or point '
            ."config('sire.providers.customer') back at SIRE's own provider."
        );
    }
}
