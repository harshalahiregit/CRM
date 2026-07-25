<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;

/**
 * Expense category master (Settings → Expense Categories, meeting 4.2).
 *
 * Seeded lazily with a common starter set on first read — same approach as tax
 * rates — so the New Expense category dropdown is usable immediately instead of
 * appearing empty/broken before anyone visits Settings. Tenants can rename or
 * deactivate these and add their own.
 */
class ExpenseCategoryController extends Controller
{
    private const DEFAULT_CATEGORIES = [
        'Travel', 'Project Expenses', 'Vendor Payments', 'Office Supplies',
    ];

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        if (! ExpenseCategory::forTenant($tenantId)->exists()) {
            foreach (self::DEFAULT_CATEGORIES as $name) {
                ExpenseCategory::firstOrCreate(
                    ['tenant_id' => $tenantId, 'name' => $name],
                    ['active' => true],
                );
            }
        }

        return response()->json(
            ExpenseCategory::forTenant($tenantId)->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'active' => 'nullable|boolean']);
        $tenantId = $request->user()->tenant_id;

        abort_if(ExpenseCategory::forTenant($tenantId)->where('name', $data['name'])->exists(), 422, 'Category already exists.');

        return response()->json(ExpenseCategory::create([...$data, 'tenant_id' => $tenantId]), 201);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory)
    {
        abort_if($expenseCategory->tenant_id !== $request->user()->tenant_id, 404);
        $data = $request->validate(['name' => 'required|string|max:100', 'active' => 'nullable|boolean']);
        abort_if(
            ExpenseCategory::forTenant($expenseCategory->tenant_id)->where('name', $data['name'])->where('id', '!=', $expenseCategory->id)->exists(),
            422, 'Category already exists.',
        );
        $expenseCategory->update($data);

        return response()->json($expenseCategory->fresh());
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory)
    {
        abort_if($expenseCategory->tenant_id !== $request->user()->tenant_id, 404);
        $expenseCategory->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
