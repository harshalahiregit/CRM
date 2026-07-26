<?php

namespace App\Http\Controllers\Api\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Accounts\Reconciliation;
use App\Services\Accounts\ReconciliationService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(private ReconciliationService $recon)
    {
    }

    /** Reconciliation history for a bank account. */
    public function index(Request $request, int $bankAccount)
    {
        return response()->json($this->recon->history($request->user()->tenant_id, $bankAccount));
    }

    /** Start a new reconciliation. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'bank_account_id'   => 'required|integer',
            'statement_date'    => 'required|date',
            'statement_balance' => 'required|numeric',
        ]);

        $rec = $this->recon->start(
            (int) $data['bank_account_id'], $data['statement_date'], (float) $data['statement_balance'],
            $request->user()->tenant_id, $request->user()->id,
        );

        return response()->json($rec, 201);
    }

    /** The reconcile workspace (book lines + statement lines + running difference). */
    public function show(Reconciliation $reconciliation, Request $request)
    {
        return response()->json($this->recon->workspace($reconciliation, $request->user()->tenant_id));
    }

    /** Toggle a book line cleared/uncleared (optionally matched to a statement line). */
    public function toggle(Reconciliation $reconciliation, Request $request)
    {
        $data = $request->validate([
            'voucher_line_id'   => 'required|integer',
            'statement_line_id' => 'nullable|integer',
        ]);

        return response()->json($this->recon->toggleClear(
            $reconciliation, (int) $data['voucher_line_id'], $data['statement_line_id'] ?? null, $request->user()->tenant_id,
        ));
    }

    /** Auto-match statement lines to book lines by amount + date window. */
    public function autoSuggest(Reconciliation $reconciliation, Request $request)
    {
        return response()->json($this->recon->autoSuggest($reconciliation, $request->user()->tenant_id));
    }

    /** Ignore / un-ignore a statement line. */
    public function ignoreLine(Request $request)
    {
        $data = $request->validate(['statement_line_id' => 'required|integer']);
        $this->recon->ignoreStatementLine((int) $data['statement_line_id'], $request->user()->tenant_id);
        return response()->json(['message' => 'Updated']);
    }

    /** Finish (requires difference = 0). */
    public function complete(Reconciliation $reconciliation, Request $request)
    {
        $rec = $this->recon->complete($reconciliation, $request->user()->tenant_id, $request->user()->id);
        return response()->json(['message' => 'Reconciliation completed', 'reconciliation' => $rec]);
    }

    /** Cancel an in-progress reconciliation. */
    public function destroy(Reconciliation $reconciliation, Request $request)
    {
        $this->recon->cancel($reconciliation, $request->user()->tenant_id);
        return response()->json(['message' => 'Reconciliation cancelled']);
    }
}
