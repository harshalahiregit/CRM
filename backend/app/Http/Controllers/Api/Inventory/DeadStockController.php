<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\DeadStockService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Dead-stock action workflow. Internal staff triage dead stock; recording a
 * decision is an operational act, so any storekeeper may. Deleting the record is
 * admin-only (it's an audit trail of what was decided).
 */
class DeadStockController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private DeadStockService $service)
    {
    }

    /** Items that are dead and don't yet have an open action. */
    public function candidates(Request $request)
    {
        $this->denyExternal($request);
        $days = (int) $request->integer('days', 90);

        return $this->success(
            $this->service->candidates($request->user()->tenant_id, $days > 0 ? $days : 90),
            'Dead-stock candidates retrieved'
        );
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->service->list($request->user()->tenant_id, $request->only(['status', 'action'])),
            'Dead-stock actions retrieved'
        );
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'product_id'       => 'required|integer',
            'action'           => ['required', Rule::in(['discount', 'liquidate', 'transfer', 'write_off', 'dismiss'])],
            'qty'              => 'nullable|numeric|min:0',
            'warehouse_id'     => 'nullable|integer',
            'to_warehouse_id'  => 'nullable|integer|required_if:action,transfer',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'new_price'        => 'nullable|numeric|min:0',
            'apply_now'        => 'nullable|boolean',
            'note'             => 'nullable|string|max:2000',
            'assigned_to'      => 'nullable|integer',
        ]);

        return $this->success(
            $this->service->create($data, $request->user()->tenant_id, $request->user()->id),
            'Dead-stock action recorded', 201
        );
    }

    public function updateStatus(Request $request, int $id)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'in_progress', 'done', 'cancelled'])],
        ]);

        return $this->success(
            $this->service->updateStatus($id, $data['status'], $request->user()->tenant_id, $request->user()->id),
            'Dead-stock action updated'
        );
    }

    public function destroy(Request $request, int $id)
    {
        $this->requireAdmin($request, 'delete a dead-stock action');
        $this->service->delete($id, $request->user()->tenant_id);

        return $this->success(null, 'Dead-stock action deleted');
    }
}
