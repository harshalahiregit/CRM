<?php

namespace App\Services\Helpdesk\Mocks;

use App\Services\Helpdesk\Contracts\CustomerServiceContract;

/**
 * Temporary stand-in for Zafar's real CustomerService.
 *
 * Returns deterministic fake customers so Helpdesk ticket creation works
 * end-to-end before the Sales/Customer module is merged. Replace by binding
 * CustomerServiceContract to Zafar's implementation — delete this class then.
 */
class MockCustomerService implements CustomerServiceContract
{
    /** A small fixed roster so responses are stable across requests. */
    private const ROSTER = [
        1 => ['name' => 'Acme Corporation',   'email' => 'support@acme.test',    'company' => 'Acme Corporation'],
        2 => ['name' => 'Globex Pvt Ltd',     'email' => 'hello@globex.test',    'company' => 'Globex Pvt Ltd'],
        3 => ['name' => 'Initech Solutions',  'email' => 'contact@initech.test', 'company' => 'Initech Solutions'],
    ];

    public function getCustomer(int $customerId, int $tenantId): ?array
    {
        if (! $this->exists($customerId, $tenantId)) {
            return null;
        }

        $row = self::ROSTER[$customerId] ?? [
            'name'    => "Customer #{$customerId}",
            'email'   => "customer{$customerId}@example.test",
            'company' => null,
        ];

        return [
            'id'      => $customerId,
            'name'    => $row['name'],
            'email'   => $row['email'],
            'company' => $row['company'],
        ];
    }

    public function exists(int $customerId, int $tenantId): bool
    {
        // Mock rule: any positive id is a valid customer for any tenant.
        return $customerId > 0;
    }

    /** The fixed roster — enough to drive a real customer picker before Sales lands. */
    public function listCustomers(int $tenantId): array
    {
        return array_map(
            fn (int $id) => $this->getCustomer($id, $tenantId),
            array_keys(self::ROSTER),
        );
    }
}
