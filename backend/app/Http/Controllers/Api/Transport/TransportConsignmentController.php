<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Support\Transport\TransportDocumentEntity;
use App\Support\Transport\TransportDocumentType;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\StoreConsignmentRequest;
use App\Http\Requests\Transport\StoreTransportDocumentRequest;
use App\Http\Requests\Transport\UpdateConsignmentRequest;
use App\Services\Transport\ConsignmentService;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consignments — the commercial shipment (STOS-CTD §8).
 *
 * Requirements: `STOS-REQ-ORD-004`, `CTD-002`, `CTD-003`, all P0. The entity
 * exists by explicit architecture approval, D-39.
 *
 * Thin: validate through a FormRequest, call the service, return through the
 * shared ApiResponse envelope. No queries and no business logic here.
 *
 * Tenant scoping is never re-implemented per method — every lookup goes through
 * ConsignmentService::find(), which filters by tenant AT the point of lookup and
 * raises ResourceNotFoundException otherwise. Route-model binding is
 * deliberately not used: it would resolve a cross-tenant id first and leave the
 * check to be remembered afterwards.
 *
 * ── STAFF-ONLY, BY RULING ────────────────────────────────────────────────
 * Every route sits in the existing `role:admin,staff` group. There is no
 * customer-facing read, and there must not be one until D-46's scope narrowing
 * is implemented — a customer reaching any transport endpoint today receives
 * the tenant's whole list, because SCOPE_OWN narrows nothing.
 * TransportRouteExposureTest fails the build if that changes.
 */
class TransportConsignmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ConsignmentService $consignments,
        private TransportAuditLogger $audit,
        private TransportDocumentService $documents,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'order_id'           => 'nullable|integer',
            'customer_id'        => 'nullable|integer',
            'customer_reference' => 'nullable|string|max:120',
            'search'             => 'nullable|string|max:120',
            'per_page'           => 'nullable|integer|min:1|max:200',

            // `prohibited`, not merely absent. There is no status column
            // (D-44), and Laravel ignores unlisted keys — so without this a
            // caller could send ?status=in_transit, get a 200, and reasonably
            // believe the list had been filtered. Refusing it says the feature
            // does not exist; silence would imply it does.
            'status' => ['prohibited'],
        ], [
            'status.prohibited' => 'Consignments cannot be filtered by status: '
                .'a consignment has no stored status. See STOS-CTD §11 — status comes from the '
                .'lifecycle engine, which is not built (D-44).',
        ]);

        return $this->success(
            $this->consignments->list($request->user()->tenant_id, $filters),
            'Consignments retrieved',
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId    = $request->user()->tenant_id;
        $consignment = $this->consignments->find($id, $tenantId);

        return $this->success([
            'consignment' => $consignment->load(['order:id,order_number,order_status', 'customer:id,company']),
            'trips'       => $consignment->trips()->get(['id', 'trip_number', 'status']),
            // D-41: the LR and the DO live here, so the detail payload carries
            // them exactly as the vehicle and driver payloads carry theirs.
            'documents'   => $consignment->documents()->orderByDesc('id')->get(),
            // Which types may be filed here, from the one place that decides —
            // TransportDocumentType::forEntity(). A form that keeps its own copy
            // of the list will offer a fitness certificate against a shipment
            // the first time somebody edits one and not the other.
            'document_types' => array_map(
                fn (string $t) => ['value' => $t, 'label' => TransportDocumentType::label($t)],
                TransportDocumentType::forEntity(TransportDocumentEntity::CONSIGNMENT),
            ),
            'audit'       => $this->audit->forSubject($consignment, $tenantId),
        ], 'Consignment retrieved');
    }

    /* ── Documents — ORD-005, ORD-006, CTD-004, CTD-005, all P0 ──────────── */

    /**
     * File a document against the shipment.
     *
     * Mirrors TransportVehicleController::storeDocument() deliberately, down to
     * the FormRequest: three entities filing documents three different ways is
     * how the validation on one of them drifts.
     *
     * The service refuses a type that does not belong to a consignment —
     * insurance and fitness describe a vehicle and outlive the shipment — so
     * there is no allow-list to repeat here.
     */
    public function storeDocument(StoreTransportDocumentRequest $request, int $id): JsonResponse
    {
        $tenantId    = $request->user()->tenant_id;
        $consignment = $this->consignments->find($id, $tenantId);
        $data        = $request->validated();

        return $this->success(
            $this->documents->file($consignment, $data['document_type'], $data, $tenantId, $request->user()),
            'Document filed', 201
        );
    }

    /** STOS-DOC §26 — a replacement is a new version, never an overwrite. */
    public function renewDocument(StoreTransportDocumentRequest $request, int $id, int $documentId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $this->consignments->find($id, $tenantId);       // tenant + existence gate
        $current  = $this->documents->find($documentId, $tenantId);

        return $this->success(
            $this->documents->renew($current, $request->validated(), $tenantId, $request->user()),
            'Document renewed', 201
        );
    }

    /** CTD-003 — every consignment on one order. */
    public function forOrder(Request $request, int $order): JsonResponse
    {
        return $this->success(
            $this->consignments->forOrder($order, $request->user()->tenant_id),
            'Consignments retrieved',
        );
    }

    public function store(StoreConsignmentRequest $request): JsonResponse
    {
        $consignment = $this->consignments->create(
            $request->validated(),
            $request->user()->tenant_id,
            $request->user(),
        );

        return $this->success($consignment, 'Consignment created', 201);
    }

    public function update(UpdateConsignmentRequest $request, int $id): JsonResponse
    {
        $tenantId    = $request->user()->tenant_id;
        $consignment = $this->consignments->find($id, $tenantId);

        return $this->success(
            $this->consignments->update($consignment, $request->validated(), $tenantId, $request->user()),
            'Consignment updated',
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId    = $request->user()->tenant_id;
        $consignment = $this->consignments->find($id, $tenantId);

        $this->consignments->delete($consignment, $tenantId, $request->user());

        return $this->success(null, 'Consignment deleted');
    }
}
