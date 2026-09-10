<?php

namespace Sire\Contracts;

use Sire\Dto\SireTenantIdentity;

/**
 * SIRE SDK — WHICH TENANT.
 *
 * The most dangerous contract in the SDK, and the reason to implement it first.
 *
 * WHY THIS ONE IS DIFFERENT
 *
 * Every other provider fails loudly. A wrong notification provider throws; a
 * wrong numbering provider produces an ugly reference; a wrong knowledge provider
 * shows an empty panel. A wrong tenant provider returns ANOTHER TENANT'S DATA on
 * a page that renders perfectly normally.
 *
 * That asymmetry drives the whole design of this interface:
 *
 *   - `currentTenant()` has no nullable return and no default argument. There is
 *     nowhere to put a fallback, because a fallback IS the bug.
 *   - `assertAccess()` must abort 404, never 403. A 403 confirms the record
 *     exists, which turns any id field into a cross-tenant existence oracle:
 *     increment the id, watch the status codes, count another tenant's issues.
 *   - Nothing here accepts a tenant id. Implementations resolve from
 *     server-side state only — never a request body, query string, route
 *     parameter or header.
 *
 * A HOST CANNOT OPT OUT OF ISOLATION
 *
 * Implementing this interface does not hand tenancy to the host. SIRE still
 * scopes every query itself and still calls assertAccess() on every route-bound
 * record; this provider only answers "which tenant is this request". A host
 * adapter that returns a tenant it should not have does not bypass SIRE's checks
 * — it lies to them, which is why the manual two-tenant verification in
 * docs/INSTALLATION.md is not optional.
 */
interface SireTenantProvider
{
    /**
     * The tenant this request belongs to, from server-side state only.
     *
     * @throws \RuntimeException when no tenant can be established. MUST throw —
     *         returning 0, null or a default tenant converts a missing session
     *         into a silent cross-tenant read.
     */
    public function currentTenant(): SireTenantIdentity;

    /** Whether currentTenant() would succeed. Never used to select a fallback. */
    public function hasTenant(): bool;

    /** Whether a tenant exists at all — used by console commands given --tenant. */
    public function exists(int $tenantId): bool;

    /**
     * Assert that $record belongs to the current tenant.
     *
     * @param  object $record any SIRE model; all of them carry tenant_id NOT NULL
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 404, not 403
     */
    public function assertAccess(object $record): void;
}
