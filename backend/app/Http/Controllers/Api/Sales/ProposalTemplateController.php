<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreProposalTemplateRequest;
use App\Http\Requests\Sales\UpdateProposalTemplateRequest;
use App\Models\Sales\ProposalTemplate;
use App\Services\Sales\ProposalTemplateService;
use Illuminate\Http\Request;

class ProposalTemplateController extends Controller
{
    public function __construct(private ProposalTemplateService $proposalTemplateService)
    {
    }

    public function index(Request $request)
    {
        return response()->json($this->proposalTemplateService->list($request->user()->tenant_id));
    }

    public function store(StoreProposalTemplateRequest $request)
    {
        $template = $this->proposalTemplateService->create(
            $request->validated(),
            $request->user()->tenant_id,
            $request->user()->id
        );

        return response()->json($template, 201);
    }

    public function update(UpdateProposalTemplateRequest $request, ProposalTemplate $proposalTemplate)
    {
        $template = $this->proposalTemplateService->update(
            $proposalTemplate,
            $request->validated(),
            $request->user()->tenant_id
        );

        return response()->json($template);
    }

    public function destroy(Request $request, ProposalTemplate $proposalTemplate)
    {
        $this->proposalTemplateService->delete($proposalTemplate, $request->user()->tenant_id);

        return response()->json(['message' => 'Deleted']);
    }

    public function clone(Request $request, ProposalTemplate $proposalTemplate)
    {
        $proposal = $this->proposalTemplateService->cloneToProposal(
            $proposalTemplate,
            $request->user()->tenant_id,
            $request->user()->id
        );

        return response()->json($proposal, 201);
    }
}
