<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\RecordInterviewFeedbackRequest;
use App\Http\Requests\Hr\SendInterviewNotificationRequest;
use App\Http\Requests\Hr\StoreInterviewRequest;
use App\Http\Requests\Hr\UpdateInterviewRequest;
use App\Models\Hr\HrInterviewRound;
use App\Models\User;
use App\Services\Hr\InterviewService;
use Illuminate\Http\Request;

class InterviewController extends Controller
{
    public function __construct(private InterviewService $interviewService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->interviewService->list($request->user()->tenant_id, $request->only(['status', 'date', 'today', 'candidate_id', 'job_posting_id']))
        );
    }

    public function stats(Request $request)
    {
        return response()->json($this->interviewService->stats($request->user()->tenant_id));
    }

    public function store(StoreInterviewRequest $request)
    {
        $this->assertCanManage($request);

        $round = $this->interviewService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($round, 201);
    }

    public function show(Request $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);

        // Interview Details page (SPK-1): the round already carries feedback,
        // scores and the panel (interviewers JSON); the candidate relation adds
        // the summary (job applied, AI score, recruiter) and attachments. Only the
        // eager-load set is widened here — no new endpoint, table or column.
        return response()->json($interviewRound->load([
            'candidate.jobPosting',
            'candidate.assignedRecruiter:id,name,internal_role',
            'candidate.documents',
            'auditLogs.actor',
        ]));
    }

    public function update(UpdateInterviewRequest $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);
        $this->assertCanManage($request);

        $updated = $this->interviewService->reschedule($interviewRound, $request->validated());

        return response()->json($updated);
    }

    public function recordFeedback(RecordInterviewFeedbackRequest $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);
        $this->assertCanManage($request);

        $updated = $this->interviewService->recordFeedback($interviewRound, $request->validated());

        return response()->json($updated);
    }

    public function cancel(Request $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);
        $this->assertCanManage($request);

        $updated = $this->interviewService->cancel($interviewRound, $request->input('reason'));

        return response()->json($updated);
    }

    public function generateMeetLink(Request $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);
        $this->assertCanManage($request);

        $link = $this->interviewService->generateMeetLink($interviewRound);

        return response()->json(['meet_link' => $link]);
    }

    public function sendNotification(SendInterviewNotificationRequest $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);
        $this->assertCanManage($request);

        $data = $request->validated();
        // Optional edited subject/body from the Email Preview popup (feature 6).
        $override = array_filter([
            'subject' => $data['subject'] ?? null,
            'body'    => $data['body'] ?? null,
        ]);
        $this->interviewService->sendNotification($interviewRound, $data['type'], $override ?: null);

        return response()->json(['success' => true, 'type' => $data['type']]);
    }

    /**
     * Interview panel members by type — reuses existing CRM users (no new table,
     * no duplication). role: staff | client | vendor | third_party_vendor.
     * SPK-1: Client/Vendor/TPV are picked in two steps — first the organisation
     * (reuses the existing users.company field), then that organisation's users —
     * so an optional `company` filter narrows the list to the chosen org.
     */
    public function panelUsers(Request $request)
    {
        $this->assertCanManage($request);
        $data = $request->validate([
            'role'    => 'required|in:staff,client,vendor,third_party_vendor',
            'company' => 'nullable|string',
        ]);

        $users = User::where('tenant_id', $request->user()->tenant_id)
            ->where('role', $data['role'])
            ->where('status', '!=', 'disabled')
            ->when(! empty($data['company']), fn ($q) => $q->where('company', $data['company']))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'department', 'company', 'vendor_type', 'tpv_type']);

        return response()->json($users);
    }

    /**
     * SPK-1: organisations (Client / Vendor / Third-Party-Vendor) for the panel
     * picker — distinct `company` values among that role's users. Internal Staff
     * needs no org step, so it is not called for staff.
     */
    public function panelOrganizations(Request $request)
    {
        $this->assertCanManage($request);
        $role = $request->validate([
            'role' => 'required|in:client,vendor,third_party_vendor',
        ])['role'];

        $orgs = User::where('tenant_id', $request->user()->tenant_id)
            ->where('role', $role)
            ->where('status', '!=', 'disabled')
            ->whereNotNull('company')
            ->where('company', '!=', '')
            ->selectRaw('company, COUNT(*) as members')
            ->groupBy('company')
            ->orderBy('company')
            ->get();

        return response()->json($orgs);
    }

    /**
     * Render the existing interview email template for the Email Preview popup.
     * Returns the default subject + HTML body so HR can edit before sending.
     * The stored blade templates are never modified.
     */
    public function emailPreview(Request $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);
        $this->assertCanManage($request);

        $recipient = $request->query('type') === 'interviewer' ? 'interviewer' : 'candidate';
        $mailable  = new \App\Mail\InterviewScheduledMail($interviewRound->load('candidate'), $recipient);

        return response()->json([
            'subject' => $mailable->envelope()->subject,
            'body'    => $mailable->render(),
        ]);
    }

    public function destroy(Request $request, HrInterviewRound $interviewRound)
    {
        $this->assertTenant($request, $interviewRound);
        $this->assertCanManage($request);

        $this->interviewService->destroy($interviewRound);

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Tenant guard for route-model-bound rounds. The round's own tenant_id can be
     * null on legacy rows, so we authorise against the parent candidate's tenant.
     */
    private function assertTenant(Request $request, HrInterviewRound $interviewRound): void
    {
        $tenantId = $interviewRound->tenant_id ?? $interviewRound->candidate?->tenant_id;
        abort_unless((int) $tenantId === (int) $request->user()->tenant_id, 404, 'Interview not found');
    }

    private function assertCanManage(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage interviews');
    }
}
