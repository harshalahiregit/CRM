<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\ApplyCreditRequest;
use App\Http\Requests\Purchase\RecordDebitRefundRequest;
use App\Http\Requests\Purchase\StorePurchaseDebitNoteRequest;
use App\Http\Requests\Purchase\UpdatePurchaseDebitNoteRequest;
use App\Models\Purchase\PurchaseCreditApplication;
use App\Models\Purchase\PurchaseDebitNote;
use App\Models\Purchase\PurchaseDebitRefund;
use App\Services\Purchase\PurchaseCreditApplicationService;
use App\Services\Purchase\PurchaseDebitNoteService;
use Illuminate\Http\Request;

class PurchaseDebitNoteController extends Controller
{
    public function __construct(
        private PurchaseDebitNoteService $debitNoteService,
        private PurchaseCreditApplicationService $creditService,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->debitNoteService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'vendor_id', 'search'])
            )
        );
    }

    public function store(StorePurchaseDebitNoteRequest $request)
    {
        $dn = $this->debitNoteService->create($request->validated(), $request->user());

        return response()->json($dn, 201);
    }

    public function show(Request $request, PurchaseDebitNote $debitNote)
    {
        $this->assertTenant($request, $debitNote);

        return response()->json($debitNote->load([
            'items', 'vendor', 'creator:id,name', 'purchaseOrder:id,po_number',
            'refunds.creator:id,name', 'creditApplications.invoice:id,invoice_number', 'auditLogs',
        ]));
    }

    public function update(Request $request, PurchaseDebitNote $debitNote, UpdatePurchaseDebitNoteRequest $updateRequest)
    {
        $this->assertTenant($request, $debitNote);

        return response()->json(
            $this->debitNoteService->update($debitNote, $updateRequest->validated(), $request->user())
        );
    }

    public function issue(Request $request, PurchaseDebitNote $debitNote)
    {
        $this->assertTenant($request, $debitNote);

        return response()->json($this->debitNoteService->issue($debitNote, $request->user()));
    }

    public function recordRefund(RecordDebitRefundRequest $request, PurchaseDebitNote $debitNote)
    {
        $this->assertTenant($request, $debitNote);

        return response()->json(
            $this->debitNoteService->recordRefund($debitNote, $request->validated(), $request->user())
        );
    }

    public function deleteRefund(Request $request, PurchaseDebitNote $debitNote, PurchaseDebitRefund $refund)
    {
        $this->assertTenant($request, $debitNote);

        return response()->json(
            $this->debitNoteService->deleteRefund($debitNote, $refund, $request->user())
        );
    }

    /** Invoices this note's balance can be netted against (same vendor, payable). */
    public function applicableInvoices(Request $request, PurchaseDebitNote $debitNote)
    {
        $this->assertTenant($request, $debitNote);

        return response()->json($this->creditService->applicableInvoices($debitNote));
    }

    /** Apply part of this note's balance against an invoice. */
    public function applyCredit(ApplyCreditRequest $request, PurchaseDebitNote $debitNote)
    {
        $this->assertTenant($request, $debitNote);
        $this->creditService->apply($debitNote, $request->validated(), $request->user());

        // Return the refreshed note with its applications for the detail screen.
        return response()->json($debitNote->fresh([
            'refunds.creator:id,name', 'creditApplications.invoice:id,invoice_number',
        ]));
    }

    /** Reverse a credit application. */
    public function reverseCredit(Request $request, PurchaseDebitNote $debitNote, PurchaseCreditApplication $application)
    {
        $this->assertTenant($request, $debitNote);
        $this->creditService->reverse($debitNote, $application, $request->user());

        return response()->json($debitNote->fresh([
            'refunds.creator:id,name', 'creditApplications.invoice:id,invoice_number',
        ]));
    }

    public function cancel(Request $request, PurchaseDebitNote $debitNote)
    {
        $this->assertTenant($request, $debitNote);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->debitNoteService->cancel($debitNote, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function destroy(Request $request, PurchaseDebitNote $debitNote)
    {
        $this->assertTenant($request, $debitNote);

        $this->debitNoteService->destroy($debitNote);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->debitNoteService->stats($request->user()->tenant_id));
    }

    private function assertTenant(Request $request, PurchaseDebitNote $debitNote): void
    {
        abort_unless(
            (int) $debitNote->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Debit note not found'
        );
    }
}
