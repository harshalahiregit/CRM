<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\TransferCategory;
use App\Models\Accounts\Voucher;
use App\Services\Accounts\TransferCategoryService;
use App\Services\Accounts\TransferService;
use Illuminate\Http\Request;

class TransferController extends Controller
{
    public function __construct(
        private TransferService $transfers,
        private TransferCategoryService $categories,
    ) {
    }

    /** Every account the user can transfer to/from, grouped: own / client / vendor / tpv. */
    public function accounts(Request $request)
    {
        return response()->json($this->transfers->pickableAccounts($request->user()->tenant_id));
    }

    public function history(Request $request)
    {
        return response()->json($this->transfers->history($request->user()->tenant_id, $request->only([
            'category_id', 'from', 'to', 'per_page',
        ])));
    }

    /** Reverse a fund transfer (posts a neutralising entry; original stays in the books). */
    public function cancel(Voucher $voucher, Request $request)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $reversal = $this->transfers->cancel($voucher, $request->user()->tenant_id, $request->user()->id, $data['reason'] ?? null);
        return response()->json(['message' => 'Transfer reversed', 'reversal' => $reversal]);
    }

    /* ── Category / Head master ──────────────────────────────────────── */

    public function categories(Request $request)
    {
        return response()->json($this->categories->list($request->user()->tenant_id));
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);
        return response()->json($this->categories->create($data, $request->user()->tenant_id), 201);
    }

    public function updateCategory(TransferCategory $category, Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
        ]);
        return response()->json($this->categories->update($category, $data, $request->user()->tenant_id));
    }

    public function destroyCategory(TransferCategory $category, Request $request)
    {
        $this->categories->delete($category, $request->user()->tenant_id);
        return response()->json(['message' => 'Category deleted']);
    }
}
