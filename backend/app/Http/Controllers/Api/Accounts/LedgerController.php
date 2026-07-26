<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreLedgerRequest;
use App\Http\Requests\Accounts\UpdateLedgerRequest;
use App\Models\Accounts\Ledger;
use App\Services\Accounts\ChartOfAccountsService;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __construct(private ChartOfAccountsService $coa)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->coa->ledgers($request->user()->tenant_id, $request->only([
            'search', 'group_id', 'is_active', 'per_page',
        ])));
    }

    /** Flat active-ledger list for voucher-entry dropdowns. */
    public function options(Request $request)
    {
        return response()->json($this->coa->ledgerOptions($request->user()->tenant_id));
    }

    public function store(StoreLedgerRequest $request)
    {
        $ledger = $this->coa->createLedger($request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($ledger, 201);
    }

    public function update(UpdateLedgerRequest $request, Ledger $ledger)
    {
        $updated = $this->coa->updateLedger($ledger, $request->validated(), $request->user()->tenant_id, $request->user()->id);
        return response()->json($updated);
    }

    public function destroy(Ledger $ledger, Request $request)
    {
        $outcome = $this->coa->deleteLedger($ledger, $request->user()->tenant_id, $request->user()->id);
        return response()->json(['message' => "Ledger {$outcome}", 'outcome' => $outcome]);
    }
}
