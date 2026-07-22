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

    public function store(Request $request)
    {
        $data = $request->validate([
            'vendor_name'        => 'required|string|max:255',
            'bill_number'        => 'nullable|string|max:100',
            'bill_date'          => 'required|date',
            'due_date'           => 'required|date|after_or_equal:bill_date',
            'amount'             => 'required|numeric|gt:0',
            'expense_ledger_id'  => 'required|integer|exists:acc_ledgers,id',
            'note'               => 'nullable|string',
        ]);

        $bill = $this->bills->create($data, $request->user()->tenant_id, $request->user()->id);

        return response()->json($bill, 201);
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
}
