<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Jobs\Hr\RecalculateCandidateScore;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrJobPosting;
use App\Models\User;
use App\Support\Hr\ManpowerRequestStatus;
use App\Notifications\WhatsApp\ApplicationReceivedNotification;
use App\Notifications\WhatsApp\StatusUpdateNotification;
use App\Repositories\Hr\CandidateRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CandidateService
{
    /** Stages driven by the recruitment workflow — never set by a manual move. */
    public const SYSTEM_CONTROLLED_STAGES = ['Offer', 'Hired'];

    public function __construct(private CandidateRepository $candidateRepository)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->candidateRepository->filtered($tenantId, $filters);
    }

    public function create(array $data, int $tenantId): HrCandidate
    {
        $data['tenant_id'] = $tenantId;

        if (! empty($data['email']) && ! empty($data['job_posting_id'])) {
            $exists = $this->candidateRepository->emailExistsForJobPosting($data['email'], $data['job_posting_id'], $tenantId);
            if ($exists) {
                throw new BusinessException('A candidate with this email already applied for this job.', 422);
            }
        }

        // No score is computed here. A client-supplied ai_score is stripped so the
        // record starts unscored and the engine is the only thing that fills it in.
        unset($data['ai_score'], $data['ai_breakdown']);

        // STEP 6: the candidate inherits the Project from its Job Posting — never a
        // manual selection. Any client-supplied project_id is ignored.
        $data['project_id'] = ! empty($data['job_posting_id'])
            ? HrJobPosting::where('id', $data['job_posting_id'])->value('project_id')
            : null;

        $candidate = HrCandidate::create($data);

        if ($candidate->job_posting_id) {
            HrJobPosting::where('id', $candidate->job_posting_id)->increment('applicant_count');
        }

        if ($candidate->email) {
            Mail::to($candidate->email)->send(
                new \App\Mail\ApplicationReceivedMail($candidate->load('jobPosting'))
            );
        }

        ApplicationReceivedNotification::send($candidate);

        // Timeline: opens the candidate's audit trail. Actor auto-resolves to the
        // authenticated HR user, or "System" for public Career-Portal applies.
        $candidate->recordAudit('Applied', null, null, array_filter([
            'source' => $candidate->source,
            'stage'  => $candidate->stage,
            'job'    => $candidate->jobPosting?->title,
        ]));

        Log::channel('hr')->info('Candidate created', ['candidate_id' => $candidate->id, 'tenant_id' => $tenantId]);

        // Queued, never inline: ten dimension evaluations do not belong on the request
        // thread of a create. The candidate is simply unscored until the worker runs.
        RecalculateCandidateScore::dispatch(
            $candidate->id, $tenantId, RecalculateCandidateScore::TRIGGER_CANDIDATE_CREATED
        );

        return $candidate;
    }

    /**
     * Candidate columns the scoring engine reads. A change to any of these
     * invalidates the stored score; anything else (phone, notes, WhatsApp opt-in)
     * leaves it valid. Keep in step with the Dimensions/ classes.
     */
    private const SCORING_INPUTS = [
        'skills', 'experience_years', 'education', 'location',
        'expected_ctc', 'notice_period', 'resume_path', 'screening_answers',
    ];

    /** Fields the generic update endpoint may never set — they are tenant/pipeline
     *  controlled and were previously mass-assignable via PUT /candidates/{id}. */
    private const UPDATE_PROTECTED = [
        'id', 'tenant_id', 'job_posting_id', 'project_id', 'stage', 'final_decision',
        'ai_score', 'applied_at', 'assigned_recruiter_id', 'created_at', 'updated_at',
    ];

    public function update(HrCandidate $candidate, array $data): HrCandidate
    {
        // Mass-assignment protection: strip tenant/pipeline/scoring keys so the generic
        // editor can never move a candidate to another tenant, jump the stage, or forge
        // the AI score. Stage moves go through updateStage(); decisions via updateDecision().
        $data = array_diff_key($data, array_flip(self::UPDATE_PROTECTED));

        $candidate->update($data);

        // Re-score only when an input the engine actually reads has moved. Editing a
        // phone number must not queue ten dimension evaluations; editing skills must.
        // wasChanged() is asked AFTER the save, so a no-op write dispatches nothing.
        if ($candidate->wasChanged(self::SCORING_INPUTS)) {
            RecalculateCandidateScore::dispatch(
                $candidate->id, $candidate->tenant_id, RecalculateCandidateScore::TRIGGER_CANDIDATE_UPDATED
            );
        }

        Log::channel('hr')->info('Candidate updated', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);

        return $candidate->fresh()->load(['jobPosting', 'interviewRounds', 'offer']);
    }

    /**
     * SPK-1 pipeline gates. Rejected is reachable from anywhere; every other move
     * must be one step forward and satisfy that stage's entry condition, so a
     * candidate cannot be dragged past a step that has not actually happened.
     *
     * Offer/Hired are handled by the SYSTEM_CONTROLLED_STAGES guard above — they
     * are reached by onboarding approval and offer acceptance, never by a drag.
     */
    private function assertTransitionAllowed(HrCandidate $candidate, string $stage, array $order): void
    {
        if ($stage === 'Rejected' || $stage === $candidate->stage) {
            return;   // rejection is always allowed; a no-op move is not a transition
        }

        $currentIdx = $order[$candidate->stage] ?? 0;
        $newIdx     = $order[$stage] ?? 0;

        // No skipping — "Applied → Interview" hides the screening that never ran.
        if ($newIdx > $currentIdx + 1) {
            $next = array_search($currentIdx + 1, $order, true);
            throw new BusinessException(
                "Move to {$next} first — stages cannot be skipped.", 422
            );
        }

        // Entry conditions for the stage being moved INTO.
        // Gate on whether the engine RAN, not on the number it published. A
        // low-confidence outcome ("Insufficient Data") is a completed screening --
        // gating on ai_score > 0 stalled those candidates over a data-coverage
        // problem rather than anything about the candidate.
        if ($stage === 'Assessment' && ! $candidate->hasAiScreening()) {
            throw new BusinessException(
                'AI screening has not run for this candidate yet.', 422
            );
        }
    }

    public function updateStage(HrCandidate $candidate, string $stage): HrCandidate
    {
        // Offer & Hired are system-controlled: onboarding approval moves a
        // candidate to Offer, and offer acceptance moves them to Hired (creating
        // the Employee). A manual move to either is never allowed.
        if (in_array($stage, self::SYSTEM_CONTROLLED_STAGES, true)) {
            throw new BusinessException('Offer and Hired stages are managed automatically by the recruitment workflow.', 422);
        }

        $order = ['Applied' => 0, 'Screening' => 1, 'Assessment' => 2, 'Interview' => 3, 'Offer' => 4, 'Hired' => 5, 'Rejected' => 6];
        $currentIdx = $order[$candidate->stage] ?? 0;
        $newIdx     = $order[$stage] ?? 0;
        if ($newIdx < $currentIdx && $stage !== 'Rejected') {
            Log::channel('hr')->warning('Candidate stage change rejected: backward move', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'from' => $candidate->stage, 'to' => $stage]);
            throw new BusinessException('Stage can only move forward in the pipeline.', 422);
        }

        $this->assertTransitionAllowed($candidate, $stage, $order);

        $oldStage = $candidate->stage;
        $candidate->update(['stage' => $stage]);

        if ($oldStage !== $stage) {
            // Audit + log first — the stage change and its timeline entry must
            // persist regardless of whether any downstream notification fails.
            $candidate->recordAudit('Moved to '.$stage, null, null, ['from' => $oldStage, 'to' => $stage]);
            Log::channel('hr')->info('Candidate stage changed', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'from' => $oldStage, 'to' => $stage]);

            StatusUpdateNotification::send($candidate, $stage);

            // Email is best-effort: a mail failure must never roll back the stage
            // change or break the request — log it and carry on.
            if ($candidate->email) {
                try {
                    $statusMessage = match ($stage) {
                        'Rejected' => 'We appreciate your interest and wish you the best in your career endeavors.',
                        'Hired'    => 'Congratulations on successfully completing all rounds!',
                        default    => '',
                    };

                    Mail::to($candidate->email)->send(
                        new \App\Mail\ApplicationStatusMail($candidate->load('jobPosting'), $stage, $statusMessage)
                    );
                } catch (\Throwable $e) {
                    Log::channel('hr')->error('Candidate stage email failed', [
                        'candidate_id' => $candidate->id,
                        'tenant_id'    => $candidate->tenant_id,
                        'stage'        => $stage,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }
        }

        return $candidate;
    }

    public function updateDecision(HrCandidate $candidate, string $decision): HrCandidate
    {
        // 'Selected' promotes the candidate to Hired — legal only once they have reached
        // the Offer stage (an offer exists / was accepted). This blocks the decision
        // endpoint from jumping an early-pipeline candidate straight to Hired, while the
        // legitimate confirm-joining flow (candidate already at Offer) passes cleanly.
        if ($decision === 'Selected' && ! in_array($candidate->stage, ['Offer', 'Hired'], true)) {
            throw new BusinessException('A candidate can only be marked Selected/Hired after an offer has been made.', 422);
        }

        $oldStage = $candidate->stage;
        $candidate->update(['final_decision' => $decision]);

        if ($decision === 'Selected') {
            $candidate->update(['stage' => 'Hired']);
        } elseif ($decision === 'Rejected') {
            $candidate->update(['stage' => 'Rejected']);
        }

        // One audit line captures the decision and any implied stage change, so
        // the timeline reads cleanly (no duplicate "Moved to …" entry).
        $candidate->recordAudit('Decision: '.$decision, null, null, array_filter([
            'decision'   => $decision,
            'from_stage' => $oldStage !== $candidate->stage ? $oldStage : null,
            'to_stage'   => $oldStage !== $candidate->stage ? $candidate->stage : null,
        ]));

        Log::channel('hr')->info('Candidate decision updated', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'decision' => $decision]);

        return $candidate;
    }

    public function destroy(HrCandidate $candidate): void
    {
        $candidate->delete();

        Log::channel('hr')->info('Candidate deleted', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id]);
    }

    /**
     * Assign (or clear, when $recruiterId is null) the owning recruiter. The
     * recruiter must be an assignable user in the candidate's own tenant.
     */
    public function assignRecruiter(HrCandidate $candidate, ?int $recruiterId): HrCandidate
    {
        $recruiter = null;

        if ($recruiterId) {
            $recruiter = $this->assignableRecruiters($candidate->tenant_id)
                ->firstWhere('id', $recruiterId);

            if (! $recruiter) {
                throw new BusinessException('Selected recruiter is not available in this workspace.', 422);
            }
        }

        $candidate->update(['assigned_recruiter_id' => $recruiter?->id]);

        $candidate->recordAudit(
            $recruiter ? 'Assigned to '.$recruiter->name : 'Recruiter unassigned',
            null,
            null,
            array_filter(['recruiter_id' => $recruiter?->id, 'recruiter' => $recruiter?->name])
        );

        Log::channel('hr')->info('Candidate recruiter assigned', ['candidate_id' => $candidate->id, 'tenant_id' => $candidate->tenant_id, 'recruiter_id' => $recruiter?->id]);

        return $candidate->fresh()->load('assignedRecruiter');
    }

    /** Users in a tenant who can own candidates (internal team, active). */
    public function assignableRecruiters(int $tenantId)
    {
        return User::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereIn('role', ['admin', 'staff'])
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'internal_role']);
    }

    // -- AI scoring ------------------------------------------------------
    // Scoring lives in CandidateScoringEngine and is persisted by ScoreRecorder.
    // The six heuristics that used to sit here (computeAiScore, evaluateApplication,
    // scoreEducation, scoreScreeningAnswers, skillReason, educationReason) are gone:
    // two of them wrote hr_candidates.ai_score with different weights, so the same
    // candidate scored differently depending on whether HR typed them in or they
    // applied through the career portal. This service now only DISPATCHES a
    // recalculation; it never computes a score.


    // ── LinkedIn Profile Extractor ─────────────────────────────────────
    public function linkedinParse(string $url): array
    {
        $url = trim($url);

        if (! str_contains($url, 'linkedin.com/in/')) {
            throw new BusinessException('Please provide a valid LinkedIn profile URL (linkedin.com/in/...)', 422);
        }

        try {
            $response = Http::withHeaders([
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ])->timeout(15)->get($url);

            if ($response->failed()) {
                return $this->linkedinFallback($url);
            }

            $html = $response->body();
            $data = $this->parseLinkedInHtml($html, $url);

            return [
                'success' => true,
                'data'    => $data,
                'source'  => 'scraped',
            ];
        } catch (\Exception $e) {
            return $this->linkedinFallback($url);
        }
    }

    private function parseLinkedInHtml(string $html, string $url): array
    {
        $data = [
            'name'            => '',
            'headline'        => '',
            'location'        => '',
            'current_company' => '',
            'skills'          => [],
            'profile_url'     => $url,
        ];

        if (preg_match('/<meta property="og:title" content="([^"]+)"/i', $html, $m)) {
            $ogTitle   = html_entity_decode($m[1]);
            $parts     = explode(' | ', $ogTitle);
            $nameTitle = $parts[0] ?? '';
            if (str_contains($nameTitle, ' - ')) {
                [$name, $rest] = explode(' - ', $nameTitle, 2);
                $data['name'] = trim($name);
                if (str_contains($rest, ' at ')) {
                    [$headline, $company] = explode(' at ', $rest, 2);
                    $data['headline']        = trim($headline);
                    $data['current_company'] = trim($company);
                } else {
                    $data['headline'] = trim($rest);
                }
            } else {
                $data['name'] = trim($nameTitle);
            }
        }

        if (preg_match('/<meta property="og:description" content="([^"]+)"/i', $html, $m)) {
            $desc                = html_entity_decode($m[1]);
            $data['linkedin_summary'] = $desc;
            if (preg_match('/(?:Location|·)\s*([^·]+)/i', $desc, $lm)) {
                $data['location'] = trim($lm[1]);
            }
        }

        if (empty($data['name'])) {
            preg_match('/linkedin\.com\/in\/([^\/\?]+)/i', $url, $slug);
            if (! empty($slug[1])) {
                $data['name'] = ucwords(str_replace(['-', '_'], ' ', $slug[1]));
            }
        }

        return $data;
    }

    private function linkedinFallback(string $url): array
    {
        preg_match('/linkedin\.com\/in\/([^\/\?#]+)/i', $url, $m);
        $slug = $m[1] ?? '';
        $name = ucwords(str_replace(['-', '_'], ' ', $slug));

        return [
            'success' => true,
            'data'    => [
                'name'            => $name,
                'headline'        => '',
                'location'        => '',
                'current_company' => '',
                'skills'          => [],
                'profile_url'     => $url,
            ],
            'source'  => 'url_extract',
            'note'    => 'LinkedIn rate-limited the request. Name extracted from URL. Please fill remaining details manually.',
        ];
    }

    /* ───────────────────────── Candidate Journey (SPK-1) ─────────────────────────
     | Complete recruitment-journey view for one candidate. Read-only aggregation
     | over the EXISTING relations (manpower → job → interviews → onboarding →
     | offer → employee) and audit logs. No new tables, no workflow changes.
     */
    public function journey(HrCandidate $candidate): array
    {
        $candidate->loadMissing([
            'jobPosting.manpowerRequest.requester', 'jobPosting.manpowerRequest.l1Approver', 'jobPosting.manpowerRequest.l2Approver',
            'interviewRounds', 'offer', 'onboarding', 'assignedRecruiter',
            'project:id,name,status',   // display project via the project_id relation (never a stored name)
        ]);

        $job      = $candidate->jobPosting;
        $mr       = $job?->manpowerRequest;
        $onb      = $candidate->onboarding;
        $offer    = $candidate->offer;
        $employee = HrEmployee::where('candidate_id', $candidate->id)->first();
        $rounds   = $candidate->interviewRounds->sortBy('scheduled_at')->values();
        $recruiter = $candidate->assignedRecruiter?->name;

        $mrApproved = $mr && ($mr->l2_approved_at || $mr->approved_at || in_array($mr->status, [
            ManpowerRequestStatus::READY_FOR_HR, ManpowerRequestStatus::CONVERTED_TO_JD,
            ManpowerRequestStatus::JOB_POSTED, ManpowerRequestStatus::HIRING_IN_PROGRESS, ManpowerRequestStatus::CLOSED,
        ], true));
        $selected = $candidate->final_decision === 'Selected' || in_array($candidate->stage, ['Offer', 'Hired'], true) || (bool) $onb;

        $stage = fn ($key, $label, $done, $at, $by = null, $link = null) => [
            'key'          => $key,
            'label'        => $label,
            'status'       => $done ? 'completed' : 'pending',
            'at'           => $at ? \Carbon\Carbon::parse($at)->toIso8601String() : null,
            'completed_by' => $done ? $by : null,
            'link'         => $done ? $link : null,
        ];

        $stages = [];
        $stages[] = $stage('mr_created', 'Manpower Request Created', (bool) $mr, $mr?->created_at, $mr?->requester?->name, $mr ? ['type' => 'manpower', 'id' => $mr->id] : null);
        $stages[] = $stage('mr_approved', 'Manpower Approved', (bool) $mrApproved, $mr?->l2_approved_at ?? $mr?->approved_at ?? $mr?->l1_approved_at, $mr?->l2Approver?->name ?? $mr?->l1Approver?->name, $mr ? ['type' => 'manpower', 'id' => $mr->id] : null);
        $stages[] = $stage('job_posted', 'Job Posted', (bool) $job, $job?->published_at ?? $job?->created_at, $recruiter, $job ? ['type' => 'job', 'id' => $job->id] : null);
        $stages[] = $stage('applied', 'Candidate Applied', true, $candidate->applied_at ?? $candidate->created_at, $candidate->source, ['type' => 'candidate', 'id' => $candidate->id]);
        $stages[] = $stage('ai_screening', 'AI Screening Completed', $candidate->hasAiScreening(), $candidate->applied_at ?? $candidate->created_at, 'AI Screening'.($candidate->isScored() ? ' · '.$candidate->ai_score.'%' : ($candidate->hasAiScreening() ? ' · Insufficient data' : '')), ['type' => 'candidate', 'id' => $candidate->id]);

        foreach ($rounds as $r) {
            $stages[] = $stage('interview_'.$r->id, 'Interview: '.$r->round_name, $r->status === 'Completed', $r->scheduled_at, $r->interviewer_name, ['type' => 'interview', 'id' => $r->id]);
        }

        $stages[] = $stage('selected', 'Selected', (bool) $selected, $offer?->created_at ?? $onb?->invited_at, $recruiter, ['type' => 'candidate', 'id' => $candidate->id]);
        $stages[] = $stage('onb_invited', 'Onboarding Invitation Sent', (bool) $onb?->invited_at, $onb?->invited_at, $recruiter, $onb ? ['type' => 'onboarding', 'id' => $onb->id] : null);
        $stages[] = $stage('onb_submitted', 'Candidate Submitted Documents', (bool) $onb?->submitted_at, $onb?->submitted_at, $candidate->name, $onb ? ['type' => 'onboarding', 'id' => $onb->id] : null);
        $stages[] = $stage('onb_verified', 'HR Verified Documents', $onb && $onb->verification_status === 'Approved', $onb?->verified_at, $recruiter ?? 'HR', $onb ? ['type' => 'onboarding', 'id' => $onb->id] : null);
        $stages[] = $stage('offer_generated', 'Offer Generated', (bool) $offer, $offer?->generated_at ?? $offer?->created_at, $recruiter ?? 'HR', $offer ? ['type' => 'offer', 'id' => $offer->id] : null);
        $stages[] = $stage('offer_sent', 'Offer Sent', (bool) $offer?->sent_at, $offer?->sent_at, $recruiter ?? 'HR', $offer ? ['type' => 'offer', 'id' => $offer->id] : null);
        $stages[] = $stage('offer_accepted', 'Offer Accepted', (bool) $offer?->accepted_at, $offer?->accepted_at, $candidate->name, $offer ? ['type' => 'offer', 'id' => $offer->id] : null);
        $stages[] = $stage('employee_created', 'Employee Created', (bool) $employee, $employee?->created_at, $recruiter ?? 'HR', $employee ? ['type' => 'employee', 'id' => $employee->id] : null);

        // Interview score = average of completed rounds' overall score.
        $scored = $rounds->whereNotNull('overall_score');
        $interviewScore = $scored->count() ? round((float) $scored->avg('overall_score'), 1) : null;

        // Auto-calculated macro-phase progress (0–100).
        $roundsDone = $rounds->where('status', 'Completed')->count();
        $onbPct = ! $onb ? 0 : ($onb->verification_status === 'Approved' ? 100 : ($onb->submitted_at ? 60 : ($onb->invited_at ? 30 : 0)));
        $offerPct = ! $offer ? 0 : (in_array($offer->status, ['Accepted', 'Completed'], true) ? 100 : ($offer->sent_at || in_array($offer->status, ['Sent', 'Viewed'], true) ? 66 : 33));
        $progress = [
            'application' => min(100, ($candidate->applied_at || $candidate->created_at ? 50 : 0) + ($candidate->hasAiScreening() ? 50 : 0)),
            'interview'   => $rounds->count() ? (int) round($roundsDone / $rounds->count() * 100) : ($selected ? 100 : 0),
            'onboarding'  => $onbPct,
            'offer'       => $offerPct,
            'employee'    => $employee ? 100 : 0,
        ];

        return [
            'summary' => [
                'name'            => $candidate->name,
                'reference'       => 'CAND-'.str_pad((string) $candidate->id, 4, '0', STR_PAD_LEFT),
                'applied_job'     => $job?->title,
                'department'      => $job?->department ?? $mr?->department,
                // Project resolved through the project_id relation (candidate → job → MR chain).
                'project'         => $candidate->project ? ['id' => $candidate->project->id, 'name' => $candidate->project->name, 'status' => $candidate->project->status] : null,
                'current_stage'   => $candidate->stage,
                'current_status'  => $employee ? 'Joined' : ($offer?->accepted_at ? 'Offer Accepted' : ($offer ? 'Offer '.$offer->status : ($onb ? 'Onboarding '.($onb->verification_status ?? 'In Progress') : ($candidate->final_decision ?: 'In Progress')))),
                'recruiter'       => $recruiter,
                'ai_score'        => $candidate->publishedAiScore(),
                'interview_score' => $interviewScore,
                'interview_count' => $rounds->count(),
                'applied_at'      => optional($candidate->applied_at ?? $candidate->created_at)->toIso8601String(),
                'offer_status'    => $offer?->status ?? 'Not Generated',
                'joining_status'  => $employee ? 'Joined' : ($onb?->joining_confirmed_at ? 'Joining Confirmed' : ($offer?->status === 'Accepted' ? 'Offer Accepted' : 'Pending')),
                'employee_code'   => $employee?->employee_code,
                'employee_id'     => $employee?->id,
                'progress_pct'    => (int) round(($progress['application'] + $progress['interview'] + $progress['onboarding'] + $progress['offer'] + $progress['employee']) / 5),
            ],
            'progress' => $progress,
            'stages'   => $this->decorateStages($stages),
        ];
    }

    /**
     * SPK-1: every stage reads Completed / Current / Pending, and each pending
     * stage names its owner and the action that will move it forward.
     *
     * "Current" is the first incomplete stage AFTER the last completed one — not
     * simply the first incomplete stage. A hired candidate can legitimately have
     * an earlier gap (e.g. no AI score on record); that gap is history, and
     * flagging it as "current" would tell HR the wrong thing entirely. When the
     * final stage is done, nothing is current — the journey is finished.
     */
    private function decorateStages(array $stages): array
    {
        $lastDone = -1;
        foreach ($stages as $i => $s) {
            if ($s['status'] === 'completed') {
                $lastDone = $i;
            }
        }

        $marked = false;
        $prevAt = null;   // completion time of the previous completed stage

        foreach ($stages as $i => $s) {
            $done = $s['status'] === 'completed';

            if (! $done && ! $marked && $i > $lastDone) {
                $stages[$i]['status'] = 'current';
                $marked = true;
            }

            [$owner, $action] = $this->stageOwnerAction($s['key']);
            // Completed stages already carry the real actor in completed_by.
            $stages[$i]['owner'] = $done ? ($s['completed_by'] ?: $owner) : $owner;
            $stages[$i]['pending_action'] = $done ? null : $action;

            // Duration spent in the stage (SPK-1): time from the previous stage's
            // completion to this one's. For the stage in progress it is time
            // elapsed so far. Null when there is nothing to measure against.
            $stages[$i]['duration_days'] = null;
            if ($done && $s['at'] && $prevAt) {
                $stages[$i]['duration_days'] = (int) round(abs(Carbon::parse($prevAt)->diffInDays(Carbon::parse($s['at']))));
            } elseif ($stages[$i]['status'] === 'current' && $prevAt) {
                $stages[$i]['duration_days'] = (int) round(abs(Carbon::parse($prevAt)->diffInDays(Carbon::now())));
            }

            if ($done && $s['at']) {
                $prevAt = $s['at'];
            }
        }

        return $stages;
    }

    /** Who owns a stage, and what is outstanding while it is not complete. */
    private function stageOwnerAction(string $key): array
    {
        if (str_starts_with($key, 'interview_')) {
            return ['Interview Panel', 'Complete the round and record feedback'];
        }

        return match ($key) {
            'mr_created'       => ['Requester', 'Raise the manpower request'],
            'mr_approved'      => ['L1 / L2 Approver', 'Awaiting approval'],
            'job_posted'       => ['HR', 'Post the job from the approved request'],
            'applied'          => ['Candidate', 'Awaiting application'],
            'ai_screening'     => ['System', 'Awaiting AI evaluation'],
            'selected'         => ['HR', 'Mark the final decision'],
            'onb_invited'      => ['HR', 'Send the onboarding invitation'],
            'onb_submitted'    => ['Candidate', 'Awaiting document upload'],
            'onb_verified'     => ['HR', 'Verify the submitted documents'],
            'offer_generated'  => ['HR', 'Generate the offer letter'],
            'offer_sent'       => ['HR', 'Send the offer to the candidate'],
            'offer_accepted'   => ['Candidate', 'Awaiting offer acceptance'],
            'employee_created' => ['HR', 'Confirm joining to create the employee'],
            default            => ['HR', 'Pending'],
        };
    }
}
