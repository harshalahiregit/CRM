<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Hr\HrExternalCompany;
use App\Models\Hr\HrHiringRequest;
use App\Models\Hr\HrRecruiterNote;
use App\Services\Hr\RecruitmentServicesService;
use Illuminate\Http\Request;

class RecruitmentServicesController extends Controller
{
    use ApiResponse;

    public function __construct(private RecruitmentServicesService $service)
    {
    }

    /* Row-level tenancy + permission — mirrors the rest of the HR module. */
    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage Recruitment Services');
    }

    private function assertCompanyTenant(Request $request, HrExternalCompany $c): void
    {
        abort_unless((int) $c->tenant_id === (int) $request->user()->tenant_id, 404);
    }

    private function assertRequestTenant(Request $request, HrHiringRequest $r): void
    {
        abort_unless((int) $r->tenant_id === (int) $request->user()->tenant_id, 404);
    }

    /* ── Dashboard ── */
    public function dashboard(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->dashboard($request->user()->tenant_id));
    }

    /* ── External companies ── */
    public function companies(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->companies($request->user()->tenant_id, $request->only(['status', 'search'])));
    }

    public function storeCompany(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'name'           => 'required|string|max:200',
            'industry'       => 'nullable|string|max:120',
            'contact_person' => 'nullable|string|max:120',
            'contact_email'  => 'nullable|email|max:150',
            'contact_phone'  => 'nullable|string|max:30',
            'location'       => 'nullable|string|max:150',
            'website'        => 'nullable|string|max:200',
            'status'         => 'nullable|in:Active,Inactive',
            'notes'          => 'nullable|string|max:2000',
        ]);

        return $this->success($this->service->createCompany($data, $request->user()), 'Company added', 201);
    }

    public function updateCompany(Request $request, HrExternalCompany $company)
    {
        $this->assertCanManage($request);
        $this->assertCompanyTenant($request, $company);
        $data = $request->validate([
            'name'           => 'sometimes|string|max:200',
            'industry'       => 'nullable|string|max:120',
            'contact_person' => 'nullable|string|max:120',
            'contact_email'  => 'nullable|email|max:150',
            'contact_phone'  => 'nullable|string|max:30',
            'location'       => 'nullable|string|max:150',
            'website'        => 'nullable|string|max:200',
            'status'         => 'nullable|in:Active,Inactive',
            'notes'          => 'nullable|string|max:2000',
        ]);

        return $this->success($this->service->updateCompany($company, $data, $request->user()));
    }

    public function destroyCompany(Request $request, HrExternalCompany $company)
    {
        $this->assertCanManage($request);
        $this->assertCompanyTenant($request, $company);
        $this->service->deleteCompany($company);

        return $this->success(null, 'Company deleted');
    }

    /* ── Hiring requests ── */
    public function requests(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->requests($request->user()->tenant_id, $request->only(['status', 'company_id', 'search'])));
    }

    public function showRequest(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);

        return $this->success($hiringRequest->load([
            'externalCompany', 'assignedRecruiter:id,name', 'reviewer:id,name',
            'manpowerRequest:id,status', 'auditLogs.actor',
        ]));
    }

    public function storeRequest(Request $request)
    {
        $this->assertCanManage($request);
        $data = $this->validateRequest($request, true);

        return $this->success($this->service->createRequest($data, $request->user()), 'Hiring request created', 201);
    }

    public function updateRequest(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        $data = $this->validateRequest($request, false);

        return $this->success($this->service->updateRequest($hiringRequest, $data, $request->user()));
    }

    public function review(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        $data = $request->validate([
            'decision' => 'required|in:under-review,approve,reject',
            'notes'    => 'nullable|string|max:2000',
        ]);

        return $this->success($this->service->review($hiringRequest, $data['decision'], $data['notes'] ?? null, $request->user()));
    }

    public function assign(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        $data = $request->validate(['recruiter_id' => 'nullable|exists:users,id']);

        return $this->success($this->service->assignRecruiter($hiringRequest, $data['recruiter_id'] ?? null, $request->user()));
    }

    public function convert(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);

        return $this->success($this->service->convertToManpower($hiringRequest, $request->user()), 'Converted to Manpower Request');
    }

    /* ── Phase 2: client collaboration ── */

    /** Existing candidates eligible to be delivered to the client. */
    public function submittableCandidates(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);

        return $this->success($this->service->submittableCandidates($hiringRequest));
    }

    /** Delivery history for a request (all candidates submitted to the client). */
    public function submissions(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);

        return $this->success([
            'counts'      => $this->service->funnelCounts($hiringRequest),
            'submissions' => $this->service->submissions($hiringRequest),
            'tracking_token' => $hiringRequest->tracking_token,
        ]);
    }

    /** Recruiter submits shortlisted candidates to the client. */
    public function submitCandidates(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        $data = $request->validate([
            'candidate_ids'   => 'required|array|min:1',
            'candidate_ids.*' => 'integer|exists:hr_candidates,id',
            'note'            => 'nullable|string|max:2000',
        ]);

        $created = $this->service->submitCandidates($hiringRequest, $data['candidate_ids'], $data['note'] ?? null, $request->user());

        return $this->success(['submitted' => count($created)], count($created).' candidate(s) submitted to client', 201);
    }

    /** Recruiter-triggered client notification (interview / offer / completion). */
    public function notifyClient(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        $data = $request->validate([
            'event' => 'required|in:interview_scheduled,offer_released,project_completed',
        ]);

        return $this->success($this->service->notifyClient($hiringRequest, $data['event'], $request->user()), 'Notification sent');
    }

    /* ── Phase 3: recruiter operations ── */

    public function workspace(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->recruiterWorkspace($request->user()));
    }

    public function recruiterDashboard(Request $request)
    {
        $this->assertCanManage($request);

        return $this->success($this->service->recruiterDashboard($request->user()));
    }

    public function sla(Request $request)
    {
        $this->assertCanManage($request);
        $recruiterId = $request->filled('recruiter_id') ? (int) $request->input('recruiter_id') : null;

        return $this->success($this->service->slaTracking($request->user()->tenant_id, $recruiterId));
    }

    public function performance(Request $request)
    {
        $this->assertCanManage($request);
        $recruiterId = $request->filled('recruiter_id') ? (int) $request->input('recruiter_id') : null;

        return $this->success($this->service->recruiterPerformance($request->user()->tenant_id, $recruiterId));
    }

    /* ── Per-request Phase 3 actions ── */

    public function notes(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);

        return $this->success($this->service->notes($hiringRequest));
    }

    public function addNote(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        return $this->success($this->service->addNote($hiringRequest, $data['body'], $request->user()), 'Note added', 201);
    }

    public function deleteNote(Request $request, HrHiringRequest $hiringRequest, HrRecruiterNote $note)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        abort_unless((int) $note->hiring_request_id === (int) $hiringRequest->id, 404);
        $this->service->deleteNote($note);

        return $this->success(null, 'Note deleted');
    }

    public function clientRating(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);

        return $this->success($this->service->clientRating($hiringRequest));
    }

    public function resumeShares(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);

        return $this->success($this->service->resumeShares($hiringRequest));
    }

    public function shareResume(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->assertRequestTenant($request, $hiringRequest);
        $data = $request->validate(['candidate_id' => 'required|integer|exists:hr_candidates,id']);

        return $this->success($this->service->shareResume($hiringRequest, $data['candidate_id'], $request->user()), 'Resume shared with client', 201);
    }

    private function validateRequest(Request $request, bool $creating): array
    {
        return $request->validate([
            'external_company_id' => ($creating ? 'required' : 'sometimes').'|exists:hr_external_companies,id',
            'job_title'           => ($creating ? 'required' : 'sometimes').'|string|max:200',
            'department'          => 'nullable|string|max:120',
            'employment_type'     => 'nullable|in:Full-time,Part-time,Contract,Internship',
            'work_mode'           => 'nullable|in:Onsite,Remote,Hybrid',
            'number_of_positions' => 'nullable|integer|min:1|max:1000',
            'experience_required' => 'nullable|string|max:100',
            'education'           => 'nullable|string|max:150',
            'location'            => 'nullable|string|max:150',
            'salary_min'          => 'nullable|numeric|min:0',
            'salary_max'          => 'nullable|numeric|min:0|gte:salary_min',
            'required_skills'     => 'nullable|array',
            'required_skills.*'   => 'string|max:60',
            'job_description'     => 'nullable|string|max:20000',
            'priority'            => 'nullable|in:Low,Medium,High,Critical',
        ]);
    }
}
