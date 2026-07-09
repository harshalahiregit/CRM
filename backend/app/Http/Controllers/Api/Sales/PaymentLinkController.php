<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StorePaymentLinkRequest;
use App\Models\Sales\PaymentLink;
use App\Services\Sales\PaymentLinkService;
use Illuminate\Http\Request;

class PaymentLinkController extends Controller
{
    public function __construct(private PaymentLinkService $paymentLinkService)
    {
    }

    public function index(Request $request)
    {
        $links = $this->paymentLinkService->list($request->user()->tenant_id, [
            'status' => $request->input('status'),
        ]);

        return response()->json($links);
    }

    public function store(StorePaymentLinkRequest $request)
    {
        $link = $this->paymentLinkService->create(
            $request->validated(),
            $request->user()->tenant_id,
            $request->user()->id
        );

        return response()->json($link, 201);
    }

    public function markPaid(Request $request, PaymentLink $paymentLink)
    {
        $link = $this->paymentLinkService->markPaid(
            $paymentLink,
            $request->only('transaction_id'),
            $request->user()->tenant_id
        );

        return response()->json($link);
    }

    public function cancel(Request $request, PaymentLink $paymentLink)
    {
        $link = $this->paymentLinkService->cancel($paymentLink, $request->user()->tenant_id);

        return response()->json($link);
    }

    public function destroy(Request $request, PaymentLink $paymentLink)
    {
        $this->paymentLinkService->delete($paymentLink, $request->user()->tenant_id);

        return response()->json(['message' => 'Deleted']);
    }
}
