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
        $this->assertCanManage($request);

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
        $this->assertCanManage($request);

        $updated = $this->offerService->send($offer);

        return response()->json($updated);
    }

    public function updateStatus(UpdateOfferStatusRequest $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        $updated = $this->offerService->updateStatus($offer, $request->validated('status'), $request->validated('rejection_reason'), $request->user());

        return response()->json($updated);
    }

    /** HR confirms joining (joining day) → creates the Employee + moves to Hired. */
    public function confirmJoining(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        return response()->json($this->offerService->confirmJoining($offer, $request->user())->load('candidate'));
    }

    /** HR regenerates an expired/declined offer with a fresh validity + token. */
    public function regenerate(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        $data = $request->validate(['validity_date' => 'nullable|date']);

        return response()->json($this->offerService->regenerate($offer, $data['validity_date'] ?? null)->load('candidate'));
    }

    /** Draft/Generated → Pending Approval. */
    public function submitForApproval(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        return response()->json($this->offerService->submitForApproval($offer)->load('candidate'));
    }

    /** Pending Approval → Approved. */
    public function approve(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        return response()->json($this->offerService->approve($offer, $request->user())->load('candidate'));
    }

    /** Withdraw before acceptance (reason mandatory). */
    public function withdraw(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return response()->json($this->offerService->withdraw($offer, $data['reason'], $request->user())->load('candidate'));
    }

    /** Revise a pre-acceptance offer (snapshots the current version to history). */
    public function revise(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        $data = $request->validate([
            'revision_reason'  => 'required|string|max:1000',
            'position'         => 'nullable|string',
            'department'       => 'nullable|string',
            'offered_ctc'      => 'nullable|numeric|min:0',
            'salary_structure_id' => 'nullable|integer|exists:hr_salary_structures,id',
            'joining_date'     => 'nullable|date',
            'probation_period' => 'nullable|string',
            'notice_period'    => 'nullable|string',
            'validity_date'    => 'nullable|date',
        ]);

        return response()->json($this->offerService->revise($offer, $data, $data['revision_reason'], $request->user())->load('candidate'));
    }

    /** Extend validity of a sent/expired offer. */
    public function extend(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        $data = $request->validate(['validity_date' => 'nullable|date']);

        return response()->json($this->offerService->extend($offer, $data['validity_date'] ?? null)->load('candidate'));
    }

    /** Immutable revision history for an offer. */
    public function revisions(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);

        return response()->json($offer->revisions()->get());
    }

    public function destroy(Request $request, HrOffer $offer)
    {
        $this->assertTenant($request, $offer);
        $this->assertCanManage($request);

        $this->offerService->destroy($offer, $request->user());

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
