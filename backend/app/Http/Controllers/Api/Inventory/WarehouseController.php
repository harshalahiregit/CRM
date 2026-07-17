<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\Location;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\WarehouseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private WarehouseService $warehouses)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->warehouses->list($request->user()->tenant_id), 'Warehouses retrieved');
    }

    public function show(Request $request, int $warehouse)
    {
        $this->denyExternal($request);

        return $this->success($this->warehouses->show($warehouse, $request->user()->tenant_id), 'Warehouse retrieved');
    }

    public function store(Request $request)
    {
        $this->requireAdmin($request, 'create a warehouse');
        $data = $request->validate($this->rules($request));

        return $this->success($this->warehouses->create($data, $request->user()->tenant_id), 'Warehouse created', 201);
    }

    public function update(Request $request, int $warehouse)
    {
        $this->requireAdmin($request, 'change a warehouse');
        $data = $request->validate($this->rules($request));

        return $this->success($this->warehouses->update($warehouse, $data, $request->user()->tenant_id), 'Warehouse updated');
    }

    public function destroy(Request $request, int $warehouse)
    {
        $this->requireAdmin($request, 'delete a warehouse');
        $this->warehouses->delete($warehouse, $request->user()->tenant_id);

        return $this->success(null, 'Warehouse deleted');
    }

    /* ── Bin locations ──────────────────────────────────────────── */

    public function locations(Request $request, int $warehouse)
    {
        $this->denyExternal($request);

        return $this->success($this->warehouses->locationTree($warehouse, $request->user()->tenant_id), 'Locations retrieved');
    }

    public function storeLocation(Request $request, int $warehouse)
    {
        $this->requireAdmin($request, 'add a location');
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name'      => 'required|string|max:120',
            'code'      => 'nullable|string|max:60',
            'type'      => ['required', Rule::in(Location::TYPES)],
            'parent_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')->where('tenant_id', $tenantId)],
            'capacity'  => 'nullable|numeric|min:0',
        ]);

        return $this->success($this->warehouses->createLocation($warehouse, $data, $tenantId), 'Location created', 201);
    }

    public function destroyLocation(Request $request, int $warehouse, int $location)
    {
        $this->requireAdmin($request, 'delete a location');
        $this->warehouses->deleteLocation($location, $request->user()->tenant_id);

        return $this->success(null, 'Location deleted');
    }

    private function rules(Request $request): array
    {
        return [
            'name'       => 'required|string|max:150',
            'code'       => 'nullable|string|max:40',
            'type'       => ['required', Rule::in(Warehouse::TYPES)],
            'order'      => 'nullable|integer|min:0',
            'address'    => 'nullable|string|max:255',
            'city'       => 'nullable|string|max:100',
            'state'      => 'nullable|string|max:100',
            'zip_code'   => 'nullable|string|max:20',
            'country'    => 'nullable|string|max:100',
            'note'       => 'nullable|string|max:255',
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'is_default' => 'nullable|boolean',
            'display'    => 'nullable|boolean',
            'status'     => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
