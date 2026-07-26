<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreChequeRequest;
use App\Models\Accounts\Cheque;
use App\Services\Accounts\ChequeService;
use Illuminate\Http\Request;

class ChequeController extends Controller
{
    public function __construct(private ChequeService $cheques)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->cheques->list($request->user()->tenant_id, $request->only([
            'direction', 'status', 'is_pdc', 'search', 'per_page', 'bank_account_id',
        ])));
    }

    public function summary(Request $request)
    {
        return response()->json($this->cheques->summary($request->user()->tenant_id));
    }

    /** PDCs whose due date has arrived. */
    public function due(Request $request)
    {
        return response()->json($this->cheques->duePdcs($request->user()->tenant_id, $request->query('as_of')));
    }

    public function store(StoreChequeRequest $request)
    {
        $cheque = $this->cheques->create($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($cheque, 201);
    }

    public function update(StoreChequeRequest $request, Cheque $cheque)
    {
        $updated = $this->cheques->update($cheque, $request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    public function changeStatus(Cheque $cheque, Request $request)
    {
        $data = $request->validate([
            'status'       => 'required|in:issued,received,deposited,presented,cleared,bounced,cancelled,post_dated',
            'cleared_date' => 'nullable|date',
        ]);
        $updated = $this->cheques->changeStatus($cheque, $data['status'], $data['cleared_date'] ?? null, $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    public function destroy(Cheque $cheque, Request $request)
    {
        $this->cheques->delete($cheque, $request->user()->tenant_id, $request->user()->id);
        return response()->json(['message' => 'Cheque deleted']);
    }
}
