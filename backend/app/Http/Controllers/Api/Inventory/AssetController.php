<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\AssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asset register. Internal staff manage assets; deleting one is admin-only
 * (master-data destruction), matching items and warehouses.
 */
class AssetController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private AssetService $assets)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->assets->list($request->user()->tenant_id, $request->only(['status', 'category', 'assigned_to', 'search', 'due'])),
            'Assets retrieved'
        );
    }

    public function show(Request $request, int $asset)
    {
        $this->denyExternal($request);

        return $this->success($this->assets->show($asset, $request->user()->tenant_id), 'Asset retrieved');
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->assets->create($this->validated($request), $request->user()->tenant_id, $request->user()->id),
            'Asset created', 201
        );
    }

    public function update(Request $request, int $asset)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->assets->update($asset, $this->validated($request, false), $request->user()->tenant_id),
            'Asset updated'
        );
    }

    public function destroy(Request $request, int $asset)
    {
        $this->requireAdmin($request, 'delete an asset');
        $this->assets->delete($asset, $request->user()->tenant_id);

        return $this->success(null, 'Asset deleted');
    }

    public function assign(Request $request, int $asset)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'assigned_to' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
        ]);

        return $this->success(
            $this->assets->assign(
                $asset,
                $data['assigned_to'] ?? null,
                $request->user()->tenant_id,
                $request->user()->id,
                array_key_exists('employee_id', $data) ? ['employee_id' => $data['employee_id']] : []
            ),
            'Asset assignment updated'
        );
    }

    /** Lifecycle actions — assign / return / transfer / replace / maintenance / lost / damaged. */
    public function lifecycle(Request $request, int $asset)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'action'      => 'required|in:assign,return,transfer,replace,maintenance,lost,damaged',
            'employee_id' => 'nullable|integer',
            'user_id'     => 'nullable|integer',
            'condition'   => 'nullable|string|max:40',
            'description' => 'nullable|string|max:500',
            'cost'        => 'nullable|numeric|min:0',
            'vendor'      => 'nullable|string|max:120',
            'next_due'    => 'nullable|date',
        ]);

        return $this->success(
            $this->assets->lifecycle($asset, $data['action'], $data, $request->user()->tenant_id, $request->user()->id),
            'Asset updated'
        );
    }

    public function setStatus(Request $request, int $asset)
    {
        $this->denyExternal($request);
        $data = $request->validate(['status' => ['required', Rule::in(\App\Models\Inventory\Asset::STATUSES)]]);

        return $this->success(
            $this->assets->setStatus($asset, $data['status'], $request->user()->tenant_id),
            'Asset status updated'
        );
    }

    public function addEvent(Request $request, int $asset)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'type'         => ['required', Rule::in(\App\Models\Inventory\AssetEvent::TYPES)],
            'description'  => 'nullable|string|max:2000',
            'cost'         => 'nullable|numeric|min:0',
            'vendor'       => 'nullable|string|max:180',
            'next_due'     => 'nullable|date',
            'performed_at' => 'nullable|date',
        ]);

        return $this->success(
            $this->assets->addEvent($asset, $data, $request->user()->tenant_id, $request->user()->id),
            'Event logged', 201
        );
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name'             => ($creating ? 'required' : 'sometimes').'|string|max:180',
            'code'             => 'nullable|string|max:60',
            'category'         => 'nullable|string|max:80',
            'product_id'       => 'nullable|integer',
            'serial_no'        => 'nullable|string|max:120',
            'status'           => ['nullable', Rule::in(\App\Models\Inventory\Asset::STATUSES)],
            'assigned_to'      => 'nullable|integer',
            'warehouse_id'     => 'nullable|integer',
            'location'         => 'nullable|string|max:180',
            'purchase_date'    => 'nullable|date',
            'purchase_cost'    => 'nullable|numeric|min:0',
            'warranty_until'   => 'nullable|date',
            'next_service_due' => 'nullable|date',
            'note'             => 'nullable|string|max:2000',
        ]);
    }
}
