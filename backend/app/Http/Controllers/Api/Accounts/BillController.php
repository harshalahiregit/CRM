<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\Bill;
use App\Services\Accounts\BillService;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function __construct(private BillService $bills)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->bills->list($request->user()->tenant_id, $request->only(['status', 'vendor', 'per_page']))
        );
    }

    public function show(Bill $bill, Request $request)
    {
        if ($bill->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(
            $bill->load('voucher:id,number,date,status', 'paidVoucher:id,number,date')
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_name'        => 'required|string|max:255',
            // vendor_ledger_id: optional hook for the future Vendor module. When
            // supplied, BillService skips the auto-create and uses this ledger.
            'vendor_ledger_id'   => 'nullable|integer|exists:acc_ledgers,id',
            'bill_number'        => 'nullable|string|max:100',
            'bill_date'          => 'required|date',
            'due_date'           => 'required|date|after_or_equal:bill_date',
            'amount'             => 'required|numeric|gt:0',
            'expense_ledger_id'  => 'required|integer|exists:acc_ledgers,id',
            'note'               => 'nullable|string',
            'attachment'         => 'nullable|string|max:1500000',
            'attachment_name'    => 'nullable|string|max:255',
            'is_recurring'       => 'nullable|boolean',
            'recurring_type'     => 'nullable|required_if:is_recurring,true|in:week,month,year',
            'recurring_every'    => 'nullable|integer|min:1|max:52',
            'recurring_cycles'   => 'nullable|integer|min:1|max:999',
        ]);

        $bill = $this->bills->create($data, $request->user()->tenant_id, $request->user()->id);

        return response()->json($bill, 201);
    }

    public function approve(Bill $bill, Request $request)
    {
        return response()->json(
            $this->bills->approve($bill, $request->user()->tenant_id, $request->user()->id)
        );
    }

    public function generateNextRecurrence(Bill $bill, Request $request)
    {
        $next = $this->bills->generateNextRecurrence($bill, $request->user()->tenant_id, $request->user()->id);

        return response()->json($next, 201);
    }

    public function pay(Bill $bill, Request $request)
    {
        $data = $request->validate([
            'bank_ledger_id' => 'required|integer|exists:acc_ledgers,id',
            'paid_date'      => 'required|date',
        ]);

        return response()->json(
            $this->bills->pay($bill, $data, $request->user()->tenant_id, $request->user()->id)
        );
    }

    public function destroy(Bill $bill, Request $request)
    {
        return response()->json(
            $this->bills->delete($bill, $request->user()->tenant_id)
        );
    }
}
