<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\HrInterviewRound;
use App\Models\HrCandidate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InterviewController extends Controller
{
    public function index(Request $request)
    {
        $query = HrInterviewRound::with('candidate');

        if ($request->filled('status') && $request->status !== 'All') {
            $query->where('status', $request->status);
        }
        if ($request->filled('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }
        if ($request->filled('today') && $request->today === 'true') {
            $query->whereDate('scheduled_at', Carbon::today());
        }
        if ($request->filled('candidate_id')) {
            $query->where('candidate_id', $request->candidate_id);
        }

        return response()->json($query->orderBy('scheduled_at')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'candidate_id'     => 'required|exists:hr_candidates,id',
            'round_name'       => 'required|string',
            'interviewer_name' => 'nullable|string',
            'scheduled_at'     => 'required|date',
            'meet_link'        => 'nullable|url',
        ]);

        // Auto-generate Google Meet link if not provided
        if (empty($validated['meet_link'])) {
            $code = strtolower(Str::random(3).'-'.Str::random(4).'-'.Str::random(3));
            $validated['meet_link'] = "https://meet.google.com/{$code}";
        }

        $round = HrInterviewRound::create($validated);

        // Move candidate to Interview stage
        HrCandidate::where('id', $validated['candidate_id'])
            ->whereIn('stage', ['Applied','Screening','Assessment'])
            ->update(['stage' => 'Interview']);

        return response()->json($round->load('candidate'), 201);
    }

    public function show(HrInterviewRound $interviewRound)
    {
        return response()->json($interviewRound->load('candidate'));
    }

    public function recordFeedback(Request $request, HrInterviewRound $interviewRound)
    {
        $request->validate([
            'result'                 => 'required|in:Pending,Passed,Failed,On Hold',
            'notes'                  => 'nullable|string',
            'status'                 => 'in:Scheduled,Completed,Cancelled,Rescheduled',
            'technical_score'        => 'nullable|integer|min:0|max:10',
            'communication_score'    => 'nullable|integer|min:0|max:10',
            'problem_solving_score'  => 'nullable|integer|min:0|max:10',
        ]);

        $data = [
            'result' => $request->result,
            'notes'  => $request->notes,
            'status' => $request->status ?? 'Completed',
        ];

        if ($request->filled('technical_score'))       $data['technical_score']       = $request->technical_score;
        if ($request->filled('communication_score'))   $data['communication_score']   = $request->communication_score;
        if ($request->filled('problem_solving_score')) $data['problem_solving_score'] = $request->problem_solving_score;

        // Auto-calculate overall score (average of 3 scores on 10-point scale, convert to 100)
        $t = $request->technical_score       ?? $interviewRound->technical_score       ?? 0;
        $c = $request->communication_score   ?? $interviewRound->communication_score   ?? 0;
        $p = $request->problem_solving_score ?? $interviewRound->problem_solving_score ?? 0;
        if ($t || $c || $p) {
            $data['overall_score'] = round((($t + $c + $p) / 3) * 10, 2); // out of 100
        }

        $interviewRound->update($data);

        return response()->json($interviewRound);
    }


    public function generateMeetLink(Request $request, HrInterviewRound $interviewRound)
    {
        $code = strtolower(Str::random(3).'-'.Str::random(4).'-'.Str::random(3));
        $link = "https://meet.google.com/{$code}";
        $interviewRound->update(['meet_link' => $link]);
        return response()->json(['meet_link' => $link]);
    }

    public function sendNotification(Request $request, HrInterviewRound $interviewRound)
    {
        $request->validate(['type' => 'required|in:email_candidate,email_interviewer,whatsapp,calendar']);

        $map = [
            'email_candidate'   => 'email_sent_candidate',
            'email_interviewer' => 'email_sent_interviewer',
            'whatsapp'          => 'whatsapp_sent',
            'calendar'          => 'calendar_event_created',
        ];

        $interviewRound->update([$map[$request->type] => true]);
        return response()->json(['success' => true, 'type' => $request->type]);
    }

    public function destroy(HrInterviewRound $interviewRound)
    {
        $interviewRound->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
