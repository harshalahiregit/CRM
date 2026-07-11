<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\RecordInterviewFeedbackRequest;
use App\Http\Requests\Hr\SendInterviewNotificationRequest;
use App\Http\Requests\Hr\StoreInterviewRequest;
use App\Http\Requests\Hr\UpdateInterviewRequest;
use App\Models\Hr\HrInterviewRound;
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

        return response()->json($interviewRound->load(['candidate', 'auditLogs.actor']));
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

        $type = $request->validated('type');
        $this->interviewService->sendNotification($interviewRound, $type);

        return response()->json(['success' => true, 'type' => $type]);
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
