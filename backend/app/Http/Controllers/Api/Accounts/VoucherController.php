<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreVoucherRequest;
use App\Models\Accounts\Voucher;
use App\Services\Accounts\PostingService;
use App\Services\Accounts\ReversalService;
use App\Services\Accounts\TransferService;
use App\Services\Accounts\VoucherService;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function __construct(
        private VoucherService $vouchers,
        private PostingService $posting,
        private ReversalService $reversal,
        private TransferService $transfers,
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

    /**
     * Fund transfer — the friendly front door onto the ledger for moving money
     * between any two accounts (own, client, vendor, or third-party-vendor).
     * The correct voucher type (Contra/Payment/Receipt) is chosen automatically
     * by TransferService based on the two accounts involved; the caller never
     * needs to think about it. Kept at this URL for frontend compatibility.
     */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'from_ledger_id' => 'required|integer|different:to_ledger_id|exists:acc_ledgers,id',
            'to_ledger_id'   => 'required|integer|exists:acc_ledgers,id',
            'date'           => 'required|date',
            'amount'         => 'required|numeric|gt:0',
            'narration'      => 'nullable|string|max:255',
            'transfer_category_id' => 'nullable|integer|exists:acc_transfer_categories,id',
        ]);

        $voucher = $this->transfers->transfer($data, $request->user()->tenant_id, $request->user()->id);

        return response()->json($voucher, 201);
    }
}
