<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\ApplyCreditNoteRequest;
use App\Http\Requests\Sales\RefundCreditNoteRequest;
use App\Http\Requests\Sales\StoreCreditNoteRequest;
use App\Models\CreditNote;
use App\Services\Sales\CreditNoteService;
use Illuminate\Http\Request;

class CreditNoteController extends Controller
{
    public function __construct(private CreditNoteService $creditNoteService)
    {
    }

    private function tid(): int { return auth()->user()->tenant_id; }

    public function index(Request $request)
    {
        $creditNotes = $this->creditNoteService->list($this->tid(), [
            'status'    => $request->status,
            'client_id' => $request->client_id,
        ]);

        return response()->json($creditNotes);
    }

    public function store(StoreCreditNoteRequest $request)
    {
        $cn = $this->creditNoteService->create($request->validated(), $this->tid(), auth()->id());

        return response()->json($cn->load('lineItems'), 201);
    }

    public function show(CreditNote $creditNote)
    {
        $creditNote = $this->creditNoteService->show($creditNote, $this->tid());

        return response()->json($creditNote);
    }

    public function destroy(CreditNote $creditNote)
    {
        $this->creditNoteService->void($creditNote, $this->tid());

        return response()->json(['message' => 'Voided']);
    }

    /**
     * POST /api/sales/credit-notes/{creditNote}/apply
     * Apply credit note balance to an invoice
     */
    public function applyToInvoice(ApplyCreditNoteRequest $request, CreditNote $creditNote)
    {
        $result = $this->creditNoteService->applyToInvoice(
            $creditNote,
            $request->validated()['invoice_id'],
            $this->tid(),
            auth()->id()
        );

        return response()->json($result);
    }

    /**
     * POST /api/sales/credit-notes/{creditNote}/refund
     */
    public function refund(RefundCreditNoteRequest $request, CreditNote $creditNote)
    {
        $result = $this->creditNoteService->refund($creditNote, $request->validated(), $this->tid(), auth()->id());

        return response()->json($result);
    }
}
