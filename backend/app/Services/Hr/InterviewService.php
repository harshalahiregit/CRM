<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Jobs\Hr\RecalculateCandidateScore;
use App\Models\Hr\HrInterviewRound;
use App\Notifications\WhatsApp\InterviewScheduledNotification;
use App\Support\Hr\InterviewSequence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InterviewService
{
    /** Rounds that don't need a video link even when online. */
    private const TELEPHONIC_ROUNDS = ['HR Telephonic', 'Telephonic', 'Phone Screen', 'Telephonic Round'];

    public function __construct(
        private CandidateService $candidateService,
        private OnboardingService $onboardingService,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        // candidate.jobPosting powers the "Job Title" column in the list view;
        // a light (id, title) select keeps the list payload small.
        $query = HrInterviewRound::with(['candidate', 'candidate.jobPosting:id,title'])
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

        $this->assertSequenceAllows($candidate, $data['round_name']);

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

    /**
     * SPK-1: a later round cannot be scheduled while an earlier one is still open.
     *
     * Only rounds the candidate ALREADY has are considered — the sequence is not
     * forced to be complete, because plenty of hires legitimately skip a round
     * (e.g. no Client Round). What this blocks is scheduling Manager while
     * Technical is still awaiting its result. Cancelled rounds are ignored, and
     * an unsequenced custom round name never blocks anything.
     */
    private function assertSequenceAllows(HrCandidate $candidate, ?string $roundName): void
    {
        $target = InterviewSequence::indexOf($roundName);
        if ($target === null) {
            return;   // custom/unsequenced round — nothing to enforce against
        }

        $blocking = $candidate->interviewRounds()
            ->where('status', '!=', 'Cancelled')
            ->get()
            ->filter(function ($r) use ($target) {
                $i = InterviewSequence::indexOf($r->round_name);

                return $i !== null && $i < $target && $r->status !== 'Completed';
            });

        if ($blocking->isNotEmpty()) {
            $names = $blocking->pluck('round_name')->unique()->implode(', ');

            throw new BusinessException(
                "Complete the earlier round(s) first: {$names}. ".
                'A later round cannot be scheduled while an earlier one is still pending.',
                422
            );
        }
    }

    public function recordFeedback(HrInterviewRound $interviewRound, array $input): HrInterviewRound
    {
        // Immutability: a terminally-completed round can never be overwritten. The one
        // exception is an "On Hold" round, which the existing design deliberately keeps
        // reversible (resume / re-evaluate), so editing a hold is still allowed.
        if ($interviewRound->status === 'Completed' && $interviewRound->result !== 'On Hold') {
            throw new BusinessException('This interview is already completed and cannot be modified.', 422);
        }

        $status = $input['status'] ?? 'Completed';

        $data = [
            'result' => $input['result'],
            'notes'  => $input['notes'] ?? $interviewRound->notes,
            'status' => $status,
        ];

        foreach ([
            'technical_score', 'communication_score', 'problem_solving_score', 'rating', 'recommendation',
            // Structured interviewer ratings (Doc 3 Module 6) — recorded alongside
            // the existing scores; the overall_score formula is left unchanged.
            'knowledge_score', 'confidence_score', 'ownership_score', 'learning_ability_score',
            'decision_making_score', 'leadership_score', 'integrity_score', 'culture_fit_score',
            'strengths', 'concerns',
            // Completion metadata (Complete-Interview form): attendance / duration / remarks.
            'attendance', 'duration', 'remarks',
        ] as $field) {
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

        // Stamp who closed the round and when, whenever it lands as Completed.
        if ($status === 'Completed') {
            $data['completed_at'] = now();
            $data['completed_by'] = auth()->id();
        }

        $interviewRound->update($data);

        // Interview-level audit.
        // Label only — a skipped optional round reads as "<Round> Skipped" on the
        // timeline instead of a feedback entry. No behaviour changes.
        $auditAction = $input['result'] === 'Skipped'
            ? $interviewRound->round_name.' Skipped'
            : 'Feedback: '.$input['result'];

        $interviewRound->recordAudit($auditAction, null, $input['notes'] ?? null, array_filter([
            'result'         => $input['result'],
            'recommendation' => $data['recommendation'] ?? null,
            'rating'         => $data['rating'] ?? null,
            'overall_score'  => $data['overall_score'] ?? null,
        ]));

        // Mirror the outcome to the candidate + auto-advance the pipeline.
        $this->applyOutcomeToCandidate($interviewRound);

        Log::channel('hr')->info('Interview feedback recorded', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id, 'result' => $input['result']]);

        // Interview feedback is a scoring input (InterviewDimension). Keyed off THIS
        // business event, not completed_at -- that column is NULL on every existing
        // round, so keying off it would silently never fire.
        if ($interviewRound->candidate_id) {
            RecalculateCandidateScore::dispatch(
                $interviewRound->candidate_id,
                $interviewRound->tenant_id,
                RecalculateCandidateScore::TRIGGER_INTERVIEW_COMPLETED
            );
        }

        return $interviewRound->fresh()->load('candidate');
    }

    /** Reschedule / edit an interview round. */
    public function reschedule(HrInterviewRound $interviewRound, array $input): HrInterviewRound
    {
        // A completed interview is immutable and cannot be edited/rescheduled. An
        // "On Hold" round stays resumable (that is how a hold is un-paused).
        if ($interviewRound->status === 'Completed' && $interviewRound->result !== 'On Hold') {
            throw new BusinessException('A completed interview cannot be edited or rescheduled.', 422);
        }

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
        // A completed interview is final — it cannot be cancelled after the fact.
        if ($interviewRound->status === 'Completed') {
            throw new BusinessException('A completed interview cannot be cancelled.', 422);
        }

        // Cancelling always requires a reason; it is persisted AND audited.
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new BusinessException('A reason is required to cancel an interview.', 422);
        }

        $interviewRound->update(['status' => 'Cancelled', 'cancellation_reason' => $reason]);

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

        // Enterprise ATS auto-transition. "Passed" is the selection outcome (no
        // reliance on round naming); "Next Round" keeps the candidate in the
        // interview loop for another round.
        //   Passed     → Selected + start Onboarding (before Offer)
        //   Next Round → stays in Interview (schedule the next round)
        //   On Hold    → stays in Interview (candidate active)
        //   Failed     → Rejected (auto)
        // startForCandidate() is idempotent, so re-recording feedback is safe.
        try {
            if ($result === 'Failed') {
                $this->candidateService->updateStage($candidate, 'Rejected');
            } elseif ($result === 'Passed') {
                // Selected: congratulations + candidate onboarding starts BEFORE the offer.
                $this->onboardingService->startForCandidate($candidate);
            } elseif ($result === 'On Hold') {
                // Hold pauses the pipeline: mark the candidate On Hold (final_decision='Hold'),
                // keeping them in the Interview stage. Reversible — resuming re-opens the round.
                $this->candidateService->updateDecision($candidate, 'Hold');
            } elseif ($result === 'Next Round') {
                // Progressing again clears any stale Hold decision.
                if ($candidate->final_decision === 'Hold') {
                    $this->candidateService->updateDecision($candidate, 'Pending');
                }
                if (in_array($candidate->stage, ['Applied', 'Screening', 'Assessment'], true)) {
                    $this->candidateService->updateStage($candidate, 'Interview');
                }
            }
        } catch (\Throwable $e) {
            // Do NOT swallow: the feedback itself is saved, but the recruiter must be
            // told that the follow-up transition (onboarding / stage) did not apply,
            // otherwise the candidate silently stalls in the Interview stage.
            Log::channel('hr')->error('Interview outcome transition failed', ['interview_round_id' => $round->id, 'result' => $result, 'error' => $e->getMessage()]);

            throw new BusinessException(
                'Feedback was saved, but the follow-up step failed: '.$e->getMessage(),
                422
            );
        }
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

    /**
     * @param  array|null  $override  optional ['subject'=>..,'body'=>..] edited in
     *   the Email Preview popup. When present the edited HTML is sent instead of the
     *   default template — the stored blade templates are never modified.
     */
    public function sendNotification(HrInterviewRound $interviewRound, string $type, ?array $override = null): void
    {
        $map = [
            'email_candidate'   => 'email_sent_candidate',
            'email_interviewer' => 'email_sent_interviewer',
            'whatsapp'          => 'whatsapp_sent',
            'calendar'          => 'calendar_event_created',
        ];

        if ($type === 'email_candidate' && $interviewRound->candidate && $interviewRound->candidate->email) {
            $this->sendEmail($interviewRound->candidate->email, $interviewRound, 'candidate', $override);
        }

        if ($type === 'email_interviewer' && $interviewRound->interviewer_email) {
            $this->sendEmail($interviewRound->interviewer_email, $interviewRound, 'interviewer', $override);
        }

        if ($type === 'whatsapp') {
            InterviewScheduledNotification::send($interviewRound);
        }

        $interviewRound->update([$map[$type] => true]);

        Log::channel('hr')->info('Interview notification sent', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id, 'type' => $type]);
    }

    /** Send the interview email — edited content if provided, else the default template. */
    private function sendEmail(string $to, HrInterviewRound $round, string $recipient, ?array $override): void
    {
        if (! empty($override['body'])) {
            $subject = $override['subject'] ?? ('Interview Scheduled - '.$round->round_name);
            Mail::html($override['body'], function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject);
            });

            return;
        }

        Mail::to($to)->send(new \App\Mail\InterviewScheduledMail($round, $recipient));
    }

    public function destroy(HrInterviewRound $interviewRound): void
    {
        optional($interviewRound->candidate)->recordAudit('Interview removed: '.$interviewRound->round_name);

        $interviewRound->auditLogs()->delete();
        $interviewRound->delete();

        Log::channel('hr')->info('Interview round deleted', ['interview_round_id' => $interviewRound->id, 'tenant_id' => $interviewRound->tenant_id]);
    }
}
