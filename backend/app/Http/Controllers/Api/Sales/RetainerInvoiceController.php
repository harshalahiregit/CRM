<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreRetainerInvoiceRequest;
use App\Http\Requests\Sales\UpdateRetainerInvoiceRequest;
use App\Models\Sales\RetainerInvoice;
use App\Services\Sales\RetainerInvoiceService;
use Illuminate\Http\Request;

class RetainerInvoiceController extends Controller
{
    public function __construct(private RetainerInvoiceService $retainerInvoiceService)
    {
    }

    public function index(Request $request)
    {
        $retainers = $this->retainerInvoiceService->list($request->user()->tenant_id, [
            'status' => $request->input('status'),
        ]);

        return response()->json($retainers);
    }

    public function store(StoreRetainerInvoiceRequest $request)
    {
        $retainer = $this->retainerInvoiceService->create(
            $request->validated(),
            $request->user()->tenant_id,
            $request->user()->id
        );

        return response()->json($retainer, 201);
    }

    public function show(Request $request, RetainerInvoice $retainerInvoice)
    {
        return response()->json($this->retainerInvoiceService->show($retainerInvoice, $request->user()->tenant_id));
    }

    public function update(UpdateRetainerInvoiceRequest $request, RetainerInvoice $retainerInvoice)
    {
        $retainer = $this->retainerInvoiceService->update(
            $retainerInvoice,
            $request->validated(),
            $request->user()->tenant_id
        );

        return response()->json($retainer);
    }

    public function destroy(Request $request, RetainerInvoice $retainerInvoice)
    {
        $this->retainerInvoiceService->delete($retainerInvoice, $request->user()->tenant_id);

        return response()->json(['message' => 'Deleted']);
    }
}
