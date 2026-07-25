<?php

namespace App\Services\Customer;

use App\Models\Customer\Client;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;

/**
 * Real implementation of the read-only customer lookup that Helpdesk (and any
 * other module) depends on. Bound to CustomerServiceContract in
 * AppServiceProvider, replacing MockCustomerService. Returns the lightweight
 * shape the contract promises; the primary contact's email is used as the
 * customer's contact email.
 */
class CustomerDirectoryService implements CustomerServiceContract
{
    public function getCustomer(int $customerId, int $tenantId): ?array
    {
        $client = Client::forTenant($tenantId)->with('primaryContact')->find($customerId);

        if (! $client) {
            return null;
        }

        return [
            'id'      => $client->id,
            'name'    => $client->company,
            'email'   => $client->primaryContact?->email,
            'company' => $client->company,
        ];
    }

    public function exists(int $customerId, int $tenantId): bool
    {
        return Client::forTenant($tenantId)->whereKey($customerId)->exists();
    }

    /**
     * Every customer visible to the tenant, for the "pick a customer" pickers.
     * Eager-loads the primary contact so the list costs two queries rather than
     * one per row, and returns the same lightweight shape as getCustomer().
     */
    public function listCustomers(int $tenantId): array
    {
        return Client::forTenant($tenantId)
            ->with('primaryContact')
            ->orderBy('company')
            ->get()
            ->map(fn (Client $client) => [
                'id'      => $client->id,
                'name'    => $client->company,
                'email'   => $client->primaryContact?->email,
                'company' => $client->company,
            ])
            ->all();
    }
}
