<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\VmiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vendor-managed inventory agreements. Internal staff manage; delete admin-only. */
class VmiController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private VmiService $vmi)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->vmi->list($request->user()->tenant_id, $request->only(['status', 'vendor_id'])), 'VMI agreements retrieved');
    }

    public function show(Request $request, int $agreement)
    {
        $this->denyExternal($request);

        return $this->success($this->vmi->show($agreement, $request->user()->tenant_id), 'VMI agreement retrieved');
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->vmi->create($this->validated($request), $request->user()->tenant_id, $request->user()->id), 'VMI agreement created', 201);
    }

    public function update(Request $request, int $agreement)
    {
        $this->denyExternal($request);

        return $this->success($this->vmi->update($agreement, $this->validated($request, false), $request->user()->tenant_id), 'VMI agreement updated');
    }

    public function destroy(Request $request, int $agreement)
    {
        $this->requireAdmin($request, 'delete a VMI agreement');
        $this->vmi->delete($agreement, $request->user()->tenant_id);

        return $this->success(null, 'VMI agreement deleted');
    }

    public function suggestions(Request $request, int $agreement)
    {
        $this->denyExternal($request);

        return $this->success($this->vmi->suggestions($agreement, $request->user()->tenant_id), 'Replenishment suggestions computed');
    }

    public function generatePurchaseOrder(Request $request, int $agreement)
    {
        $this->denyExternal($request);
        $result = $this->vmi->generatePurchaseOrder($agreement, $request->user()->tenant_id, $request->user()->id);

        return $this->success($result, $result['message']);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'vendor_id'        => ($creating ? 'required' : 'sometimes').'|integer',
            'warehouse_id'     => 'nullable|integer',
            'name'             => 'nullable|string|max:180',
            'status'           => ['nullable', Rule::in(['active', 'paused'])],
            'review_frequency' => ['nullable', Rule::in(['weekly', 'fortnightly', 'monthly'])],
            'note'             => 'nullable|string|max:2000',
            'items'                 => 'sometimes|array',
            'items.*.product_id'    => 'required_with:items|integer',
            'items.*.min_level'     => 'nullable|numeric|min:0',
            'items.*.max_level'     => 'nullable|numeric|min:0',
        ]);
    }
}
