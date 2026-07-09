<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\LinkedinParseRequest;
use App\Http\Requests\Hr\StoreCandidateRequest;
use App\Http\Requests\Hr\UpdateCandidateDecisionRequest;
use App\Http\Requests\Hr\UpdateCandidateStageRequest;
use App\Models\HrCandidate;
use App\Services\Hr\CandidateService;
use Illuminate\Http\Request;

class CandidateController extends Controller
{
    public function __construct(private CandidateService $candidateService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->candidateService->list($request->user()->tenant_id, $request->only(['stage', 'job_posting_id', 'source', 'search']))
        );
    }

    public function store(StoreCandidateRequest $request)
    {
        $candidate = $this->candidateService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($candidate, 201);
    }

    public function show(HrCandidate $candidate)
    {
        return response()->json(
            $candidate->load(['jobPosting', 'interviewRounds', 'offer', 'onboarding'])
        );
    }

    public function update(Request $request, HrCandidate $candidate)
    {
        $updated = $this->candidateService->update($candidate, $request->all());

        return response()->json($updated);
    }

    public function updateStage(UpdateCandidateStageRequest $request, HrCandidate $candidate)
    {
        $updated = $this->candidateService->updateStage($candidate, $request->validated('stage'));

        return response()->json($updated);
    }

    public function updateDecision(UpdateCandidateDecisionRequest $request, HrCandidate $candidate)
    {
        $updated = $this->candidateService->updateDecision($candidate, $request->validated('final_decision'));

        return response()->json($updated);
    }

    public function destroy(HrCandidate $candidate)
    {
        $this->candidateService->destroy($candidate);

        return response()->json(['message' => 'Deleted']);
    }

    // ── LinkedIn Profile Extractor ─────────────────────────────────────
    public function linkedinParse(LinkedinParseRequest $request)
    {
        return response()->json($this->candidateService->linkedinParse($request->validated('url')));
    }
}
