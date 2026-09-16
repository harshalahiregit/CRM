<?php

namespace Sire\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Sire\Contracts\SireCustomerProvider;
use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Models\Report;
use Sire\Services\SireAccessService;

/**
 * SIRE — naming the customer an issue affects.
 *
 * READ-ONLY against the host's directory. SIRE never creates, edits or deletes a
 * customer; it records an id against a defect so the register can answer "which
 * customers are hitting this", and that is the whole relationship.
 *
 * Both routes sit behind the ordinary SIRE middleware, so a customer or vendor
 * login never reaches them -- which matters more here than elsewhere, because
 * this is the one endpoint in SIRE that reads another module's data.
 */
class SireCustomerController
{
    use AssertsSireTenantOwnership;
    use ResolvesSireUser;
    use SireApiResponse;

    public function __construct(
        private readonly SireCustomerProvider $customers,
        private readonly SireAccessService $access,
    ) {
    }

    /**
     * GET /sire/customers?q= — type-ahead against the host directory.
     *
     * `available` is in the payload so the client can hide the control entirely
     * rather than draw a search box that can only ever return nothing. A person
     * who searches an empty directory concludes their customer is missing; one
     * who sees no box concludes the feature is not wired up, which is true.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $this->sireUser()->tenantId;

        $query = (string) $request->query('q', '');

        return $this->success([
            'available' => $this->customers->isAvailable(),
            'customers' => $this->customers->isAvailable()
                ? array_map(
                    fn ($customer) => $customer->toArray(),
                    $this->customers->search($tenantId, $query, 10),
                )
                : [],
        ]);
    }

    /**
     * PUT /sire/reports/{report}/customer — set or clear it.
     *
     * Triage, not development: saying whose problem this is changes how it is
     * prioritised, so it sits with the people who own the queue rather than with
     * whoever happens to be assigned.
     *
     * A null id clears it. Being unable to undo a mis-click is how a field fills
     * with values nobody trusts.
     */
    public function update(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        $actor = $this->sireUser();
        $this->access->assert($actor, 'sire.report.triage', $report);

        $customerId = $request->validate([
            'customer_id' => ['nullable'],
        ])['customer_id'] ?? null;

        $customer = null;

        if ($customerId !== null && $customerId !== '') {
            // Resolved through the provider, never trusted from the body. An id
            // that does not belong to this tenant comes back null here, so a
            // guessed number cannot attach another workspace's customer to an
            // issue -- and cannot confirm that the number exists either.
            $customer = $this->customers->find((int) $actor->tenantId, $customerId);

            if ($customer === null) {
                return $this->error('That customer could not be found in your workspace.', 422);
            }
        }

        $before = $report->customer_id;
        $report->customer_id = $customer?->id;
        $report->save();

        $report->recordAudit(
            $customer ? 'Affected customer set' : 'Affected customer cleared',
            $actor,
            null,
            [
                'action'   => 'customer_changed',
                'system'   => true,
                'before'   => $before,
                'after'    => $customer?->id,
                'customer' => $customer?->name,
            ],
        );

        return $this->success(['customer' => $customer?->toArray()]);
    }
}
