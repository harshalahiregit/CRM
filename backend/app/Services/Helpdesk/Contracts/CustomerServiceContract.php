<?php

namespace App\Services\Helpdesk\Contracts;

/**
 * Contract for reading Customer data owned by Zafar's Sales/Customer module.
 *
 * Helpdesk NEVER queries the customers table directly. It depends on this
 * interface; a mock satisfies it today, and Zafar's real implementation is
 * swapped in later by binding this interface in a service provider — no changes
 * needed inside HelpdeskService.
 */
interface CustomerServiceContract
{
    /**
     * Return a lightweight customer record, or null if it does not exist / is
     * not visible to the given tenant.
     *
     * @return array{id:int, name:string, email:?string, company:?string}|null
     */
    public function getCustomer(int $customerId, int $tenantId): ?array;

    /**
     * Whether the customer exists and belongs to the tenant.
     */
    public function exists(int $customerId, int $tenantId): bool;
}
