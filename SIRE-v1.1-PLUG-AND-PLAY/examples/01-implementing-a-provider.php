<?php

/*
 * ============================================================================
 * CREATE IN HOST — connecting SIRE to your application
 * ============================================================================
 *
 * There is no provider class to write. SireServiceProvider ships in the package
 * at backend/app/Providers/SireServiceProvider.php — copy it, register it in
 * bootstrap/providers.php, and SIRE is installed:
 *
 *     App\Providers\SireServiceProvider::class,
 *
 * At that point SIRE RUNS. Every one of the thirteen SDK contracts has an
 * implementation SIRE owns, so you can report, triage, assign, develop, QA and
 * release before writing a line of integration code.
 *
 * What you write, when you want to, is a PROVIDER. This shows the shape of one.
 * Thirteen stubs are waiting in integration/host-adapters/.
 *
 * NOTE — this file is an ILLUSTRATION, not a droppable class: its filename does
 * not match the class inside it, so copying it as-is fails PSR-4 autoloading.
 * Start from integration/host-adapters/HostTenantProvider.php, which is already
 * named correctly.
 * ============================================================================
 */

namespace App\Sire\Host;

use App\Contracts\Sire\Sdk\SireTenantProvider;
use App\Support\Sire\Sdk\SireTenantIdentity;
use RuntimeException;

/*
 * ---------------------------------------------------------------------------
 * STEP 1 — implement the contract.
 *
 * Tenancy first, always. Every other provider fails loudly; this one fails
 * silently, by returning another tenant's data on a page that looks completely
 * normal.
 * ---------------------------------------------------------------------------
 */
class HostTenantProvider implements SireTenantProvider
{
    public function currentTenant(): SireTenantIdentity
    {
        $tenantId = auth()->user()?->tenant_id;   // <HOST_TENANT_RESOLVER>

        if (empty($tenantId)) {
            // Throw. Never return 0, null, or a "default" tenant: each of those
            // converts a missing session into a silent cross-tenant read, which
            // is the single failure this contract exists to prevent.
            throw new RuntimeException('SIRE: no tenant in scope.');
        }

        return new SireTenantIdentity((int) $tenantId);
    }

    public function hasTenant(): bool
    {
        return ! empty(auth()->user()?->tenant_id);
    }

    public function exists(int $tenantId): bool
    {
        // <PLACEHOLDER: confirm this tenant exists>
        // Used only by console commands given --tenant. Returning true when you
        // cannot cheaply tell is fine: every query is still scoped by that id,
        // so a wrong one yields an empty result rather than someone else's data.
        return true;
    }

    public function assertAccess(object $record): void
    {
        // 404, NOT 403. A 403 confirms the record exists, which turns any id
        // field into a cross-tenant existence oracle: increment the id, watch
        // the status codes, and count another tenant's issues.
        abort_if((int) ($record->tenant_id ?? 0) !== $this->currentTenant()->id, 404);
    }
}

/*
 * ---------------------------------------------------------------------------
 * STEP 2 — bind it. config/sire.php, nothing else.
 *
 *     'providers' => [
 *         'tenant' => \App\Sire\Host\HostTenantProvider::class,
 *
 *         // The other twelve can stay on SIRE's own implementations for as long
 *         // as you like. Six of them — audit, notes, settings, numbering, SLA
 *         // and knowledge — are meant to stay there permanently unless you have
 *         // a reason otherwise.
 *         'audit' => \App\Services\Sire\Sdk\SireLocalAuditProvider::class,
 *     ],
 *
 * No SIRE service, controller or model changes. That is the entire contract.
 * ---------------------------------------------------------------------------
 */

/*
 * ---------------------------------------------------------------------------
 * STEP 3 — verify. The doctor cannot check tenancy; you must.
 *
 *     php artisan sire:doctor      # which implementation is behind each contract
 *
 * Then, by hand:
 *   1. Sign in as tenant A, create an issue, note its id.
 *   2. Sign in as tenant B, request /api/sire/reports/<that id>.
 *   3. You want a 404. A 200 means it is wrong; a 403 means it is nearly right
 *      but leaking existence.
 * ---------------------------------------------------------------------------
 */
