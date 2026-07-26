<?php

namespace App\Http\Controllers\Api\CompanyPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Hr\HrCandidateSubmission;
use App\Models\Hr\HrHiringRequest;
use App\Models\Hr\HrHiringRequestDocument;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrOffer;
use App\Services\Hr\CompanyPortalService;
use App\Services\Hr\HiringRequestDocumentService;
use App\Services\Hr\HiringRequestMessageService;
use App\Services\Hr\InterviewClientActionService;
use App\Support\Hr\CompanyType;
use Illuminate\Http\Request;

/**
 * External company self-service portal. Reads its subject from the ambient
 * `portalCompany` (never a URL id). Hiring-request actions delegate to the
 * existing RecruitmentServicesService — no recruitment logic is duplicated.
 */
class CompanyPortalController extends Controller
{
    use ApiResponse, ResolvesPortalCompany;

    public function __construct(
        private CompanyPortalService $service,
        private HiringRequestMessageService $messages,
        private HiringRequestDocumentService $documents,
        private InterviewClientActionService $interviewActions,
    ) {
    }

    private function requestValidation(bool $creating): array
    {
        return [
            'job_title'           => ($creating ? 'required' : 'sometimes').'|string|max:200',
            'department'          => ($creating ? 'required' : 'sometimes').'|string|max:120',
            'business_unit'       => 'nullable|string|max:150',
            'employment_type'     => 'nullable|in:Full-time,Part-time,Contract,Internship',
            'work_mode'           => 'nullable|in:Onsite,Remote,Hybrid',
            'number_of_positions' => 'nullable|integer|min:1|max:1000',
            'experience_required' => 'nullable|string|max:100',
            'target_joining_date' => 'nullable|date',
            'education'           => 'nullable|string|max:150',
            'location'            => 'nullable|string|max:150',
            'salary_min'          => 'nullable|numeric|min:0',
            'salary_max'          => 'nullable|numeric|min:0|gte:salary_min',
            'required_skills'     => 'nullable|array',
            'required_skills.*'   => 'string|max:60',
            'job_description'     => 'nullable|string|max:20000',
            'priority'            => 'nullable|in:Low,Medium,High,Critical',
        ];
    }

    private function ownRequest(Request $request, HrHiringRequest $hiringRequest): HrHiringRequest
    {
        $this->assertRequestOwned($request, $hiringRequest);

        return $hiringRequest;
    }

