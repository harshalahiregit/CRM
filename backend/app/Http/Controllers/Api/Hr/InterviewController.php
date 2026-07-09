<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\RecordInterviewFeedbackRequest;
use App\Http\Requests\Hr\SendInterviewNotificationRequest;
use App\Http\Requests\Hr\StoreInterviewRequest;
use App\Models\HrInterviewRound;
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
            $this->interviewService->list($request->user()->tenant_id, $request->only(['status', 'date', 'today', 'candidate_id']))
        );
    }

    public function store(StoreInterviewRequest $request)
    {
        $round = $this->interviewService->create($request->validated(), $request->user()->tenant_id);

        return response()->json($round, 201);
    }

    public function show(HrInterviewRound $interviewRound)
    {
        return response()->json($interviewRound->load('candidate'));
    }

    public function recordFeedback(RecordInterviewFeedbackRequest $request, HrInterviewRound $interviewRound)
    {
        $updated = $this->interviewService->recordFeedback($interviewRound, $request->validated());

        return response()->json($updated);
    }

    public function generateMeetLink(Request $request, HrInterviewRound $interviewRound)
    {
        $link = $this->interviewService->generateMeetLink($interviewRound);

        return response()->json(['meet_link' => $link]);
    }

    public function sendNotification(SendInterviewNotificationRequest $request, HrInterviewRound $interviewRound)
    {
        $type = $request->validated('type');
        $this->interviewService->sendNotification($interviewRound, $type);

        return response()->json(['success' => true, 'type' => $type]);
    }

    public function destroy(HrInterviewRound $interviewRound)
    {
        $this->interviewService->destroy($interviewRound);

        return response()->json(['message' => 'Deleted']);
    }
}
