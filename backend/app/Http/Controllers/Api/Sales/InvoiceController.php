<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\RecordPaymentRequest;
use App\Http\Requests\Sales\StoreInvoiceRequest;
use App\Http\Requests\Sales\UpdateInvoiceRequest;
use App\Models\Sales\SalesInvoice;
use App\Services\Sales\InvoiceService;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService)
    {
    }

    public function index(Request $request)
    {
        $invoices = $this->invoiceService->list($request->user()->tenant_id, [
            'status'    => $request->input('status'),
            'client_id' => $request->input('client_id'),
        ]);

        return response()->json($invoices);
    }

    public function store(StoreInvoiceRequest $request)
    {
        $invoice = $this->invoiceService->create(
            $request->validated(),
            $request->user()->tenant_id,
            auth()->id()
        );

        return response()->json($invoice, 201);
    }

    public function show(Request $request, SalesInvoice $invoice)
    {
        $invoice = $this->invoiceService->show($invoice, $request->user()->tenant_id);

        return response()->json($invoice);
    }

    public function update(UpdateInvoiceRequest $request, SalesInvoice $invoice)
    {
        $invoice = $this->invoiceService->update(
            $invoice,
            $request->validated(),
            $request->user()->tenant_id
        );

        return response()->json($invoice);
    }

    public function destroy(Request $request, SalesInvoice $invoice)
    {
        $this->invoiceService->delete($invoice, $request->user()->tenant_id);

        return response()->json(['message' => 'Deleted']);
    }

    public function send(Request $request, SalesInvoice $invoice)
    {
        $invoice = $this->invoiceService->send($invoice, $request->user()->tenant_id);

        return response()->json($invoice);
    }

    /**
     * POST /api/sales/invoices/{invoice}/payments
     */
    public function recordPayment(RecordPaymentRequest $request, SalesInvoice $invoice)
    {
        $result = $this->invoiceService->recordPayment(
            $invoice,
            $request->validated(),
            $request->user()->tenant_id,
            auth()->id()
        );

        return response()->json($result, 201);
    }

    /**
     * POST /api/sales/invoices/{invoice}/public-link
     */
    public function generatePublicLink(Request $request, SalesInvoice $invoice)
    {
        $invoice = $this->invoiceService->generatePublicLink(
            $invoice,
            $request->user()->tenant_id,
            $request->input('expiry_days', 30)
        );

        return response()->json([
            'token'      => $invoice->public_link_token,
            'expires_at' => $invoice->public_link_expiry,
        ]);
    }

    /**
     * POST /api/sales/invoices/{invoice}/send-reminder
     */
    public function sendPaymentReminder(Request $request, SalesInvoice $invoice)
    {
        $reminder = $this->invoiceService->sendPaymentReminder($invoice, $request->user()->tenant_id);

        return response()->json($reminder, 201);
    }

    /**
     * POST /api/sales/invoices/{invoice}/send-feedback-request
     */
    public function sendFeedbackRequest(Request $request, SalesInvoice $invoice)
    {
        $invoice = $this->invoiceService->sendFeedbackRequest($invoice, $request->user()->tenant_id);

        return response()->json($invoice);
    }
}
