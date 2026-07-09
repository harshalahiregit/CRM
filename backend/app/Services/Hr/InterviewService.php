<?php

namespace App\Services\Hr;

use App\Models\HrCandidate;
use App\Models\HrInterviewRound;
use App\Notifications\WhatsApp\InterviewScheduledNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InterviewService
{
    public function list(int $tenantId, array $filters): Collection
    {
        $query = HrInterviewRound::with('candidate')
            ->whereHas('candidate', function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId);
            });

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['date'])) {
            $query->whereDate('scheduled_at', $filters['date']);
        }
        if (! empty($filters['today']) && $filters['today'] === 'true') {
            $query->whereDate('scheduled_at', Carbon::today());
        }
        if (! empty($filters['candidate_id'])) {
            $query->where('candidate_id', $filters['candidate_id']);
        }

        return $query->orderBy('scheduled_at')->get();
    }

    public function create(array $data, int $tenantId): HrInterviewRound
    {
        $candidate = HrCandidate::where('id', $data['candidate_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        if (empty($data['meet_link'])) {
            $telephonicRounds = ['HR Telephonic', 'Telephonic', 'Phone Screen', 'Telephonic Round'];

            if (! in_array($data['round_name'], $telephonicRounds)) {
                $code = strtolower(Str::random(3).'-'.Str::random(4).'-'.Str::random(3));
                $data['meet_link'] = "https://meet.google.com/{$code}";
            }
        }

        $round = HrInterviewRound::create($data);

        HrCandidate::where('id', $data['candidate_id'])
            ->where('tenant_id', $tenantId)
            ->whereIn('stage', ['Applied', 'Screening', 'Assessment'])
            ->update(['stage' => 'Interview']);

        if ($candidate->email) {
            Mail::to($candidate->email)->send(
                new \App\Mail\InterviewScheduledMail($round, 'candidate')
            );
            $round->update(['email_sent_candidate' => true]);
        }

        InterviewScheduledNotification::send($round);

        Log::channel('hr')->info('Interview round created', ['interview_round_id' => $round->id, 'tenant_id' => $tenantId, 'candidate_id' => $candidate->id]);

        return $round->load('candidate');
    }

    public function recordFeedback(HrInterviewRound $interviewRound, array $input): HrInterviewRound
    {
        $data = [
            'result' => $input['result'],
            'notes'  => $input['notes'] ?? null,
            'status' => $input['status'] ?? 'Completed',
        ];

        if (isset($input['technical_score']))       $data['technical_score']       = $input['technical_score'];
        if (isset($input['communication_score']))   $data['communication_score']   = $input['communication_score'];
        if (isset($input['problem_solving_score'])) $data['problem_solving_score'] = $input['problem_solving_score'];

        $t = $input['technical_score']       ?? $interviewRound->technical_score       ?? 0;
        $c = $input['communication_score']   ?? $interviewRound->communication_score   ?? 0;
        $p = $input['problem_solving_score'] ?? $interviewRound->problem_solving_score ?? 0;
        if ($t || $c || $p) {
            $data['overall_score'] = round((($t + $c + $p) / 3) * 10, 2);
        }

        $interviewRound->update($data);

        Log::channel('hr')->info('Interview feedback recorded', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id, 'result' => $input['result']]);

        return $interviewRound;
    }

    public function generateMeetLink(HrInterviewRound $interviewRound): string
    {
        $code = strtolower(Str::random(3).'-'.Str::random(4).'-'.Str::random(3));
        $link = "https://meet.google.com/{$code}";
        $interviewRound->update(['meet_link' => $link]);

        Log::channel('hr')->info('Interview meet link generated', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id]);

        return $link;
    }

    public function sendNotification(HrInterviewRound $interviewRound, string $type): void
    {
        $map = [
            'email_candidate'   => 'email_sent_candidate',
            'email_interviewer' => 'email_sent_interviewer',
            'whatsapp'          => 'whatsapp_sent',
            'calendar'          => 'calendar_event_created',
        ];

        if ($type === 'email_candidate' && $interviewRound->candidate && $interviewRound->candidate->email) {
            Mail::to($interviewRound->candidate->email)->send(
                new \App\Mail\InterviewScheduledMail($interviewRound, 'candidate')
            );
        }

        if ($type === 'email_interviewer' && $interviewRound->interviewer_email) {
            Mail::to($interviewRound->interviewer_email)->send(
                new \App\Mail\InterviewScheduledMail($interviewRound, 'interviewer')
            );
        }

        if ($type === 'whatsapp') {
            InterviewScheduledNotification::send($interviewRound);
        }

        $interviewRound->update([$map[$type] => true]);

        Log::channel('hr')->info('Interview notification sent', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id, 'type' => $type]);
    }

    public function destroy(HrInterviewRound $interviewRound): void
    {
        $interviewRound->delete();

        Log::channel('hr')->info('Interview round deleted', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id]);
    }
}
