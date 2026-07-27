<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Purchase\PurchaseVendorCategory;
use App\Services\Purchase\PurchaseVendorCategoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Settings → Vendor category. Purchase-owned master feeding the Vendor Category
 * dropdown. Reads are open to admin/staff; writes are admin-only (route group).
 */
class PurchaseVendorCategoryController extends Controller
{
    public function __construct(private PurchaseVendorCategoryService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->service->list($request->user()->tenant_id));
    }

    public function store(Request $request)
    {
        $data = $this->rules($request);

        return response()->json($this->service->create($data, $request->user()), 201);
    }

    public function update(Request $request, PurchaseVendorCategory $vendorCategory)
    {
        $this->assertTenant($request, $vendorCategory);
        $data = $this->rules($request, $vendorCategory->id);

        return response()->json($this->service->update($vendorCategory, $data, $request->user()));
    }

    public function destroy(Request $request, PurchaseVendorCategory $vendorCategory)
    {
        $this->assertTenant($request, $vendorCategory);
        $this->service->delete($vendorCategory, $request->user());

        return response()->json(['message' => 'Category deleted']);
    }

    private function rules(Request $request, ?int $ignoreId = null): array
    {
        $tenantId = $request->user()->tenant_id;

        return $request->validate([
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('purchase_vendor_categories', 'name')
                    ->where('tenant_id', $tenantId)->whereNull('deleted_at')
                    ->ignore($ignoreId),
            ],
            'description' => 'nullable|string|max:2000',
        ]);
    }

    private function assertTenant(Request $request, PurchaseVendorCategory $category): void
    {
        abort_unless((int) $category->tenant_id === (int) $request->user()->tenant_id, 404, 'Category not found');
    }
}
