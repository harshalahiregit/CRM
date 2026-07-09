<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreProposalRequest;
use App\Http\Requests\Sales\UpdateProposalRequest;
use App\Http\Requests\Sales\UpdateProposalStatusRequest;
use App\Models\Sales\Proposal;
use App\Services\Sales\ProposalService;
use Illuminate\Http\Request;

class ProposalController extends Controller
{
    public function __construct(private ProposalService $proposalService)
    {
    }

    public function index(Request $request)
    {
        $proposals = $this->proposalService->list(
            $request->user()->tenant_id,
            $request->filled('status') ? $request->status : null,
            $request->filled('search') ? $request->search : null
        );

        return response()->json($proposals);
    }

    public function store(StoreProposalRequest $request)
    {
        $validated = $request->validated();
        $lineItems = $validated['line_items'] ?? [];
        unset($validated['line_items']);

        $proposal = $this->proposalService->create($validated, $lineItems, $request->user()->tenant_id, $request->user()->id);

        return response()->json($proposal, 201);
    }

    public function show(Proposal $proposal, Request $request)
    {
        return response()->json($this->proposalService->show($proposal, $request->user()->tenant_id));
    }

    public function update(UpdateProposalRequest $request, Proposal $proposal)
    {
        $validated = $request->validated();
        $lineItems = $validated['line_items'] ?? null;
        unset($validated['line_items']);

        $updated = $this->proposalService->update(
            $proposal, $validated, $lineItems, $request->has('line_items'), $request->user()->tenant_id
        );

        return response()->json($updated);
    }

    public function destroy(Proposal $proposal, Request $request)
    {
        $this->proposalService->delete($proposal, $request->user()->tenant_id);
        return response()->json(['message' => 'Deleted']);
    }

    public function send(Proposal $proposal, Request $request)
    {
        return response()->json($this->proposalService->send($proposal, $request->user()->tenant_id));
    }

    public function updateStatus(UpdateProposalStatusRequest $request, Proposal $proposal)
    {
        return response()->json(
            $this->proposalService->updateStatus($proposal, $request->validated('status'), $request->user()->tenant_id)
        );
    }
}
