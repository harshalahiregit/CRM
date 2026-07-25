<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\RecordQuotationRequest;
use App\Http\Requests\Purchase\UpdateQuotationRequest;
use App\Models\Purchase\PurchaseQuotation;
use App\Models\Purchase\PurchaseRfq;
use App\Services\Purchase\PurchaseQuotationService;
use Illuminate\Http\Request;

class PurchaseQuotationController extends Controller
{
    public function __construct(private PurchaseQuotationService $quotationService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->quotationService->list($request->user()->tenant_id, $request->only(['purchase_rfq_id', 'status', 'vendor_id']))
        );
    }

    public function show(Request $request, PurchaseQuotation $quotation)
    {
        $this->assertTenant($request, $quotation);

        return response()->json($quotation->load(['items', 'vendor', 'rfq:id,rfq_number,title', 'auditLogs']));
    }

    /** Record a vendor's quotation against an RFQ. */
    public function store(RecordQuotationRequest $request, PurchaseRfq $rfq)
    {
        $this->assertRfqTenant($request, $rfq);

        return response()->json(
            $this->quotationService->record($rfq, $request->validated(), $request->user()),
            201
        );
    }

    public function update(UpdateQuotationRequest $request, PurchaseQuotation $quotation)
    {
        $this->assertTenant($request, $quotation);

        return response()->json($this->quotationService->update($quotation, $request->validated(), $request->user()));
    }

    public function shortlist(Request $request, PurchaseQuotation $quotation)
    {
        $this->assertTenant($request, $quotation);
        $data = $request->validate(['on' => 'nullable|boolean']);

        return response()->json($this->quotationService->shortlist($quotation, $data['on'] ?? true, $request->user()));
    }

    public function reject(Request $request, PurchaseQuotation $quotation)
    {
        $this->assertTenant($request, $quotation);
        $data = $request->validate(['remarks' => 'nullable|string']);

        return response()->json($this->quotationService->reject($quotation, $request->user(), $data['remarks'] ?? null));
    }

    /** Award this quotation → create the PO. Admin-only (route-gated). */
    public function award(Request $request, PurchaseQuotation $quotation)
    {
        $this->assertTenant($request, $quotation);

        $po = $this->quotationService->award($quotation, $request->user());

        return response()->json([
            'message'        => 'Quotation awarded — a draft purchase order has been created.',
            'purchase_order' => $po,
        ], 201);
    }

    private function assertTenant(Request $request, PurchaseQuotation $quotation): void
    {
        abort_unless(
            (int) $quotation->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Quotation not found'
        );
    }

    private function assertRfqTenant(Request $request, PurchaseRfq $rfq): void
    {
        abort_unless(
            (int) $rfq->tenant_id === (int) $request->user()->tenant_id,
            404,
            'RFQ not found'
        );
    }
}
