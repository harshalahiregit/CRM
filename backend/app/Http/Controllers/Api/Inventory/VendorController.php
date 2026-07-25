<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\VendorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vendor master. Internal staff manage vendors; deleting one (master-data
 * destruction) is admin-only, matching items and warehouses.
 */
class VendorController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private VendorService $vendors)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->vendors->list($request->user()->tenant_id, $request->only(['status', 'search'])),
            'Vendors retrieved'
        );
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->vendors->create($this->validated($request), $request->user()->tenant_id, $request->user()->id),
            'Vendor created', 201
        );
    }

    public function update(Request $request, int $vendor)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->vendors->update($vendor, $this->validated($request, false), $request->user()->tenant_id),
            'Vendor updated'
        );
    }

    public function destroy(Request $request, int $vendor)
    {
        $this->requireAdmin($request, 'delete a vendor');
        $this->vendors->delete($vendor, $request->user()->tenant_id);

        return $this->success(null, 'Vendor deleted');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name'           => ($creating ? 'required' : 'sometimes').'|string|max:180',
            'code'           => 'nullable|string|max:60',
            'email'          => 'nullable|email|max:180',
            'phone'          => 'nullable|string|max:40',
            'gstin'          => 'nullable|string|max:20',
            'address'        => 'nullable|string|max:255',
            'city'           => 'nullable|string|max:80',
            'state'          => 'nullable|string|max:80',
            'country'        => 'nullable|string|max:80',
            'payment_terms'  => 'nullable|string|max:80',
            'lead_time_days' => 'nullable|integer|min:0|max:3650',
            'status'         => ['nullable', Rule::in(['active', 'inactive'])],
            'note'           => 'nullable|string|max:2000',
        ]);
    }
}
