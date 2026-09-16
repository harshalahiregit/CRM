<?php

namespace Sire\Contracts;

use Sire\Dto\SireCustomer;

/**
 * SIRE SDK — which customer an issue affects.
 *
 * SIRE BUILDS NO CUSTOMER RECORD. Every CRM already has one, and a defect
 * register that grew its own would immediately be the second place a customer
 * name lives and the first place it goes stale.
 *
 * WHY THIS IS WORTH A SEAM AT ALL. "Which customers are hitting this" is the
 * question that turns a backlog into a priority order. A P3 nobody has mentioned
 * and a P3 that three customers have raised this month are not the same defect,
 * and without this SIRE cannot tell them apart.
 *
 * FULLY OPTIONAL. Unbound, isAvailable() is false, the picker does not render,
 * and every issue simply has no customer — which is the correct state for an
 * internal-only workspace. Nothing else in SIRE changes.
 */
interface SireCustomerProvider
{
    /**
     * Whether a customer directory exists at all.
     *
     * The UI asks this before it draws a search box, so nobody is offered a
     * control that can only ever return nothing.
     */
    public function isAvailable(): bool;

    /**
     * Type-ahead search within one tenant.
     *
     * @return array<int, SireCustomer>
     */
    public function search(int $tenantId, string $query, int $limit = 10): array;

    /**
     * One customer by id, or null when absent or belonging to another tenant —
     * the two are deliberately indistinguishable, as everywhere else in SIRE.
     */
    public function find(int $tenantId, int|string $customerId): ?SireCustomer;
}
