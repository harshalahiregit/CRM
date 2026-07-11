<?php

namespace App\Services\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrInterviewRound;
use App\Notifications\WhatsApp\InterviewScheduledNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InterviewService
{
    /** Round names that conclude the interview loop → candidate advances to Offer. */
    private const FINAL_ROUND_KEYWORDS = ['final'];

    /** Rounds that don't need a video link even when online. */
    private const TELEPHONIC_ROUNDS = ['HR Telephonic', 'Telephonic', 'Phone Screen', 'Telephonic Round'];

    public function __construct(
        private CandidateService $candidateService,
        private OnboardingService $onboardingService,
    ) {
    }

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
        if (! empty($filters['job_posting_id'])) {
            $query->whereHas('candidate', fn ($q) => $q->where('job_posting_id', $filters['job_posting_id']));
        }

        // Newest/most-recently-scheduled first, so freshly booked interviews are
        // immediately visible at the top instead of being buried under old rounds.
        return $query->orderByDesc('scheduled_at')->orderByDesc('id')->get();
    }

    /** Dashboard widgets: Today / Upcoming / Completed / Pending Feedback. */
    public function stats(int $tenantId): array
    {
        $base = fn () => HrInterviewRound::whereHas('candidate', fn ($q) => $q->where('tenant_id', $tenantId));

        return [
            'today'            => $base()->whereDate('scheduled_at', Carbon::today())->count(),
            'upcoming'         => $base()->where('scheduled_at', '>', now())->where('status', 'Scheduled')->count(),
            'completed'        => $base()->where('status', 'Completed')->count(),
            'pending_feedback' => $base()->where('status', '!=', 'Cancelled')
                ->where('result', 'Pending')->where('scheduled_at', '<', now())->count(),
        ];
    }

    public function create(array $data, int $tenantId): HrInterviewRound
    {
        $candidate = HrCandidate::where('id', $data['candidate_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $data['tenant_id'] = $tenantId;
        $mode = $data['mode'] ?? 'online';

        // Online rounds (except telephonic) auto-get a Meet link; offline rounds use a venue.
        if ($mode === 'offline') {
            $data['meet_link'] = null;
        } elseif (empty($data['meet_link']) && ! in_array($data['round_name'], self::TELEPHONIC_ROUNDS, true)) {
            $data['meet_link'] = $this->randomMeetLink();
        }

        $round = HrInterviewRound::create($data);

        // Interview-level audit timeline.
        $round->recordAudit('Scheduled', null, null, array_filter([
            'round'        => $round->round_name,
            'mode'         => $mode,
            'scheduled_at' => optional($round->scheduled_at)->toDateTimeString(),
            'interviewer'  => $round->interviewer_name,
            'venue'        => $round->venue,
        ]));

        // Advance the candidate to Interview (if earlier) and log on their timeline.
        $oldStage = $candidate->stage;
        if (in_array($oldStage, ['Applied', 'Screening', 'Assessment'], true)) {
            $candidate->update(['stage' => 'Interview']);
        }
        $candidate->recordAudit('Interview Scheduled: '.$round->round_name, null, null, array_filter([
            'round'        => $round->round_name,
            'interviewer'  => $round->interviewer_name,
            'scheduled_at' => optional($round->scheduled_at)->toDateTimeString(),
            'from'         => $oldStage !== $candidate->stage ? $oldStage : null,
            'to'           => $oldStage !== $candidate->stage ? $candidate->stage : null,
        ]));

        // Email is best-effort — a mail failure must never break scheduling.
        if ($candidate->email) {
            try {
                Mail::to($candidate->email)->send(new \App\Mail\InterviewScheduledMail($round, 'candidate'));
                $round->update(['email_sent_candidate' => true]);
            } catch (\Throwable $e) {
                Log::channel('hr')->error('Interview schedule email failed', ['interview_round_id' => $round->id, 'error' => $e->getMessage()]);
            }
        }

        InterviewScheduledNotification::send($round);

        Log::channel('hr')->info('Interview round created', ['interview_round_id' => $round->id, 'tenant_id' => $tenantId, 'candidate_id' => $candidate->id]);

        return $round->load('candidate');
    }

    public function recordFeedback(HrInterviewRound $interviewRound, array $input): HrInterviewRound
    {
        $data = [
            'result' => $input['result'],
            'notes'  => $input['notes'] ?? $interviewRound->notes,
            'status' => $input['status'] ?? 'Completed',
        ];

        foreach (['technical_score', 'communication_score', 'problem_solving_score', 'rating', 'recommendation'] as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                $data[$field] = $input[$field];
            }
        }

        $t = $input['technical_score']       ?? $interviewRound->technical_score       ?? 0;
        $c = $input['communication_score']   ?? $interviewRound->communication_score   ?? 0;
        $p = $input['problem_solving_score'] ?? $interviewRound->problem_solving_score ?? 0;
        if ($t || $c || $p) {
            $data['overall_score'] = round((($t + $c + $p) / 3) * 10, 2);
        }

        $interviewRound->update($data);

        // Interview-level audit.
        $interviewRound->recordAudit('Feedback: '.$input['result'], null, $input['notes'] ?? null, array_filter([
            'result'         => $input['result'],
            'recommendation' => $data['recommendation'] ?? null,
            'rating'         => $data['rating'] ?? null,
            'overall_score'  => $data['overall_score'] ?? null,
        ]));

        // Mirror the outcome to the candidate + auto-advance the pipeline.
        $this->applyOutcomeToCandidate($interviewRound);

        Log::channel('hr')->info('Interview feedback recorded', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id, 'result' => $input['result']]);

        return $interviewRound->fresh()->load('candidate');
    }

    /** Reschedule / edit an interview round. */
    public function reschedule(HrInterviewRound $interviewRound, array $input): HrInterviewRound
    {
        $changes = array_intersect_key($input, array_flip([
            'round_name', 'mode', 'interviewer_name', 'interviewers',
            'scheduled_at', 'meet_link', 'venue', 'reminder_minutes',
        ]));

        // Offline rounds have no meet link; online rounds keep/receive one.
        $mode = $changes['mode'] ?? $interviewRound->mode;
        if ($mode === 'offline') {
            $changes['meet_link'] = null;
        }

        $changes['status'] = 'Scheduled'; // rescheduling re-opens the round
        $interviewRound->update($changes);

        $interviewRound->recordAudit('Rescheduled', null, null, array_filter([
            'scheduled_at' => optional($interviewRound->scheduled_at)->toDateTimeString(),
            'mode'         => $interviewRound->mode,
            'venue'        => $interviewRound->venue,
        ]));
        optional($interviewRound->candidate)->recordAudit('Interview rescheduled: '.$interviewRound->round_name, null, null, array_filter([
            'scheduled_at' => optional($interviewRound->scheduled_at)->toDateTimeString(),
        ]));

        Log::channel('hr')->info('Interview rescheduled', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id]);

        return $interviewRound->fresh()->load('candidate');
    }

    public function cancel(HrInterviewRound $interviewRound, ?string $reason = null): HrInterviewRound
    {
        $interviewRound->update(['status' => 'Cancelled']);

        $interviewRound->recordAudit('Cancelled', null, $reason);
        optional($interviewRound->candidate)->recordAudit('Interview cancelled: '.$interviewRound->round_name, null, $reason);

        Log::channel('hr')->info('Interview cancelled', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id]);

        return $interviewRound->fresh()->load('candidate');
    }

    /**
     * Mirror a round's result onto the candidate timeline and auto-advance the
     * pipeline on success. Stage moves reuse CandidateService (forward-only clamp,
     * candidate audit, notifications) and never break feedback if they can't apply.
     */
    private function applyOutcomeToCandidate(HrInterviewRound $round): void
    {
        $candidate = $round->candidate;
        if (! $candidate) {
            return;
        }

        $result = $round->result;

        $candidate->recordAudit('Interview '.$round->round_name.': '.$result, null, null, array_filter([
            'round'          => $round->round_name,
            'result'         => $result,
            'recommendation' => $round->recommendation,
        ]));

        // Enterprise ATS auto-transition. All stage moves reuse CandidateService
        // (forward-only clamp, candidate audit, notifications) and never break
        // feedback if they can't apply.
        //   Failed                      → Rejected (auto)
        //   Passed + Final round        → Selected + start Onboarding (before Offer)
        //   Passed (non-final) / Next Round → stays in Interview (schedule next round)
        //   On Hold                     → stays in Interview (candidate active)
        try {
            if ($result === 'Failed') {
                $this->candidateService->updateStage($candidate, 'Rejected');
            } elseif ($result === 'Passed' && $this->isFinalRound($round->round_name)) {
                // Selected: congratulations + candidate onboarding starts BEFORE the offer.
                $this->onboardingService->startForCandidate($candidate);
            } elseif (in_array($result, ['Passed', 'Next Round'], true)
                && in_array($candidate->stage, ['Applied', 'Screening', 'Assessment'], true)) {
                $this->candidateService->updateStage($candidate, 'Interview');
            }
        } catch (\Throwable $e) {
            // Backward move / already advanced — safe to ignore; feedback still stands.
            Log::channel('hr')->info('Interview auto-advance skipped', ['interview_round_id' => $round->id, 'reason' => $e->getMessage()]);
        }
    }

    private function isFinalRound(string $roundName): bool
    {
        $name = strtolower($roundName);
        foreach (self::FINAL_ROUND_KEYWORDS as $keyword) {
            if (str_contains($name, $keyword)) {
                return true;
            }
        }

        return false;
    }

    public function generateMeetLink(HrInterviewRound $interviewRound): string
    {
        $link = $this->randomMeetLink();
        $interviewRound->update(['meet_link' => $link]);

        Log::channel('hr')->info('Interview meet link generated', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id]);

        return $link;
    }

    private function randomMeetLink(): string
    {
        $code = strtolower(Str::random(3).'-'.Str::random(4).'-'.Str::random(3));

        return "https://meet.google.com/{$code}";
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
        optional($interviewRound->candidate)->recordAudit('Interview removed: '.$interviewRound->round_name);

        $interviewRound->auditLogs()->delete();
        $interviewRound->delete();

        Log::channel('hr')->info('Interview round deleted', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id]);
    }
}
