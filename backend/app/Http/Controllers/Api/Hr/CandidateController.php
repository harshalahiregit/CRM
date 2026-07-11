<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\AssignRecruiterRequest;
use App\Http\Requests\Hr\LinkedinParseRequest;
use App\Http\Requests\Hr\StoreCandidateRequest;
use App\Http\Requests\Hr\UpdateCandidateDecisionRequest;
use App\Http\Requests\Hr\UpdateCandidateStageRequest;
use App\Models\Hr\HrCandidate;
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
        $this->assertCanManage($request);

        $candidate = $this->candidateService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($candidate, 201);
    }

    public function show(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);

        return response()->json(
            $candidate->load([
                'jobPosting', 'interviewRounds', 'offer', 'onboarding',
                'assignedRecruiter:id,name,internal_role', 'candidateNotes', 'documents',
                'auditLogs.actor',
            ])
        );
    }

    public function update(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->update($candidate, $request->all());

        return response()->json($updated);
    }

    public function updateStage(UpdateCandidateStageRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->updateStage($candidate, $request->validated('stage'));

        return response()->json($updated);
    }

    public function updateDecision(UpdateCandidateDecisionRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->updateDecision($candidate, $request->validated('final_decision'));

        return response()->json($updated);
    }

    public function destroy(Request $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $this->candidateService->destroy($candidate);

        return response()->json(['message' => 'Deleted']);
    }

    public function assign(AssignRecruiterRequest $request, HrCandidate $candidate)
    {
        $this->assertTenant($request, $candidate);
        $this->assertCanManage($request);

        $updated = $this->candidateService->assignRecruiter($candidate, $request->validated('recruiter_id'));

        return response()->json($updated);
    }

    /** Assignable recruiters (internal, active users) in the caller's tenant. */
    public function recruiters(Request $request)
    {
        return response()->json(
            $this->candidateService->assignableRecruiters($request->user()->tenant_id)
        );
    }

    // ── LinkedIn Profile Extractor ─────────────────────────────────────
    public function linkedinParse(LinkedinParseRequest $request)
    {
        return response()->json($this->candidateService->linkedinParse($request->validated('url')));
    }

    /**
     * Tenant guard for route-model-bound actions. Implicit binding resolves by
     * global id, so this ensures a user only ever touches their own tenant's
     * candidates (row-level isolation).
     */
    private function assertTenant(Request $request, HrCandidate $candidate): void
    {
        abort_unless((int) $candidate->tenant_id === (int) $request->user()->tenant_id, 404, 'Candidate not found');
    }

    /** Only HR-queue managers may mutate candidates. */
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage candidates');
    }
}
