<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Tpv\TpvPpeRequirement;
use App\Models\Tpv\TpvWorker;
use App\Services\Tpv\PpeInventoryService;
use Illuminate\Http\Request;

/**
 * The PPE requirement matrix — role → required PPE.
 *
 * Admin-configurable, so PPE rules change without a deploy. The rules point at
 * Inventory products and hold no product or stock detail of their own.
 */
class PpeRequirementController extends Controller
{
    public function __construct(private PpeInventoryService $ppe)
    {
    }

    /** The matrix, grouped by role, plus the vocabulary the editor needs. */
    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $rules = TpvPpeRequirement::query()
            ->where('tenant_id', $tenantId)
            ->with('product:id,name,sku,status')
            ->orderBy('scope_type')->orderBy('scope_value')
            ->get()
            ->map(fn (TpvPpeRequirement $r) => [
                'id'          => $r->id,
                'scope_type'  => $r->scope_type,
                'scope_value' => $r->scope_value,
                'product_id'  => $r->product_id,
                'product'     => $r->product?->name,
                'sku'         => $r->product?->sku,
                'qty'         => $r->qty,
                'is_active'   => $r->is_active,
            ]);

        return response()->json([
            'rules'  => $rules,
            'scopes' => TpvPpeRequirement::SCOPES,
            // Roles actually in use, so the editor offers real values rather than
            // a free-text box that silently never matches anybody.
            'roles'  => [
                'designation'    => TpvWorker::where('tenant_id', $tenantId)->whereNotNull('designation')
                                        ->distinct()->orderBy('designation')->pluck('designation'),
                'skill_category' => TpvWorker::where('tenant_id', $tenantId)->whereNotNull('skill_category')
                                        ->distinct()->orderBy('skill_category')->pluck('skill_category'),
            ],
            // The PPE items available to require — straight from Inventory.
            'items'  => $this->ppe->catalogue($tenantId)
                            ->map(fn ($i) => ['product_id' => $i['product_id'], 'name' => $i['name'], 'sku' => $i['sku']])
                            ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertAdmin($request);
        $tenantId = (int) $request->user()->tenant_id;
        $data = $this->validated($request);

        // The product must be a real PPE item in this tenant.
        abort_unless(
            Product::forTenant($tenantId)->whereKey($data['product_id'])->exists(),
            422,
            'That PPE item does not exist in Inventory.'
        );

        $rule = TpvPpeRequirement::updateOrCreate(
            [
                'tenant_id'   => $tenantId,
                'scope_type'  => $data['scope_type'],
                'scope_value' => $data['scope_type'] === 'all' ? null : $data['scope_value'],
                'product_id'  => $data['product_id'],
            ],
            [
                'qty'        => $data['qty'] ?? 1,
                'is_active'  => $data['is_active'] ?? true,
                'created_by' => $request->user()->id,
            ]
        );

        return response()->json($rule->load('product:id,name,sku'), 201);
    }

    public function update(Request $request, TpvPpeRequirement $requirement)
    {
        $this->assertAdmin($request);
        $this->assertTenant($request, $requirement);

        $requirement->update($request->validate([
            'qty'       => 'sometimes|integer|min:1',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json($requirement->load('product:id,name,sku'));
    }

    public function destroy(Request $request, TpvPpeRequirement $requirement)
    {
        $this->assertAdmin($request);
        $this->assertTenant($request, $requirement);

        $requirement->delete();

        return response()->json(['status' => 'success']);
    }

    /** One worker's ✓/✗ list against their role's requirements. */
    public function worker(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->ppe->complianceFor($worker));
    }

    /** Tenant-wide compliance for the dashboard. */
    public function summary(Request $request)
    {
        return response()->json($this->ppe->complianceSummary((int) $request->user()->tenant_id));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'scope_type'  => 'required|in:'.implode(',', array_keys(TpvPpeRequirement::SCOPES)),
            'scope_value' => 'required_unless:scope_type,all|nullable|string|max:120',
            'product_id'  => 'required|integer|min:1',
            'qty'         => 'nullable|integer|min:1',
            'is_active'   => 'nullable|boolean',
        ]);
    }

    /** Changing what PPE is legally required is an admin decision. */
    private function assertAdmin(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403, 'Only an admin can change PPE requirements.');
    }

    private function assertTenant(Request $request, $model): void
    {
        abort_unless((int) $model->tenant_id === (int) $request->user()->tenant_id, 404, 'Not found');
    }
}
