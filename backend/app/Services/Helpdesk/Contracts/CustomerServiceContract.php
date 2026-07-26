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

    /**
     * Every customer visible to the tenant, for "pick a customer" UI.
     *
     * Added because a searchable customer picker is impossible with only
     * getCustomer(id) — the alternative was making users type a raw customer id
     * into a form, which is what this replaces. Any real implementation already
     * has this query; it just wasn't exposed.
     *
     * @return array<int, array{id:int, name:string, email:?string, company:?string}>
     */
    public function listCustomers(int $tenantId): array;
}
