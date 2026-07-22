<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreVoucherRequest;
use App\Models\Accounts\Voucher;
use App\Services\Accounts\PostingService;
use App\Services\Accounts\ReversalService;
use App\Services\Accounts\VoucherService;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function __construct(
        private VoucherService $vouchers,
        private PostingService $posting,
        private ReversalService $reversal,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json($this->vouchers->list($request->user()->tenant_id, $request->only([
            'type', 'status', 'from', 'to', 'search', 'per_page',
        ])));
    }

    public function show(Voucher $voucher, Request $request)
    {
        return response()->json($this->vouchers->show($voucher, $request->user()->tenant_id));
    }

    /** Post a voucher through the single gate (balance-checked, numbered, audited). */
    public function store(StoreVoucherRequest $request)
    {
        $voucher = $this->posting->post($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($voucher, 201);
    }

    /** Cancel a posted voucher by posting a reversing entry (no hard delete). */
    public function cancel(Voucher $voucher, Request $request)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $reversal = $this->reversal->cancel($voucher, $request->user()->tenant_id, $request->user()->id, $data['reason'] ?? null);
        return response()->json(['message' => 'Voucher cancelled', 'reversal' => $reversal]);
    }
}
