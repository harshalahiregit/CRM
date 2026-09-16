<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\AttachContainerRequest;
use App\Http\Requests\Transport\StoreContainerRequest;
use App\Services\Transport\ConsignmentService;
use App\Services\Transport\ContainerService;
use App\Services\Transport\TransportAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Containers — the physical transport unit (STOS-CTD §8).
 *
 * Requirements: `MDM-008`, `CTD-001` (P0, "search complete lifecycle using
 * container number"), `CTD-002`, `CTD-003` through the consignment chain.
 *
 * Thin: validate through a FormRequest, call the service, return through the
 * shared ApiResponse envelope. No queries and no business logic here.
 *
 * Tenant scoping is never re-implemented per method — every lookup goes through
 * ContainerService::find(), which filters by tenant AT the point of lookup and
 * raises ResourceNotFoundException otherwise. Route-model binding is
 * deliberately not used: it would resolve a cross-tenant id first and leave the
 * check to be remembered afterwards.
 *
 * ── NO UPDATE, NO DELETE ─────────────────────────────────────────────────
 * Neither exists, and neither is an oversight. The container number IS the
 * identity; editing it would silently rewrite the association history that
 * STOS-CTD §7 requires be maintained, and deleting a container would delete
 * that history with it. A container leaves a consignment by being DETACHED,
 * which keeps the row and stamps `detached_at`.
 *
 * ── STAFF-ONLY, BY RULING ────────────────────────────────────────────────
 * Every route sits in the existing `role:admin,staff` group. There is no
 * customer-facing read, and there must not be one until D-46's scope narrowing
 * is implemented — a customer reaching any transport endpoint today receives
 * the tenant's whole list, because SCOPE_OWN narrows nothing. That matters more
 * here than anywhere else: STOS-CTD's Digital Passport is container-keyed and
 * is precisely the customer-facing route that would expose it.
 * TransportRouteExposureTest fails the build if one escapes.
 */
class TransportContainerController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ContainerService $containers,
        private ConsignmentService $consignments,
        private TransportAuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search'   => 'nullable|string|max:120',
            'attached' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:200',

            // `prohibited`, not merely absent. A container has no status of its
            // own (STOS-CTD §8 — the consignment carries the commercial facts),
            // and Laravel silently drops unlisted keys, so without this a caller
            // could send ?status=in_transit, receive a 200, and reasonably
            // believe the list had been filtered. Refusing says the feature does
            // not exist; silence would imply it does.
            'status' => ['prohibited'],
        ], [
            'status.prohibited' => 'Containers cannot be filtered by status: a container has no '
                .'status of its own. STOS-CTD §8 — the container is the physical unit; status '
                .'belongs to the consignment it is on.',
        ]);

        return $this->success(
            $this->containers->list($request->user()->tenant_id, $filters),
            'Containers retrieved',
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $container = $this->containers->find($id, $tenantId);

        return $this->success([
            'container' => $container,
            // STOS-CTD §7 — "maintain historical associations". The history is
            // part of reading a container, not a separate feature.
            'history' => $this->containers->history($container->id, $tenantId),
            'audit'   => $this->audit->forSubject($container, $tenantId),
        ], 'Container retrieved');
    }

    /**
     * CTD-001 — resolve a container by its number, however it was typed.
     *
     * The number is normalised before lookup, so `abcd-123456-7` finds
     * `ABCD1234567`. This is the anchor for the lifecycle search, not the
     * search itself — that is Block 5.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['number' => 'required|string|max:32'],
            ['number.required' => 'Enter a container number to look up.'],
        );

        return $this->success(
            $this->containers->findByNumber($data['number'], $request->user()->tenant_id),
            'Container retrieved',
        );
    }

    /** STOS-CTD §8 — every container on one consignment, current and historical. */
    public function forConsignment(Request $request, int $consignment): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return $this->success(
            $this->containers->attachmentsFor(
                $this->consignments->find($consignment, $tenantId),
                $tenantId,
                $request->boolean('active_only'),
            ),
            'Containers retrieved',
        );
    }

    public function store(StoreContainerRequest $request): JsonResponse
    {
        $container = $this->containers->create(
            $request->validated(),
            $request->user()->tenant_id,
            $request->user(),
        );

        return $this->success($container, 'Container created', 201);
    }

    public function attach(AttachContainerRequest $request, int $id): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $container = $this->containers->find($id, $tenantId);

        return $this->success(
            $this->containers->attach(
                $container,
                $this->consignments->find($request->validated()['consignment_id'], $tenantId),
                $tenantId,
                $request->user(),
            ),
            'Container attached',
            201,
        );
    }

    public function detach(Request $request, int $id): JsonResponse
    {
        $tenantId  = $request->user()->tenant_id;
        $container = $this->containers->find($id, $tenantId);

        return $this->success(
            $this->containers->detach($container, $tenantId, $request->user()),
            'Container detached',
        );
    }
}
