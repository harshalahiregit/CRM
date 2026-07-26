<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Hr\ApproveManpowerRequest;
use App\Http\Requests\Hr\AssignManpowerManagerRequest;
use App\Http\Requests\Hr\ConvertToJdRequest;
use App\Http\Requests\Hr\RejectManpowerRequest;
use App\Http\Requests\Hr\StoreManpowerRequest;
use App\Http\Requests\Hr\UpdateManpowerRequest;
use App\Support\Hr\JdTemplate;
use App\Models\Hr\HrManpowerRequest;
use App\Services\Hr\JobDescriptionAIService;
use App\Services\Hr\JobDescriptionAnalyzerService;
use App\Services\Hr\JobDescriptionTemplateService;
use App\Services\Hr\ManpowerRequestService;
use App\Support\Hr\JobDescriptionPromptBuilder;
use Illuminate\Http\Request;

class ManpowerRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private ManpowerRequestService $manpowerRequestService)
    {
    }

    /* GET /api/hr/manpower-requests */
    public function index(Request $request)
    {
        $results = $this->manpowerRequestService->list($request->user(), $request->only(['status', 'department']));

        return $this->success($results);
    }

    /* GET /api/hr/manpower-requests/queue — fully-approved HR queue */
    public function queue(Request $request)
    {
        $results = $this->manpowerRequestService->list(
            $request->user(),
            ['scope' => 'hr_queue'] + $request->only(['department'])
        );

        return $this->success($results);
    }

    /* GET /api/hr/manpower-requests/pending-approvals */
    public function pendingApprovals(Request $request)
    {
        return $this->success($this->manpowerRequestService->pendingApprovals($request->user()));
    }

    /* POST /api/hr/manpower-requests */
    public function store(StoreManpowerRequest $request)
    {
        $mr = $this->manpowerRequestService->create($request->validated(), $request->user());

        return $this->success($mr, 'Request created', 201);
    }

    /* GET /api/hr/manpower-requests/{id} */
    public function show(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $this->assertTenant($request, $manpowerRequest);

        $manpowerRequest->load(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'jobPosting', 'auditLogs.actor']);

        // Standard JD (SPK-1), auto-filled from this requisition. Built here rather
        // than in the browser so "Post Job" and "Convert to JD" emit identical text
        // from one template. Attached on show() only — the list must stay lean.
        $manpowerRequest->setAttribute(
            'standard_jd',
            JdTemplate::build($manpowerRequest, $request->user()->tenant?->name)
        );

        return $this->success($manpowerRequest);
    }

    /* POST /api/hr/manpower-requests/{id}/submit */
    public function submit(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->submit($manpowerRequest, $request->user());

        return $this->success($result, 'Submitted for L1 approval');
    }

    /* POST /api/hr/manpower-requests/{id}/approve-l1 */
    public function approveL1(ApproveManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->approveL1($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'L1 Approved — now pending Management (L2) approval');
    }

    /* POST /api/hr/manpower-requests/{id}/reject-l1 */
    public function rejectL1(RejectManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->rejectL1($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Request rejected at L1');
    }

    /* POST /api/hr/manpower-requests/{id}/approve-l2 */
    public function approveL2(ApproveManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->approveL2($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Fully Approved — request is now in the HR queue');
    }

    /* POST /api/hr/manpower-requests/{id}/reject-l2 */
    public function rejectL2(RejectManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->rejectL2($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Request rejected at L2');
    }

    /* POST /api/hr/manpower-requests/{id}/send-back */
    public function sendBack(RejectManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->sendBack($manpowerRequest, $request->user(), $request->validated('remarks'));

        return $this->success($result, 'Sent back to the requester for revision');
    }

    /* POST /api/hr/manpower-requests/{id}/convert-to-jd */
    public function convertToJd(ConvertToJdRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->convertToJd($manpowerRequest, $request->user(), $request->validated());

        return $this->success($result, 'Converted to Job Description — review and publish');
    }

    /*
     * POST /api/hr/manpower-requests/{id}/generate-jd
     * AI Job Description generation. Thin controller — all AI logic lives in
     * JobDescriptionAIService (method-injected). Permission-gated to HR
     * Recruiter / HR Manager / Super Admin.
     */
    public function generateJd(Request $request, HrManpowerRequest $manpowerRequest, JobDescriptionAIService $ai)
    {
        $this->assertJdActor($request, $manpowerRequest);

        // AI option controls (tone/industry/level/type) + improvement context
        // (improve/current_jd/ats_before) — forwarded as-is; the PromptBuilder
        // whitelists them, so nothing AI-specific is hardcoded in the controller.
        $options = $request->only(['tone', 'industry', 'experience_level', 'employment_type', 'improve', 'current_jd', 'ats_before']);
        $regenerate = $request->boolean('regenerate');

        $result = $ai->generate($manpowerRequest, $request->user(), $regenerate, $options);

        $message = $request->boolean('improve') ? 'AI JD improved' : ($regenerate ? 'AI JD regenerated' : 'AI JD generated');

        return $this->success($result, $message);
    }

    /*
     * POST /api/hr/manpower-requests/{id}/jd-improvement-decision
     * Records the outcome of an AI improvement (accepted/rejected) with the ATS
     * before/after scores. Audit-only — reuses the existing audit timeline.
     */
    public function jdImprovementDecision(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $this->assertJdActor($request, $manpowerRequest);
        $data = $request->validate([
            'decision'   => 'required|in:accepted,rejected',
            'ats_before' => 'nullable|integer',
            'ats_after'  => 'nullable|integer',
        ]);

        $action = $data['decision'] === 'accepted' ? 'AI Improvement Accepted' : 'AI Improvement Rejected';
        $manpowerRequest->recordAudit($action, $request->user(), null, array_filter([
            'ats_before' => $data['ats_before'] ?? null,
            'ats_after'  => $data['ats_after'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->success(null, $action);
    }

    /*
     * POST /api/hr/manpower-requests/{id}/template-jd
     * Deterministic role-template JD generation (no LLM). jd_source=template.
     */
    public function templateJd(Request $request, HrManpowerRequest $manpowerRequest, JobDescriptionTemplateService $templates)
    {
        $this->assertJdActor($request, $manpowerRequest);
        $data = $request->validate(['template' => 'required|string|max:60']);

        return $this->success($templates->generate($manpowerRequest, $request->user(), $data['template']), 'JD generated from template');
    }

    /*
     * POST /api/hr/manpower-requests/{id}/analyze-jd
     * Enterprise JD scorecard (adapter over the existing JdAnalyzer). Pass
     * silent=true for passive refreshes to skip the audit entry.
     */
    public function analyzeJd(Request $request, HrManpowerRequest $manpowerRequest, JobDescriptionAnalyzerService $analyzer)
    {
        $this->assertJdActor($request, $manpowerRequest);
        $data = $request->validate([
            'title'        => 'nullable|string|max:200',
            'description'  => 'nullable|string',
            'requirements' => 'nullable|string',
            'silent'       => 'nullable|boolean',
        ]);

        $report = $analyzer->report(
            $data['title'] ?? $manpowerRequest->position_title,
            $data['description'] ?? null,
            $data['requirements'] ?? null,
            $manpowerRequest->department,
            (array) $manpowerRequest->required_skills
        );

        if (! $request->boolean('silent')) {
            $manpowerRequest->recordAudit('JD Analyzed', $request->user(), null, [
                'overall' => $report['overall_score'], 'ats' => $report['ats_score'], 'bias' => $report['bias']['status'],
            ]);
        }

        return $this->success($report, 'JD analyzed');
    }

    /*
     * GET /api/hr/manpower-requests/jd-templates
     * Template catalog + AI option catalog for the Convert-to-JD UI.
     */
    public function jdTemplates(Request $request, JobDescriptionTemplateService $templates)
    {
        abort_unless($request->user()->canGenerateAiJd(), 403, 'You are not authorised');

        return $this->success([
            'templates'   => $templates->catalog(),
            'ai_options'  => JobDescriptionPromptBuilder::OPTIONS,
        ]);
    }

    /** Shared guard for the JD generation/analysis actions. */
    private function assertJdActor(Request $request, HrManpowerRequest $manpowerRequest): void
    {
        abort_unless($request->user()->canGenerateAiJd(), 403, 'You are not authorised to use the AI Job Description tools');
        abort_unless((int) $manpowerRequest->tenant_id === (int) $request->user()->tenant_id, 404);
    }

    /* POST /api/hr/manpower-requests/{id}/publish */
    public function publish(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->publishJob($manpowerRequest, $request->user());

        return $this->success($result, 'Job published — hiring started');
    }

    /* POST /api/hr/manpower-requests/{id}/close */
    public function close(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->close($manpowerRequest, $request->user(), $request->input('remarks'));

        return $this->success($result, 'Position closed');
    }

    /* PUT /api/hr/manpower-requests/{id} */
    public function update(UpdateManpowerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->update($manpowerRequest, $request->validated(), $request->user());

        return $this->success($result, 'Updated successfully');
    }

    /* DELETE /api/hr/manpower-requests/{id} */
    public function destroy(Request $request, HrManpowerRequest $manpowerRequest)
    {
        $this->manpowerRequestService->destroy($manpowerRequest, $request->user());

        return $this->success(null, 'Deleted successfully');
    }

    /* GET /api/hr/manpower-requests/stats */
    public function stats(Request $request)
    {
        return $this->success($this->manpowerRequestService->stats($request->user()->tenant_id));
    }

    /* GET /api/hr/manpower-requests/pending-count */
    public function pendingCount(Request $request)
    {
        return $this->success(['count' => $this->manpowerRequestService->pendingCount($request->user()->tenant_id)]);
    }

    /**
     * Distinct project names for the searchable Project dropdown (SPK-1).
     *
     * There is no separate Projects module in this codebase, so the "project"
     * list is sourced from the values already entered on this tenant's manpower
     * requests — dynamic, tenant-scoped, never hardcoded, and no new table.
     */
    public function projects(Request $request)
    {
        $projects = HrManpowerRequest::where('tenant_id', $request->user()->tenant_id)
            ->whereNotNull('project')
            ->where('project', '!=', '')
            ->distinct()
            ->orderBy('project')
            ->pluck('project')
            ->values();

        return $this->success($projects);
    }

    /**
     * Form lookups for the enterprise Manpower Request form (SPK-1).
     *
     * There are no Business-Unit / Department master tables in this codebase, so
     * both are sourced dynamically from the values already on this tenant's
     * requests (never hardcoded). departments_by_bu lets the form filter the
     * Department dropdown by the chosen Business Unit, derived from real history.
     * Hiring managers come from the existing hr_employees table.
     */
    public function formOptions(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $rows = HrManpowerRequest::where('tenant_id', $tenantId)
            ->get(['business_unit', 'department']);

        $businessUnits = $rows->pluck('business_unit')->filter()->unique()->sort()->values();
        $departments   = $rows->pluck('department')->filter()->unique()->sort()->values();

        // business_unit => [departments seen under it] (dynamic, from real data)
        $byBu = $rows->filter(fn ($r) => $r->business_unit && $r->department)
            ->groupBy('business_unit')
            ->map(fn ($g) => $g->pluck('department')->filter()->unique()->sort()->values());

        $managers = \App\Models\Hr\HrEmployee::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'designation', 'department'])
            ->map(fn ($e) => [
                'id'    => $e->id,
                'name'  => $e->name,
                'label' => trim($e->name.($e->designation ? ' · '.$e->designation : '')),
            ]);

        return $this->success([
            'business_units'     => $businessUnits,
            'departments'        => $departments,
            'departments_by_bu'  => $byBu,
            'hiring_managers'    => $managers,
        ]);
    }

    /* PATCH /api/hr/manpower-requests/{id}/assign-manager */
    public function assignManager(AssignManpowerManagerRequest $request, HrManpowerRequest $manpowerRequest)
    {
        $result = $this->manpowerRequestService->assignManager($manpowerRequest, $request->validated('manager_id'), $request->user());

        return $this->success($result, 'Manager assigned');
    }

    /**
     * Tenant guard for route-model-bound reads. Implicit binding resolves by
     * global id; this ensures a user can only read their own tenant's records.
     */
    private function assertTenant(Request $request, HrManpowerRequest $manpowerRequest): void
    {
        abort_unless((int) $manpowerRequest->tenant_id === (int) $request->user()->tenant_id, 404, 'Request not found');
    }
}
