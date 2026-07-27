<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\RecordPurchaseInvoicePaymentRequest;
use App\Http\Requests\Purchase\StorePurchaseInvoiceRequest;
use App\Http\Requests\Purchase\UpdatePurchaseInvoiceRequest;
use App\Models\Purchase\PurchaseInvoice;
use App\Models\Purchase\PurchaseInvoicePayment;
use App\Models\Purchase\PurchaseOrder;
use App\Services\Purchase\PurchaseInvoiceService;
use App\Services\Purchase\ThreeWayMatchService;
use Illuminate\Http\Request;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private PurchaseInvoiceService $purchaseInvoiceService,
        private ThreeWayMatchService $threeWayMatch,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->purchaseInvoiceService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'purchase_vendor_id', 'overdue', 'search'])
            )
        );
    }

    public function store(StorePurchaseInvoiceRequest $request)
    {
        $invoice = $this->purchaseInvoiceService->create($request->validated(), $request->user());

        return response()->json($invoice, 201);
    }

    /** Raise an invoice from an issued/received Purchase Order. */
    public function fromOrder(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless(
            (int) $purchaseOrder->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Purchase order not found'
        );

        $data = $request->validate([
            'vendor_invoice_ref' => 'nullable|string',
            'invoice_date'       => 'nullable|date',
            'due_date'           => 'nullable|date',
        ]);

        $invoice = $this->purchaseInvoiceService->createFromOrder($purchaseOrder, $data, $request->user());

        return response()->json($invoice, 201);
    }

    public function show(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertTenant($request, $purchaseInvoice);

        // Shape unchanged (returns the invoice directly) so existing consumers
        // keep working; the approval screen pulls variances from /match on demand.
        return response()->json($purchaseInvoice->load([
            'items', 'vendor', 'creator:id,name', 'purchaseOrder:id,po_number',
            'payments.creator:id,name', 'creditApplications.debitNote:id,debit_number', 'auditLogs',
        ]));
    }

    /** Read-only 3-way match preview — billed vs ordered vs GRN-accepted. */
    public function match(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertTenant($request, $purchaseInvoice);

        return response()->json($this->threeWayMatch->evaluate($purchaseInvoice));
    }

    public function update(Request $request, PurchaseInvoice $purchaseInvoice, UpdatePurchaseInvoiceRequest $updateRequest)
    {
        $this->assertTenant($request, $purchaseInvoice);

        return response()->json(
            $this->purchaseInvoiceService->update($purchaseInvoice, $updateRequest->validated(), $request->user())
        );
    }

    public function approve(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertTenant($request, $purchaseInvoice);

        return response()->json($this->purchaseInvoiceService->approve($purchaseInvoice, $request->user()));
    }

    public function recordPayment(RecordPurchaseInvoicePaymentRequest $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertTenant($request, $purchaseInvoice);

        return response()->json(
            $this->purchaseInvoiceService->recordPayment($purchaseInvoice, $request->validated(), $request->user())
        );
    }

    public function deletePayment(Request $request, PurchaseInvoice $purchaseInvoice, PurchaseInvoicePayment $payment)
    {
        $this->assertTenant($request, $purchaseInvoice);

        return response()->json(
            $this->purchaseInvoiceService->deletePayment($purchaseInvoice, $payment, $request->user())
        );
    }

    public function cancel(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertTenant($request, $purchaseInvoice);

        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json(
            $this->purchaseInvoiceService->cancel($purchaseInvoice, $request->user(), $data['remarks'] ?? null)
        );
    }

    public function destroy(Request $request, PurchaseInvoice $purchaseInvoice)
    {
        $this->assertTenant($request, $purchaseInvoice);

        $this->purchaseInvoiceService->destroy($purchaseInvoice);

        return response()->json(['message' => 'Deleted']);
    }

    public function stats(Request $request)
    {
        return response()->json($this->purchaseInvoiceService->stats($request->user()->tenant_id));
    }

    private function assertTenant(Request $request, PurchaseInvoice $purchaseInvoice): void
    {
        abort_unless(
            (int) $purchaseInvoice->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Purchase invoice not found'
        );
    }
}
