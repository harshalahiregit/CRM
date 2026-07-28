<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Hr\AirCandidateScore;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrCandidateSubmission;
use App\Models\Hr\HrEmployee;
use App\Models\Hr\HrExternalCompany;
use App\Models\Hr\HrHiringRequest;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrOffer;
use App\Models\User;
use App\Support\Hr\HiringRequestStatus as Status;
use App\Support\Hr\SubmissionStatus;
use Illuminate\Support\Collection;

/**
 * Read/act façade for the external company portal.
 *
 * Owns NO recruitment logic — it scopes reads to the ambient company and reuses
 * RecruitmentServicesService for anything that touches the recruitment pipeline
 * (candidate resolution, funnel counts, feedback). Everything here is derived
 * live from the existing candidate/interview/offer data.
 */
class CompanyPortalService
{
    public function __construct(
        private RecruitmentServicesService $recruitment,
        private CandidateService $candidates,
        private ResumeService $resume,
        private OfferService $offers,
        private \App\Services\Notifications\NotificationService $notifications,
    ) {
    }

    /** Candidate ids actually delivered to this company (via submissions). */
    public function deliveredCandidateIds(HrHiringRequest $request): Collection
    {
        return HrCandidateSubmission::where('hiring_request_id', $request->id)->pluck('candidate_id')->unique()->values();
    }

    public function dashboard(HrExternalCompany $company): array
    {
        $requests = HrHiringRequest::where('external_company_id', $company->id)
            ->get(['id', 'status', 'number_of_positions', 'manpower_request_id', 'created_at', 'closed_at']);
        $reqIds = $requests->pluck('id');

        $active    = $requests->where('status', Status::CONVERTED);
        $completed = $requests->whereNotNull('closed_at');

        $avgHiringDays = $completed->isEmpty() ? null : round(
            $completed->avg(fn ($r) => max(0, $r->closed_at->getTimestamp() - $r->created_at->getTimestamp()) / 86400),
            1,
        );

        // The company's KPIs concern the candidates DELIVERED to them — resolved
        // in ONE query (not per-request), and correctly excludes non-delivered
        // applicants the company should never see counts for.
        $candIds = HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)->pluck('candidate_id')->unique()->values();

        $todaysInterviews = $candIds->isEmpty() ? 0 : HrInterviewRound::whereIn('candidate_id', $candIds)
            ->whereDate('scheduled_at', now()->toDateString())
            ->where('status', 'Scheduled')->count();

        $pendingOffers = $candIds->isEmpty() ? 0 : HrOffer::whereIn('candidate_id', $candIds)
            ->whereIn('status', ['Sent', 'Viewed'])->count();

