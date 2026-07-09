<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreEstimateRequest;
use App\Http\Requests\Sales\UpdateEstimateRequest;
use App\Models\Estimate;
use App\Services\Sales\EstimateService;
use Illuminate\Http\Request;

class EstimateController extends Controller
{
    public function __construct(private EstimateService $estimateService)
    {
    }

    public function index(Request $request)
    {
        $estimates = $this->estimateService->list(
            $request->user()->tenant_id,
            $request->filled('status') ? $request->status : null
        );

        return response()->json($estimates);
    }

    public function store(StoreEstimateRequest $request)
    {
        $validated = $request->validated();
        $lineItems = $validated['line_items'] ?? [];
        unset($validated['line_items']);

        $estimate = $this->estimateService->create($validated, $lineItems, $request->user()->tenant_id, $request->user()->id);

        return response()->json($estimate, 201);
    }

    public function show(Estimate $estimate, Request $request)
    {
        return response()->json($this->estimateService->show($estimate, $request->user()->tenant_id));
    }

    public function update(UpdateEstimateRequest $request, Estimate $estimate)
    {
        $validated = $request->validated();
        $lineItems = $validated['line_items'] ?? null;
        unset($validated['line_items']);

        $updated = $this->estimateService->update(
            $estimate, $validated, $lineItems, $request->has('line_items'), $request->user()->tenant_id
        );

        return response()->json($updated);
    }

    public function destroy(Estimate $estimate, Request $request)
    {
        $this->estimateService->delete($estimate, $request->user()->tenant_id);
        return response()->json(['message' => 'Deleted']);
    }

    public function send(Estimate $estimate, Request $request)
    {
        return response()->json($this->estimateService->send($estimate, $request->user()->tenant_id));
    }

    public function convertToInvoice(Request $request, Estimate $estimate)
    {
        $invoice = $this->estimateService->convertToInvoice(
            $estimate, $request->input('due_date'), $request->user()->tenant_id, $request->user()->id
        );

        return response()->json(['invoice_id' => $invoice->id, 'invoice_number' => $invoice->number], 201);
    }
}
