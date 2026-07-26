<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\Location;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\LayoutService;
use App\Services\Inventory\WarehouseEnvService;
use App\Services\Inventory\WarehouseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(
        private WarehouseService $warehouses,
        private LayoutService $layout,
        private WarehouseEnvService $env,
    ) {
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
            // A capacity with no unit is not a capacity — 500 washers and 500
            // engines do not fit the same shelf.
            'capacity_uom' => ['nullable', Rule::in(Location::CAPACITY_UOMS)],
        ]);

        return $this->success($this->warehouses->createLocation($warehouse, $data, $tenantId), 'Location created', 201);
    }

    /**
     * The site as a building: what is in each bin, how full it is, and what is
     * wrong with the picture. `measure` decides what "full" counts.
     */
    public function layout(Request $request, int $warehouse)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->layout->forWarehouse($warehouse, $request->user()->tenant_id, $request->query('measure', 'units')),
            'Layout retrieved'
        );
    }

    /** Where should this go? Every option says which rule produced it. */
    public function putaway(Request $request, int $warehouse)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'quantity'   => 'required|numeric|min:0',
            'measure'    => ['nullable', Rule::in(Location::CAPACITY_UOMS)],
        ]);

        return $this->success(
            $this->layout->suggestPutaway(
                (int) $data['product_id'], (float) $data['quantity'], $warehouse,
                $request->user()->tenant_id, $data['measure'] ?? 'units',
            ),
            'Put-away suggestions retrieved'
        );
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

            // Environment band + field-audit switches.
            'temp_min'      => 'nullable|numeric|between:-100,200',
            'temp_max'      => 'nullable|numeric|between:-100,200',
            'humidity_min'  => 'nullable|numeric|between:0,100',
            'humidity_max'  => 'nullable|numeric|between:0,100',
            'track_environment'  => 'nullable|boolean',
            'require_move_gps'   => 'nullable|boolean',
            'require_move_photo' => 'nullable|boolean',
        ];
    }

    /* ── Environment monitoring ─────────────────────────────────── */

    public function envReadings(Request $request, int $warehouse)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->env->readings($warehouse, $request->user()->tenant_id),
            'Environment readings retrieved'
        );
    }

    public function storeEnvReading(Request $request, int $warehouse)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'temperature' => 'nullable|numeric|between:-100,200',
            'humidity'    => 'nullable|numeric|between:0,100',
            'note'        => 'nullable|string|max:255',
        ]);

        if (($data['temperature'] ?? null) === null && ($data['humidity'] ?? null) === null) {
            return $this->error('Record a temperature or a humidity reading.', 422);
        }

        return $this->success(
            $this->env->record($warehouse, $data, $request->user()->tenant_id, $request->user()->id),
            'Environment reading logged', 201
        );
    }
}
