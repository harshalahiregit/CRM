<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Inventory\Product;
use App\Models\Purchase\PurchasePpeRequirement;
use App\Models\Purchase\PurchaseWorker;
use App\Services\Purchase\PurchasePpeService;
use Illuminate\Http\Request;

/**
 * The Purchase PPE requirement matrix — role → required PPE.
 *
 * Purchase had no matrix at all, so its PPE gate was the weakest rule its data
 * supported: holding ANY one item counted as equipped. With this, the badge and
 * the site gate can name the mandatory items a worker is short of, exactly as
 * TPV does.
 *
 * Admin-configurable, so PPE rules change without a deploy. The rules point at
 * Inventory products and hold no product or stock detail of their own.
 *
 * @see \App\Http\Controllers\Api\Tpv\PpeRequirementController
 */
class PurchasePpeRequirementController extends Controller
{
    public function __construct(private PurchasePpeService $ppe) {}

    /** The matrix, plus the vocabulary the editor needs. */
    public function index(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $rules = PurchasePpeRequirement::query()
            ->where('tenant_id', $tenantId)
            ->with('product:id,name,sku,status')
            ->orderBy('scope_type')->orderBy('scope_value')
            ->get()
            ->map(fn (PurchasePpeRequirement $r) => [
                'id' => $r->id,
                'scope_type' => $r->scope_type,
                'scope_value' => $r->scope_value,
                'hazard' => $r->hazard,
                'activity' => $r->activity,
                'ppe_class' => $r->ppe_class ?? 'mandatory',
                'condition' => $r->condition,
                'product_id' => $r->product_id,
                'product' => $r->product?->name,
                'sku' => $r->product?->sku,
                'qty' => $r->qty,
                'replacement_frequency_days' => $r->replacement_frequency_days,
                'verification_required' => (bool) $r->verification_required,
                'is_active' => $r->is_active,
            ]);

        return response()->json([
            'rules' => $rules,
            'scopes' => PurchasePpeRequirement::SCOPES,
            'classes' => PurchasePpeRequirement::CLASSES,
            // Roles actually in use, so the editor offers real values rather than
            // a free-text box that silently never matches anybody.
            'roles' => [
                'designation' => PurchaseWorker::where('tenant_id', $tenantId)->whereNotNull('designation')
                    ->distinct()->orderBy('designation')->pluck('designation'),
                'skill_category' => PurchaseWorker::where('tenant_id', $tenantId)->whereNotNull('skill_category')
                    ->distinct()->orderBy('skill_category')->pluck('skill_category'),
            ],
            // The PPE items available to require — straight from Inventory.
            'items' => $this->ppe->catalogue($tenantId)
                ->map(fn ($i) => ['product_id' => $i['product_id'], 'name' => $i['name'], 'sku' => $i['sku']])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertAdmin($request);
        $tenantId = (int) $request->user()->tenant_id;
        $data = $this->validated($request);

        abort_unless(
            Product::forTenant($tenantId)->whereKey($data['product_id'])->exists(),
            422,
            'That PPE item does not exist in Inventory.'
        );

        // Keyed on scope + product, so saving the same rule twice edits it rather
        // than raising a duplicate the matrix would then ask for twice.
        $rule = PurchasePpeRequirement::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'scope_type' => $data['scope_type'],
                'scope_value' => $data['scope_type'] === 'all' ? null : $data['scope_value'],
                'product_id' => $data['product_id'],
            ],
            [
                'hazard' => $data['hazard'] ?? null,
                'activity' => $data['activity'] ?? null,
                'ppe_class' => $data['ppe_class'] ?? 'mandatory',
                'condition' => $data['condition'] ?? null,
                'qty' => $data['qty'] ?? 1,
                'replacement_frequency_days' => $data['replacement_frequency_days'] ?? null,
                'verification_required' => $data['verification_required'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $request->user()->id,
            ]
        );

        return response()->json($rule->load('product:id,name,sku'), 201);
    }

    public function update(Request $request, PurchasePpeRequirement $requirement)
    {
        $this->assertAdmin($request);
        $this->assertTenant($request, $requirement);

        $requirement->update($request->validate([
            'hazard' => 'sometimes|nullable|string|max:120',
            'activity' => 'sometimes|nullable|string|max:120',
            'ppe_class' => 'sometimes|in:'.implode(',', PurchasePpeRequirement::CLASSES),
            'condition' => 'sometimes|nullable|string|max:200',
            'qty' => 'sometimes|integer|min:1',
            'replacement_frequency_days' => 'sometimes|nullable|integer|min:1',
            'verification_required' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]));

        return response()->json($requirement->load('product:id,name,sku'));
    }

    public function destroy(Request $request, PurchasePpeRequirement $requirement)
    {
        $this->assertAdmin($request);
        $this->assertTenant($request, $requirement);

        $requirement->delete();

        return response()->json(['status' => 'success']);
    }

    /** One worker's ✓/✗ list against their role's requirements. */
    public function worker(Request $request, PurchaseWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json($this->ppe->complianceFor($worker));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'scope_type' => 'required|in:'.implode(',', array_keys(PurchasePpeRequirement::SCOPES)),
            'scope_value' => 'required_unless:scope_type,all|nullable|string|max:120',
            'hazard' => 'nullable|string|max:120',
            'activity' => 'nullable|string|max:120',
            'ppe_class' => 'nullable|in:'.implode(',', PurchasePpeRequirement::CLASSES),
            'condition' => 'nullable|string|max:200',
            'product_id' => 'required|integer|min:1',
            'qty' => 'nullable|integer|min:1',
            'replacement_frequency_days' => 'nullable|integer|min:1',
            'verification_required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
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
