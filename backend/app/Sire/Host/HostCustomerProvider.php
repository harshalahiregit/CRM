<?php

namespace App\Sire\Host;

use App\Models\Customer\Client;
use Sire\Contracts\SireCustomerProvider;
use Sire\Dto\SireCustomer;

/**
 * SIRE -> this CRM's Customer Directory.
 *
 * Reads `clients` directly rather than through CustomerServiceContract, and the
 * reason is search: that contract offers getCustomer, exists and listCustomers,
 * and listCustomers loads EVERY client with their primary contact eager-loaded.
 * Fine for a dropdown of eight; a type-ahead that pulls the whole directory on
 * each keystroke is how a customer list becomes a performance incident once
 * somebody has four thousand of them.
 *
 * READ-ONLY, ALWAYS. SIRE never creates, edits or deletes a client. It names one
 * on a defect so that "which customers are hitting this" has an answer, and
 * that is the entire relationship. Nothing here writes.
 *
 * Tenant comes from the caller, never from the request: ->forTenant($tenantId)
 * on every query, the same rule as the rest of SIRE.
 */
class HostCustomerProvider implements SireCustomerProvider
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function search(int $tenantId, string $query, int $limit = 10): array
    {
        $query = trim($query);

        return Client::forTenant($tenantId)
            ->when($query !== '', fn ($q) => $q->where('company', 'like', "%{$query}%"))
            ->orderBy('company')
            ->limit(max(1, min($limit, 50)))
            // Named columns, not SELECT *: a client row carries an opening
            // balance and an address, and SIRE has no business holding either.
            ->get(['id', 'company'])
            ->map(fn (Client $client) => $this->toCustomer($client))
            ->all();
    }

    public function find(int $tenantId, int|string $customerId): ?SireCustomer
    {
        $client = Client::forTenant($tenantId)
            ->whereKey($customerId)
            ->first(['id', 'company']);

        return $client ? $this->toCustomer($client) : null;
    }

    private function toCustomer(Client $client): SireCustomer
    {
        return new SireCustomer(
            id: (int) $client->id,
            name: (string) $client->company,
            // No account code on this table; the company name is the reference
            // people use, so there is nothing honest to put here.
            reference: null,
            // Deep link into the directory the name came from, so an engineer
            // reading the issue can reach the customer record in one click.
            url: '/app/customers/'.$client->id,
        );
    }
}
