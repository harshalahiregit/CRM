<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\StoreOfferRequest;
use App\Http\Requests\Hr\UpdateOfferStatusRequest;
use App\Models\Hr\HrOffer;
use App\Services\Hr\OfferService;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function __construct(private OfferService $offerService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->offerService->list($request->user()->tenant_id, $request->only('status'))
        );
    }

    public function store(StoreOfferRequest $request)
    {
        $offer = $this->offerService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($offer, 201);
    }

    public function show(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);

        return response()->json($offer->load('candidate'));
    }

    public function send(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);

        $updated = $this->offerService->send($offer);

        return response()->json($updated);
    }

    public function updateStatus(UpdateOfferStatusRequest $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);

        $updated = $this->offerService->updateStatus($offer, $request->validated('status'), $request->validated('rejection_reason'));

        return response()->json($updated);
    }

    public function destroy(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);

        $this->offerService->destroy($offer);

        return response()->json(['message' => 'Deleted']);
    }

    /** Tenant guard for route-model-bound offers (row-level isolation). */
    private function assertTenant(Request $request, HrOffer $offer): void
    {
        abort_unless((int) $offer->tenant_id === (int) $request->user()->tenant_id, 404, 'Offer not found');
    }
}