    /** GET /api/company-portal/me — the signed-in company + user. */
    public function me(Request $request)
    {
        $company = $this->portalCompany($request);
        $user = $request->user();

        return $this->success([
            'company' => [
                'id'            => $company->id,
                'company_code'  => $company->company_code,
                'name'          => $company->name,
                'company_type'  => $company->company_type,
                'industry'      => $company->industry,
                'logo_path'     => $company->logo_path,
                'status'        => $company->status,
                'branding'      => $company->branding ?? [],
            ],
            'user' => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'internal_role' => $user->internal_role,
                'role_label'    => \App\Support\Hr\CompanyRole::LABELS[$user->internal_role] ?? $user->internal_role,
                'last_login_at' => $user->last_login_at,
                'permissions'   => \App\Support\Hr\CompanyRole::abilitiesFor($user->internal_role),
            ],
        ]);
    }

    /** GET /api/company-portal/dashboard — company KPIs + recent activity. */
    public function dashboard(Request $request)
    {
        return $this->success($this->service->dashboard($this->portalCompany($request)));
    }

    /** GET /api/company-portal/logo — stream the company logo (private disk). */
    public function logo(Request $request)
    {
        $company = $this->portalCompany($request);
        abort_if(! $company->logo_path || ! \Illuminate\Support\Facades\Storage::disk('hr_documents')->exists($company->logo_path), 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('hr_documents')->path($company->logo_path));
    }

    /** Form option lists for the create/edit request UI. */
    public function meta(Request $request)
    {
        return $this->success([
            'company_types'    => CompanyType::ALL,
            'employment_types' => ['Full-time', 'Part-time', 'Contract', 'Internship'],
            'work_modes'       => ['Onsite', 'Remote', 'Hybrid'],
            'priorities'       => ['Low', 'Medium', 'High', 'Critical'],
            'document_types'   => HrHiringRequestDocument::TYPES,
        ]);
    }

    /* ── Hiring requests ── */

    public function hiringRequests(Request $request)
    {
        return $this->success($this->service->hiringRequests(
            $this->portalCompany($request),
            $request->only(['status', 'search', 'sort', 'dir', 'per_page']),
        ));
    }

    public function storeRequest(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate($this->requestValidation(true));

        return $this->success($this->service->createDraft($this->portalCompany($request), $data, $request->user()), 'Draft created', 201);
    }

    public function showRequest(Request $request, HrHiringRequest $hiringRequest)
    {
        return $this->success($this->service->detail($this->ownRequest($request, $hiringRequest)));
    }

    public function updateRequest(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->ownRequest($request, $hiringRequest);
        $data = $request->validate($this->requestValidation(false));

        return $this->success($this->service->updateDraft($this->portalCompany($request), $hiringRequest, $data, $request->user()));
    }

    public function submitRequest(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->ownRequest($request, $hiringRequest);

        return $this->success($this->service->submit($hiringRequest, $request->user()), 'Hiring request submitted for review');
    }

    /* ── Messages ── */

    public function messages(Request $request, HrHiringRequest $hiringRequest)
    {
        return $this->success($this->messages->list($this->ownRequest($request, $hiringRequest)));
    }

    public function postMessage(Request $request, HrHiringRequest $hiringRequest)
    {
        abort_unless($request->user()->companyCan('messages', 'manage'), 403, 'Your role has read-only access.');
        $this->ownRequest($request, $hiringRequest);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        return $this->success($this->messages->post($hiringRequest, 'company', $request->user(), $data['body']), 'Message sent', 201);
    }

    /* ── Documents ── */

    public function documents(Request $request, HrHiringRequest $hiringRequest)
    {
        return $this->success($this->documents->list($this->ownRequest($request, $hiringRequest)));
    }

    public function uploadDocument(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->assertCanManage($request);
        $this->ownRequest($request, $hiringRequest);
        $request->validate([
            'type' => 'required|in:'.implode(',', HrHiringRequestDocument::TYPES),
            'file' => 'required|file|max:'.HiringRequestDocumentService::MAX_SIZE_KB.'|mimes:'.implode(',', HiringRequestDocumentService::ALLOWED_MIMES),
        ]);

        $doc = $this->documents->upload($hiringRequest, $request->file('file'), $request->input('type'), 'company', $request->user());

        return $this->success($doc, 'Document uploaded', 201);
    }

    public function downloadDocument(Request $request, HrHiringRequest $hiringRequest, HrHiringRequestDocument $document)
    {
        $this->ownRequest($request, $hiringRequest);
        abort_unless((int) $document->hiring_request_id === (int) $hiringRequest->id, 404);
        $file = $this->documents->resolveDownload($document);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }

    public function deleteDocument(Request $request, HrHiringRequest $hiringRequest, HrHiringRequestDocument $document)
    {
        $this->assertCanManage($request);
        $this->ownRequest($request, $hiringRequest);
        abort_unless((int) $document->hiring_request_id === (int) $hiringRequest->id, 404);
        $this->documents->delete($hiringRequest, $document);

        return $this->success(null, 'Document removed');
    }

    /* ── Candidates (Sprint 3) ── */

    private function ownSubmission(Request $request, HrHiringRequest $hiringRequest, HrCandidateSubmission $submission): void
    {
        $this->ownRequest($request, $hiringRequest);
        abort_unless((int) $submission->hiring_request_id === (int) $hiringRequest->id, 404, 'Candidate not found');
    }

    public function candidates(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->ownRequest($request, $hiringRequest);

        return $this->success($this->service->companyCandidates($hiringRequest, $request->only(['status', 'search', 'sort', 'dir', 'per_page'])));
    }

    public function candidateShow(Request $request, HrHiringRequest $hiringRequest, HrCandidateSubmission $submission)
    {
        $this->ownSubmission($request, $hiringRequest, $submission);

        return $this->success($this->service->candidateDetail($submission));
    }

    public function reviewCandidate(Request $request, HrHiringRequest $hiringRequest, HrCandidateSubmission $submission)
    {
        $this->assertCanManage($request);
        $this->ownSubmission($request, $hiringRequest, $submission);
        $data = $request->validate([
            'decision' => 'required|in:shortlist,accept,reject,more,hold',
            'comment'  => 'nullable|string|max:2000',
        ]);

        return $this->success($this->service->reviewCandidate($submission, $data['decision'], $data['comment'] ?? null), 'Feedback recorded');
    }

    public function candidateResume(Request $request, HrHiringRequest $hiringRequest, HrCandidateSubmission $submission)
    {
        $this->ownSubmission($request, $hiringRequest, $submission);
        $file = $this->service->resolveResume($submission);
        $inline = $request->query('disposition', 'inline') === 'inline';

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$file['filename'].'"',
        ]);
    }

    public function shareCandidateResume(Request $request, HrHiringRequest $hiringRequest, HrCandidateSubmission $submission)
    {
        $this->assertCanManage($request);
        $this->ownSubmission($request, $hiringRequest, $submission);
        $share = $this->service->shareCandidateResume($hiringRequest, $submission, $request->user());

        return $this->success(['share_token' => $share->share_token], 'Resume share link created');
    }

    public function candidateComments(Request $request, HrHiringRequest $hiringRequest, HrCandidateSubmission $submission)
    {
        $this->ownSubmission($request, $hiringRequest, $submission);

        return $this->success($this->service->candidateComments($hiringRequest, $submission));
    }

    public function postCandidateComment(Request $request, HrHiringRequest $hiringRequest, HrCandidateSubmission $submission)
    {
        abort_unless($request->user()->companyCan('messages', 'manage'), 403, 'Your role has read-only access.');
        $this->ownSubmission($request, $hiringRequest, $submission);
        $data = $request->validate(['body' => 'required|string|max:5000']);

        return $this->success($this->service->postCandidateComment($hiringRequest, $submission, $request->user(), $data['body']), 'Comment posted', 201);
    }

    /* ── Interviews (Sprint 4) ── */

    private function ownInterview(Request $request, HrHiringRequest $hiringRequest, HrInterviewRound $interview): void
    {
        $this->ownRequest($request, $hiringRequest);
        abort_unless($this->service->deliveredCandidateIds($hiringRequest)->contains((int) $interview->candidate_id), 404, 'Interview not found');
    }

    public function interviews(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->ownRequest($request, $hiringRequest);

        return $this->success($this->service->requestInterviews($hiringRequest));
    }

    public function interviewAction(Request $request, HrHiringRequest $hiringRequest, HrInterviewRound $interview)
    {
        abort_unless($request->user()->canManageCompanyInterviews(), 403, 'Your role has read-only access.');
        $this->ownInterview($request, $hiringRequest, $interview);
        $data = $request->validate([
            'action'         => 'required|in:Confirmed,Reschedule,Cancel',
            'preferred_date' => 'nullable|date|after_or_equal:today',
            'preferred_time' => 'nullable|string|max:20',
            'reason'         => 'nullable|string|max:2000',
        ]);

        $company = $this->portalCompany($request);
        $submissionId = HrCandidateSubmission::where('hiring_request_id', $hiringRequest->id)
            ->where('candidate_id', $interview->candidate_id)->value('id');
        $this->interviewActions->record($interview, $company, $submissionId, $data['action'], $data, $request->user());

        return $this->success(null, 'Interview '.$data['action'].' request recorded', 201);
    }

    public function interviewInvite(Request $request, HrHiringRequest $hiringRequest, HrInterviewRound $interview)
    {
        $this->ownInterview($request, $hiringRequest, $interview);

        return response($this->service->interviewIcs($interview), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="interview-'.$interview->id.'.ics"',
        ]);
    }

    /* ── Offers (Sprint 4) ── */

    private function ownOffer(Request $request, HrHiringRequest $hiringRequest, HrOffer $offer): void
    {
        $this->ownRequest($request, $hiringRequest);
        abort_unless($this->service->deliveredCandidateIds($hiringRequest)->contains((int) $offer->candidate_id), 404, 'Offer not found');
    }

    public function offers(Request $request, HrHiringRequest $hiringRequest)
    {
        $this->ownRequest($request, $hiringRequest);

        return $this->success($this->service->requestOffers($hiringRequest));
    }

    public function offerDecision(Request $request, HrHiringRequest $hiringRequest, HrOffer $offer)
    {
        $this->assertCanManage($request);
        $this->ownOffer($request, $hiringRequest, $offer);
        $data = $request->validate([
            'decision' => 'required|in:approve,changes,reject,feedback',
            'comment'  => 'nullable|string|max:2000',
        ]);

        return $this->success($this->service->offerDecision($offer, $data['decision'], $data['comment'] ?? null, $request->user()), 'Offer decision recorded');
    }

    public function offerDownload(Request $request, HrHiringRequest $hiringRequest, HrOffer $offer)
    {
        $this->ownOffer($request, $hiringRequest, $offer);
        $file = $this->service->offerLetter($offer);

        return response()->download($file['path'], $file['filename'], [
            'Content-Type'        => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.$file['filename'].'"',
        ]);
    }
}