        $pendingFeedback = HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)
            ->where('status', SubmissionStatus::PENDING)->count();

        $filledPositions = $candIds->isEmpty() ? 0 : HrCandidate::whereIn('id', $candIds)
            ->where(fn ($q) => $q->where('stage', 'Hired')->orWhere('final_decision', 'Selected'))->count();

        $byStatus = fn ($s) => $requests->where('status', $s)->count();

        // Candidate collaboration KPIs (Sprint 3) — live from submissions.
        $subBy = fn ($s) => HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)->where('status', $s)->count();

        return [
            // Workspace status cards (live, no cached values)
            'total_requests'    => $requests->count(),
            'pending_review'    => $byStatus(Status::SUBMITTED),
            'under_review'      => $byStatus(Status::UNDER_REVIEW),
            'approved'          => $byStatus(Status::APPROVED),
            'converted'         => $byStatus(Status::CONVERTED),
            'closed'            => $completed->count(), // closed_at — consistent with closed_positions & avg_hiring_days
            'draft'             => $byStatus(Status::DRAFT),
            'open_positions'    => (int) $active->sum('number_of_positions'),
            'filled_positions'  => $filledPositions,
            'closed_positions'  => (int) $completed->sum('number_of_positions'),
            // Operational KPIs
            'avg_hiring_days'   => $avgHiringDays,
            'todays_interviews' => $todaysInterviews,
            'pending_feedback'  => $pendingFeedback,
            'pending_offers'    => $pendingOffers,
            'active_requests'   => $active->count(),
            // Candidate KPIs
            'candidates_submitted'  => HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)->count(),
            'candidates_pending'    => $subBy(SubmissionStatus::PENDING),
            'candidates_shortlisted' => $subBy(SubmissionStatus::SHORTLISTED),
            'candidates_accepted'   => $subBy(SubmissionStatus::ACCEPTED),
            'candidates_rejected'   => $subBy(SubmissionStatus::REJECTED),
            'candidates_need_more'  => $subBy(SubmissionStatus::NEED_MORE),
            'candidates_hold'       => $subBy(SubmissionStatus::HOLD),
            'candidate_interviews'  => $candIds->isEmpty() ? 0 : HrInterviewRound::whereIn('candidate_id', $candIds)->count(),
            'candidate_offers'      => $candIds->isEmpty() ? 0 : HrOffer::whereIn('candidate_id', $candIds)->count(),
            // Interview & Offer collaboration KPIs (Sprint 4) — scoped to DELIVERED candidates.
            ...$this->collaborationKpis($candIds),
            'recent_activities' => $this->recentActivities($reqIds),
        ];
    }

    /** Client-safe recent activity across the company's requests (audit-derived, no internal notes). */
    private function recentActivities(Collection $requestIds): array
    {
        if ($requestIds->isEmpty()) {
            return [];
        }

        return AuditLog::where('auditable_type', HrHiringRequest::class)
            ->whereIn('auditable_id', $requestIds)
            ->latest('id')->limit(30)->get()
            ->filter(function ($a) {
                $m = $a->metadata ?? [];

                return ($m['comm'] ?? false) || ($m['client'] ?? false) || ($m['client_visible'] ?? false);
            })
            ->take(15)
            ->map(fn ($a) => [
                'action' => $a->action,
                'note'   => $a->comment,
                'at'     => $a->created_at,
            ])->values()->all();
    }

    /* ── Hiring request list (search / filter / sort / paginate) ── */

    public function hiringRequests(HrExternalCompany $company, array $f)
    {
        $sort = in_array($f['sort'] ?? '', ['created_at', 'job_title', 'priority', 'status', 'number_of_positions'], true)
            ? $f['sort'] : 'created_at';
        $dir = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return HrHiringRequest::where('external_company_id', $company->id)
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['search']), fn ($q) => $q->where(fn ($w) => $w
                ->where('job_title', 'like', '%'.$f['search'].'%')
                ->orWhere('department', 'like', '%'.$f['search'].'%')))
            ->with('assignedRecruiter:id,name')
            ->withCount('submissions')
            ->orderBy($sort, $dir)
            ->paginate(min(50, max(1, (int) ($f['per_page'] ?? 12))));
    }

    /* ── Create / edit (Draft) / submit — all reuse the recruitment engine ── */

    public function createDraft(HrExternalCompany $company, array $data, User $user): HrHiringRequest
    {
        // Force the request onto THIS company; never trust a client-supplied company id.
        $data['external_company_id'] = $company->id;

        return $this->recruitment->createRequest($data, $user, Status::DRAFT, 'Company');
    }

    public function updateDraft(HrExternalCompany $company, HrHiringRequest $request, array $data, User $user): HrHiringRequest
    {
        if ($request->status !== Status::DRAFT) {
            throw new BusinessException('Only a draft request can be edited.', 422);
        }
        unset($data['external_company_id']); // cannot be reassigned

        return $this->recruitment->updateRequest($request, $data, $user);
    }

    public function submit(HrHiringRequest $request, User $user): HrHiringRequest
    {
        return $this->recruitment->submitRequest($request, $user);
    }

    /* ── Detail workspace ── */

    public function detail(HrHiringRequest $request): array
    {
        $request->loadMissing(['externalCompany:id,name,company_code', 'assignedRecruiter:id,name', 'manpowerRequest:id,status']);
        $candIds = $this->recruitment->candidateIdsForRequest($request);

        return [
            'overview'    => $request,
            'counts'      => $this->recruitment->funnelCounts($request),
            'candidates'  => $this->recruitment->submissions($request),
            'interviews'  => $this->interviews($candIds),
            'offers'      => $this->offers($candIds),
            'documents'   => $request->documents()->with('uploader:id,name')->get(),
            'timeline'    => $this->timeline($request),
            'messages_count' => $request->messages()->count(),
        ];
    }

    private function interviews(Collection $candIds): array
    {
        if ($candIds->isEmpty()) {
            return [];
        }

        return HrInterviewRound::whereIn('candidate_id', $candIds)
            ->with('candidate:id,name')
            ->orderByDesc('scheduled_at')
            ->get(['id', 'candidate_id', 'round_name', 'mode', 'scheduled_at', 'status', 'result'])
            ->toArray();
    }

    private function offers(Collection $candIds): array
    {
        if ($candIds->isEmpty()) {
            return [];
        }

        return HrOffer::whereIn('candidate_id', $candIds)
            ->with('candidate:id,name')
            ->latest('id')
            ->get(['id', 'candidate_id', 'position', 'offered_ctc', 'joining_date', 'status', 'sent_at', 'accepted_at'])
            ->toArray();
    }

    /**
     * Full workflow timeline from the request's existing audit logs (no duplicate
     * history). Shows every milestone the company should see; internal review
     * comments are suppressed, rejection reasons kept.
     */
    public function timeline(HrHiringRequest $request): array
    {
        return $request->auditLogs()->latest('id')->limit(80)->get()->map(function ($a) {
            $m = $a->metadata ?? [];
            $showNote = ($m['comm'] ?? false) || ($m['client'] ?? false) || ($m['client_visible'] ?? false)
                || str_contains($a->action, 'Rejected');

            return [
                'action' => str_replace('_', ' ', $a->action),
                'note'   => $showNote ? $a->comment : null,
                'at'     => $a->created_at,
            ];
        })->values()->all();
    }

    /* ═══════════════════ Sprint 3 — Candidate collaboration ═══════════════════ */

    /** Delivered candidates for a request — card/list data, searched/filtered/sorted/paginated. */
    public function companyCandidates(HrHiringRequest $request, array $f)
    {
        $sort = in_array($f['sort'] ?? '', ['submitted_at', 'status'], true) ? $f['sort'] : 'submitted_at';
        $dir  = ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $page = $request->submissions()
            ->with([
                'candidate:id,name,current_company,experience_years,location,expected_ctc,ai_score,ai_breakdown,skills,stage,final_decision',
                'submittedBy:id,name',
            ])
            ->when(! empty($f['status']) && $f['status'] !== 'All', fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('candidate', fn ($c) => $c->where('name', 'like', '%'.$f['search'].'%')))
            ->orderBy($sort, $dir)
            ->paginate(min(50, max(1, (int) ($f['per_page'] ?? 12))));

        $page->getCollection()->transform(fn ($s) => $this->cardData($s));

        return $page;
    }

    /** The card payload for one delivered candidate. */
    private function cardData(HrCandidateSubmission $s): array
    {
        $c = $s->candidate;

        return [
            'submission_id'   => $s->id,
            'candidate_id'    => $s->candidate_id,
            'name'            => $c?->name,
            'current_company' => $c?->current_company,
            'experience_years' => $c?->experience_years,
            'location'        => $c?->location,
            'expected_ctc'    => $c?->expected_ctc,
            'ai_score'        => $c?->publishedAiScore(),
            'recommendation'  => $this->storedRecommendation($c),
            'skills'          => $c?->skills ?? [],
            'stage'           => $c?->stage,
            'status'          => $s->status,
            'submitted_at'    => $s->submitted_at,
            'viewed_at'       => $s->viewed_at,
            'recruiter'       => optional($s->submittedBy)->name,
        ];
    }

    /**
     * The engine's recommendation for a LIST row.
     *
     * Lists read the mirrored ai_breakdown rather than calling payloadFor() per row,
     * which would be N+1. It is still the engine's own label -- no threshold is
     * re-derived here. The 85/70/50 vocabulary that used to live in this class is
     * gone; there is one vocabulary, produced by RecommendationEngine.
     */
    private function storedRecommendation(?HrCandidate $candidate): ?string
    {
        if (! $candidate) {
            return null;
        }

        $breakdown = $candidate->ai_breakdown;
        if (is_string($breakdown)) {
            $breakdown = json_decode($breakdown, true) ?: [];
        }

        return is_array($breakdown) ? ($breakdown['recommendation'] ?? null) : null;
    }

    /**
     * The client portal's AI panel — the SAME payload the HR side consumes.
     *
     * This used to flatten ai_breakdown into its own hand-picked keys
     * (skill_match / experience_match / overall_fit / question_score), two of which
     * nothing ever wrote, so those bars were permanently blank. It now returns the
     * engine's payload verbatim: one contract, and a new dimension appears here
     * automatically instead of needing a key added.
     */
    public function aiEvaluation(HrCandidate $c): array
    {
        return AirCandidateScore::payloadFor($c);
    }

    /** Full candidate workspace — reuses candidate data, AI, journey, interviews, offer, onboarding. */
    public function candidateDetail(HrCandidateSubmission $sub): array
    {
        $c = $sub->candidate;

        // Record the "Viewed" event once (drives the timeline).
        if ($c && ! $sub->viewed_at) {
            $sub->update(['viewed_at' => now()]);
            $sub->recordAudit('Candidate profile viewed by client', null, $c->name, ['client' => true]);
        }

        $c?->loadMissing(['interviewRounds', 'offer', 'onboarding', 'assignedRecruiter:id,name']);

        return [
            'submission' => [
                'id' => $sub->id, 'status' => $sub->status, 'client_feedback' => $sub->client_feedback,
                'submitted_at' => $sub->submitted_at, 'viewed_at' => $sub->viewed_at,
            ],
            'summary' => $c ? [
                'name' => $c->name, 'current_company' => $c->current_company, 'experience_years' => $c->experience_years,
                'location' => $c->location, 'expected_ctc' => $c->expected_ctc, 'current_ctc' => $c->current_ctc,
                'notice_period' => $c->notice_period, 'stage' => $c->stage, 'final_decision' => $c->final_decision,
                'source' => $c->source, 'applied_at' => $c->applied_at,
                'skills' => $c->skills ?? [], 'education' => $c->education ?? [],
                'certifications' => $c->certifications ?? [], 'languages' => $c->languages ?? [],
            ] : null,
            'ai'              => $c ? $this->aiEvaluation($c) : null,
            'recruiter'       => $c?->assignedRecruiter?->name ?? optional($sub->submittedBy)->name,
            'resume_available' => (bool) ($c?->resume_path),
            'interviews'      => $c ? $c->interviewRounds->map(fn ($r) => [
                'round_name' => $r->round_name, 'mode' => $r->mode, 'scheduled_at' => $r->scheduled_at,
                'status' => $r->status, 'result' => $r->result, 'overall_score' => $r->overall_score,
            ])->values()->all() : [],
            'offer' => $c?->offer ? [
                'position' => $c->offer->position, 'offered_ctc' => $c->offer->offered_ctc,
                'joining_date' => $c->offer->joining_date, 'status' => $c->offer->status,
            ] : null,
            'onboarding' => $c?->onboarding ? [
                'status' => $c->onboarding->verification_status ?? 'In Progress',
                'invited_at' => $c->onboarding->invited_at, 'submitted_at' => $c->onboarding->submitted_at,
            ] : null,
            'journey'  => $c ? $this->journeyStages($c) : [],
            'timeline' => $c ? $this->candidateTimeline($c, $sub) : [],
        ];
    }

    /** Reuse CandidateService::journey stages (strip internal deep-links). */
    private function journeyStages(HrCandidate $c): array
    {
        $stages = $this->candidates->journey($c)['stages'] ?? [];

        return array_map(fn ($s) => [
            'label'  => $s['label'] ?? null,
            'status' => $s['status'] ?? null,
            'at'     => $s['at'] ?? null,
            'by'     => $s['completed_by'] ?? null,
        ], $stages);
    }

    /** Candidate timeline from existing audit logs (submission + candidate), client-safe. */
    private function candidateTimeline(HrCandidate $c, HrCandidateSubmission $sub): array
    {
        $subLogs = $sub->auditLogs()->get()->map(fn ($a) => [
            'action' => $a->action, 'note' => $a->comment, 'at' => $a->created_at,
        ]);

        $keywords = ['Applied', 'Interview', 'Offer', 'Onboard', 'Joined', 'Hired', 'Selected', 'Screening', 'Resume'];
        $candLogs = $c->auditLogs()->latest('id')->limit(60)->get()
            ->filter(fn ($a) => collect($keywords)->contains(fn ($k) => str_contains($a->action, $k)))
            ->map(fn ($a) => ['action' => $a->action, 'note' => null, 'at' => $a->created_at]);

        return $subLogs->concat($candLogs)->sortByDesc('at')->values()->map(fn ($x) => [
            'action' => str_replace('_', ' ', $x['action']),
            'note'   => $x['note'],
            'at'     => $x['at'],
        ])->all();
    }

    /** Company review action — delegates to the existing recordClientFeedback (audited + notifies recruiter). */
    public function reviewCandidate(HrCandidateSubmission $sub, string $decision, ?string $comment): HrCandidateSubmission
    {
        return $this->recruitment->recordClientFeedback($sub, $decision, $comment);
    }

    /** Stream the candidate's EXISTING resume (reuses ResumeService — no copy), audit the download. */
    public function resolveResume(HrCandidateSubmission $sub): array
    {
        $c = $sub->candidate;
        if (! $c || ! $c->resume_path) {
            throw new BusinessException('No resume on file for this candidate.', 404);
        }
        $file = $this->resume->resolveDownload($c, $sub->tenant_id);
        $sub->recordAudit('Client downloaded a resume', null, $c->name, ['client' => true]);

        return $file;
    }

    /** Generate a public share link for the candidate's resume (reuses shareResume). */
    public function shareCandidateResume(HrHiringRequest $request, HrCandidateSubmission $sub, User $user)
    {
        return $this->recruitment->shareResume($request, $sub->candidate_id, $user);
    }

    /** Candidate comments thread (reuses the request message service, scoped to the submission). */
    public function candidateComments(HrHiringRequest $request, HrCandidateSubmission $sub)
    {
        return app(HiringRequestMessageService::class)->list($request, $sub->id);
    }

    public function postCandidateComment(HrHiringRequest $request, HrCandidateSubmission $sub, User $user, string $body)
    {
        return app(HiringRequestMessageService::class)->post($request, 'company', $user, $body, $sub->id);
    }

    /* ═══════════════════ Sprint 4 — Interview & Offer collaboration ═══════════════════ */

    /** Dashboard KPIs for interviews/offers/joining, over delivered candidates. */
    private function collaborationKpis(Collection $delivered): array
    {
        if ($delivered->isEmpty()) {
            return ['upcoming_interviews' => 0, 'offers_pending' => 0, 'offers_accepted' => 0, 'offers_rejected' => 0, 'joining_pending' => 0, 'joined' => 0];
        }
        $offerBy = fn ($statuses) => HrOffer::whereIn('candidate_id', $delivered)->whereIn('status', $statuses)->count();

        return [
            'upcoming_interviews' => HrInterviewRound::whereIn('candidate_id', $delivered)->where('scheduled_at', '>', now())->where('status', 'Scheduled')->count(),
            'offers_pending'      => $offerBy(['Generated', 'Sent', 'Viewed']),
            'offers_accepted'     => $offerBy(['Accepted', 'Completed']),
            'offers_rejected'     => $offerBy(['Declined', 'Expired']),
            'joining_pending'     => HrOffer::whereIn('candidate_id', $delivered)->where('status', 'Accepted')->whereNull('joining_confirmed_at')->count(),
            'joined'              => HrEmployee::whereIn('candidate_id', $delivered)->count(),
        ];
    }

    /* ── Interviews (for delivered candidates on this request) ── */

    public function requestInterviews(HrHiringRequest $request): array
    {
        $ids = $this->deliveredCandidateIds($request);
        if ($ids->isEmpty()) {
            return [];
        }

        // Latest company action per interview (Confirmed / Reschedule / Cancel).
        $rounds = HrInterviewRound::whereIn('candidate_id', $ids)->with('candidate:id,name')->orderByDesc('scheduled_at')->get();
        $actions = \App\Models\Hr\HrInterviewClientAction::whereIn('interview_id', $rounds->pluck('id'))
            ->orderByDesc('id')->get()->groupBy('interview_id');

        return $rounds->map(fn ($r) => [
            'id'            => $r->id,
            'candidate'     => $r->candidate?->name,
            'round'         => $r->round_name,
            'type'          => $r->mode,
            'panel'         => is_array($r->interviewers) ? $r->interviewers : [],
            'interviewer'   => $r->interviewer_name,
            'scheduled_at'  => $r->scheduled_at,
            'meet_link'     => $r->meet_link,
            'venue'         => $r->venue,
            'status'        => $r->status,
            'decision'      => $r->result,
            'rating'        => $r->rating,
            'feedback'      => $r->notes,
            'client_action' => optional(($actions[$r->id] ?? collect())->first())->action,
        ])->values()->all();
    }

    /** ICS calendar invite for an interview (calendar export — not a new calendar). */
    public function interviewIcs(HrInterviewRound $interview): string
    {
        $start = $interview->scheduled_at ? \Carbon\Carbon::parse($interview->scheduled_at) : now();
        $end = (clone $start)->addHour();
        $fmt = fn ($d) => $d->utc()->format('Ymd\THis\Z');
        $desc = 'Interview: '.$interview->round_name.($interview->meet_link ? '\nJoin: '.$interview->meet_link : '');
        $loc = $interview->meet_link ?: ($interview->venue ?: '');

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//CRM//Recruitment//EN\r\nBEGIN:VEVENT\r\n"
            ."UID:interview-{$interview->id}@crm\r\nDTSTAMP:{$fmt(now())}\r\nDTSTART:{$fmt($start)}\r\nDTEND:{$fmt($end)}\r\n"
            ."SUMMARY:Interview — {$interview->round_name}\r\nDESCRIPTION:{$desc}\r\nLOCATION:{$loc}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /* ── Offers (for delivered candidates) + joining ── */

    public function requestOffers(HrHiringRequest $request): array
    {
        $ids = $this->deliveredCandidateIds($request);
        if ($ids->isEmpty()) {
            return [];
        }

        // Batch the employee lookup once (avoid one query per offer).
        $employees = HrEmployee::whereIn('candidate_id', $ids)->get()->keyBy('candidate_id');

        return HrOffer::whereIn('candidate_id', $ids)->with(['candidate:id,name', 'auditLogs' => fn ($q) => $q->latest('id')])->latest('id')->get()->map(function ($o) use ($employees) {
            $employee = $employees->get($o->candidate_id);
            $lastClient = $o->auditLogs->first(fn ($a) => str_starts_with($a->action, 'Client '));

            return [
                'id'              => $o->id,
                'offer_number'    => 'OFR-'.$o->id,
                'candidate'       => $o->candidate?->name,
                'position'        => $o->position,
                'department'      => $o->department,
                'offered_ctc'     => $o->offered_ctc,
                'joining_date'    => $o->joining_date,
                'status'          => $o->status,
                'company_decision' => $lastClient?->action,
                'joining' => [
                    'expected_joining' => $o->joining_date,
                    'actual_joining'   => $o->joining_confirmed_at ?? $employee?->joining_date,
                    'joining_status'   => $o->joining_confirmed_at ? 'Confirmed' : ($o->status === 'Accepted' ? 'Pending' : '—'),
                    'employee_status'  => $employee?->status,
                    'employee_code'    => $employee?->employee_code,
                ],
            ];
        })->values()->all();
    }

    public function offerLetter(HrOffer $offer): array
    {
        $file = $this->offers->offerLetterFile($offer);
        if (! $file) {
            throw new BusinessException('No offer letter is available yet.', 404);
        }
        $offer->recordAudit('Client downloaded the offer letter', null, null, ['client' => true]);

        return $file + ['mime' => 'application/pdf'];
    }

    /** Company offer decision — recorded on the offer's audit trail + recruiter notified. No offer-state duplication. */
    public function offerDecision(HrOffer $offer, string $decision, ?string $comment, User $user): HrOffer
    {
        $map = ['approve' => 'approved', 'changes' => 'requested changes on', 'reject' => 'rejected', 'feedback' => 'left feedback on'];
        if (! isset($map[$decision])) {
            throw new BusinessException('Invalid offer decision.', 422);
        }
        if ($decision !== 'feedback') {
            if ($offer->status === 'Expired') {
                throw new BusinessException('This offer has expired and cannot be actioned.', 422);
            }
            if (in_array($offer->status, ['Accepted', 'Completed'], true)) {
                throw new BusinessException('This offer has been accepted and can no longer be modified.', 422);
            }
        }

        $offer->recordAudit('Client '.$map[$decision].' the offer', $user, $comment, ['client' => true]);
        $offer->loadMissing('candidate.assignedRecruiter:id,email');
        $this->notifications->email(
            optional($offer->candidate?->assignedRecruiter)->email,
            'Client offer decision: '.ucfirst($decision),
            "The client {$map[$decision]} the offer for {$offer->candidate?->name} (OFR-{$offer->id}).".($comment ? "\n\nComment: {$comment}" : ''),
            ['offer_id' => $offer->id, 'event' => 'offer_'.$decision],
        );

        return $offer->fresh();
    }
}
