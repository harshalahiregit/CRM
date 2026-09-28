<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\StoreTransportOrderRequest;
use App\Http\Requests\Transport\TransitionTransportOrderRequest;
use App\Http\Requests\Transport\UpdateTransportOrderRequest;
use App\Services\Transport\TransportAuditLogger;
use App\Services\Transport\TransportOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transport Orders (SNG-TRN-006).
 *
 * Thin: validate through a FormRequest, call the service, return through the
 * shared ApiResponse envelope. No queries and no business logic here.
 *
 * Tenant scoping is never re-implemented per method — every lookup goes through
 * TransportOrderService::find(), which filters by tenant AT the point of the
 * lookup and raises UnauthorizedTenantException otherwise. Route-model binding
 * is deliberately not used, because it would resolve a cross-tenant id first and
 * leave the check to be remembered afterwards.
 */
class TransportOrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TransportOrderService $orders,
        private TransportAuditLogger $audit,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'      => 'nullable|string|max:40',
            'priority'    => 'nullable|string|max:20',
            'customer_id' => 'nullable|integer',
            'search'      => 'nullable|string|max:120',
            'per_page'    => 'nullable|integer|min:1|max:200',
        ]);

        return $this->success(
            $this->orders->list($request->user()->tenant_id, $filters),
            'Transport orders retrieved'
        );
    }

    /** Counts per status, for the list's filter chips. */
    public function statusCounts(Request $request): JsonResponse
    {
        return $this->success(
            $this->orders->statusCounts($request->user()->tenant_id),
            'Order status counts retrieved'
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $order    = $this->orders->find($id, $tenantId);

        return $this->success([
            'order' => $order->load('customer:id,company', 'trips:id,order_id,trip_number,status,approved_freight,currency'),
            // The detail page shows who did what and when — the audit trail is
            // the record, so it is read from it rather than re-derived.
            'audit' => $this->audit->forSubject($order, $tenantId),
        ], 'Transport order retrieved');
    }

    public function store(StoreTransportOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create(
            $request->validated(),
            $request->user()->tenant_id,
            $request->user()
        );

        return $this->success($order, 'Transport order created', 201);
    }

    public function update(UpdateTransportOrderRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $order    = $this->orders->find($id, $tenantId);

        return $this->success(
            $this->orders->update($order, $request->validated(), $tenantId, $request->user()),
            'Transport order updated'
        );
    }

    /** Move the order through SM-ORD (submit / approve / reject / return to draft). */
    public function transition(TransitionTransportOrderRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $order    = $this->orders->find($id, $tenantId);
        $data     = $request->validated();

        return $this->success(
            $this->orders->transition($order, $data['status'], $tenantId, $request->user(), $data['reason'] ?? null),
            'Transport order status updated'
        );
    }
}
