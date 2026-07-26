<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\BillCategory;
use Illuminate\Http\Request;

/**
 * Bill category master. Lazy-seeded with a common starter set on first read
 * (same approach as expense categories / tax rates) so the New Bill category
 * dropdown is usable immediately.
 */
class BillCategoryController extends Controller
{
    private const DEFAULTS = ['Utilities', 'Rent', 'Office Supplies', 'Professional Fees', 'Travel', 'Marketing'];

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        if (! BillCategory::forTenant($tenantId)->exists()) {
            foreach (self::DEFAULTS as $name) {
                BillCategory::firstOrCreate(['tenant_id' => $tenantId, 'name' => $name], ['active' => true]);
            }
        }

        return response()->json(BillCategory::forTenant($tenantId)->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'active' => 'nullable|boolean']);
        $tenantId = $request->user()->tenant_id;

        abort_if(BillCategory::forTenant($tenantId)->where('name', $data['name'])->exists(), 422, 'Category already exists.');

        return response()->json(BillCategory::create([...$data, 'tenant_id' => $tenantId]), 201);
    }

    public function update(Request $request, BillCategory $billCategory)
    {
        abort_if($billCategory->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['name' => 'sometimes|string|max:100', 'active' => 'sometimes|boolean']);
        $billCategory->update($data);

        return response()->json($billCategory);
    }

    public function destroy(Request $request, BillCategory $billCategory)
    {
        abort_if($billCategory->tenant_id !== $request->user()->tenant_id, 404);
        $billCategory->delete();

        return response()->json(['message' => 'Category deleted']);
    }
}
