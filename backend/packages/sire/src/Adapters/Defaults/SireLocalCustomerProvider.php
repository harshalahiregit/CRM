<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireCustomerProvider;
use Sire\Dto\SireCustomer;

/**
 * The shipped customer provider: reports honestly that no customer directory is
 * connected.
 *
 * SIRE builds no customer record — every CRM already has one. Until
 * SireCustomerProvider is implemented, isAvailable() is false, the issue page
 * draws no customer picker, and every other feature carries on exactly as it
 * does today.
 *
 * isAvailable() is why this interface has that method: a search box that can
 * only ever return nothing is worse than no search box, because the person using
 * it concludes their customer is missing rather than that the feature is not
 * wired up.
 */
class SireLocalCustomerProvider implements SireCustomerProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function search(int $tenantId, string $query, int $limit = 10): array
    {
        return [];
    }

    public function find(int $tenantId, int|string $customerId): ?SireCustomer
    {
        return null;
    }
}
