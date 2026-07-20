<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\StorePurchaseRfqRequest;
use App\Http\Requests\Purchase\UpdatePurchaseRfqRequest;
use App\Models\Purchase\PurchaseRfq;
use App\Services\Purchase\PurchaseDocumentPdfService;
use App\Services\Purchase\PurchaseRfqService;
use Illuminate\Http\Request;

class PurchaseRfqController extends Controller
{
    public function __construct(private PurchaseRfqService $rfqService, private PurchaseDocumentPdfService $pdfService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->rfqService->list($request->user()->tenant_id, $request->only(['status', 'department', 'search']))
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->rfqService->stats($request->user()->tenant_id));
    }

    public function store(StorePurchaseRfqRequest $request)
    {
        return response()->json($this->rfqService->create($request->validated(), $request->user()), 201);
    }

    public function show(Request $request, PurchaseRfq $rfq)
    {
        $this->assertTenant($request, $rfq);

        return response()->json($rfq->load([
            'items', 'rfqVendors.vendor:id,vendor_code,company_name', 'creator:id,name',
            'quotations.vendor:id,vendor_code,company_name', 'quotations.items', 'auditLogs',
        ]));
    }

    /** The line-by-line comparison matrix across received quotations. */
    public function comparison(Request $request, PurchaseRfq $rfq)
    {
        $this->assertTenant($request, $rfq);

        return response()->json($this->rfqService->comparison($rfq));
    }

    public function update(UpdatePurchaseRfqRequest $request, PurchaseRfq $rfq)
    {
        $this->assertTenant($request, $rfq);

        return response()->json($this->rfqService->update($rfq, $request->validated(), $request->user()));
    }

    public function send(Request $request, PurchaseRfq $rfq)
    {
        $this->assertTenant($request, $rfq);

        return response()->json($this->rfqService->send($rfq, $request->user()));
    }

    public function cancel(Request $request, PurchaseRfq $rfq)
    {
        $this->assertTenant($request, $rfq);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->rfqService->cancel($rfq, $request->user(), $data['remarks'] ?? null));
    }

    public function destroy(Request $request, PurchaseRfq $rfq)
    {
        $this->assertTenant($request, $rfq);
        $this->rfqService->destroy($rfq);

        return response()->json(['message' => 'Deleted']);
    }

    /** Vendor-facing RFQ PDF — ?inline=1 streams, otherwise downloads. */
    public function downloadPdf(Request $request, PurchaseRfq $rfq)
    {
        $this->assertTenant($request, $rfq);
        $pdf = $this->pdfService->renderRfq($rfq);
        $file = "{$rfq->rfq_number}.pdf";

        return $request->boolean('inline')
            ? $pdf->stream($file)
            : $pdf->download($file);
    }

    private function assertTenant(Request $request, PurchaseRfq $rfq): void
    {
        abort_unless(
            (int) $rfq->tenant_id === (int) $request->user()->tenant_id,
            404,
            'RFQ not found'
        );
    }
}
