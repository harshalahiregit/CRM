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
            $this->offerService->list($request->user()->tenant_id, $request->only(['status', 'view']))
        );
    }

    /** HR pre-joining dashboard: accepted offers bucketed by days-to-joining. */
    public function joiningBuckets(Request $request)
    {
        return response()->json($this->offerService->joiningBuckets($request->user()->tenant_id));
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

    /** HR confirms joining (joining day) → creates the Employee + moves to Hired. */
    public function confirmJoining(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        return response()->json($this->offerService->confirmJoining($offer)->load('candidate'));
    }

    /** HR regenerates an expired/declined offer with a fresh validity + token. */
    public function regenerate(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        $data = $request->validate(['validity_date' => 'nullable|date']);

        return response()->json($this->offerService->regenerate($offer, $data['validity_date'] ?? null)->load('candidate'));
    }

    public function destroy(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);

        $this->offerService->destroy($offer);

        return response()->json(['message' => 'Deleted']);
    }

    /** Only HR-queue managers may act on offers. */
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage offers');
    }

    /** Tenant guard for route-model-bound offers (row-level isolation). */
    private function assertTenant(Request $request, HrOffer $offer): void
    {
        abort_unless((int) $offer->tenant_id === (int) $request->user()->tenant_id, 404, 'Offer not found');
    }
}
