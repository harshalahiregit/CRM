<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrCandidateSubmission;
use App\Models\Hr\HrClientRating;
use App\Models\Hr\HrExternalCompany;
use App\Models\Hr\HrHiringRequest;
use App\Models\Hr\HrInterviewRound;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrOffer;
use App\Models\Hr\HrRecruiterNote;
use App\Models\Hr\HrResumeShare;
use App\Models\User;
use App\Support\Hr\HiringRequestStatus as Status;
use App\Support\Hr\RecruitmentSla;
use App\Support\Hr\SubmissionStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Recruitment Services (Phase 1) — external-company hiring intake + hand-off.
 *
 * The one rule that matters: conversion REUSES the existing
 * ManpowerRequestService::create. It never touches the candidate/job/interview/
 * offer/onboarding modules — once the Manpower Request exists, the standard
 * recruitment workflow proceeds exactly as it does for any internal request.
 */
class RecruitmentServicesService
{
    public function __construct(
        private ManpowerRequestService $manpowerRequestService,
        private CandidateService $candidateService,   // reused for the assignable-recruiter list
        private ResumeService $resumeService,          // reused for resume storage + streaming
    ) {
    }

    /* ───────────────────────── External companies ───────────────────────── */

    public function companies(int $tenantId, array $filters = [])
    {
        return HrExternalCompany::where('tenant_id', $tenantId)
            ->when(! empty($filters['status']) && $filters['status'] !== 'All', fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('name', 'like', '%'.$filters['search'].'%'))
            ->withCount('hiringRequests')
            ->latest()
            ->get();
    }

    public function createCompany(array $data, User $user): HrExternalCompany
    {
        $company = HrExternalCompany::create([
            ...$data,
            'tenant_id'  => $user->tenant_id,
            'created_by' => $user->id,
        ]);
        $company->recordAudit('External company added', $user, $company->name);

        return $company;
    }

    public function updateCompany(HrExternalCompany $company, array $data, User $user): HrExternalCompany
    {
        $company->update($data);
        $company->recordAudit('External company updated', $user, $company->name);

        return $company->fresh();
    }

    public function deleteCompany(HrExternalCompany $company): void
    {
        if ($company->hiringRequests()->exists()) {
            throw new BusinessException('Cannot delete a company that has hiring requests.', 422);
        }
        $company->delete();
    }

    /* ───────────────────────── Hiring requests ──────────────────────────── */

    public function requests(int $tenantId, array $filters = [])
    {
        return HrHiringRequest::where('tenant_id', $tenantId)
            ->when(! empty($filters['status']) && $filters['status'] !== 'All', fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['company_id']), fn ($q) => $q->where('external_company_id', $filters['company_id']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('job_title', 'like', '%'.$filters['search'].'%'))
            ->with(['externalCompany:id,name', 'assignedRecruiter:id,name', 'manpowerRequest:id,status'])
            ->latest()
            ->get();
    }

    /**
     * Create a hiring request. HR-created requests default to Submitted/Internal;
     * the company portal passes Draft/Company so the company can edit before
     * submitting. Same single code path — no duplicated creation logic.
     */
    public function createRequest(array $data, User $user, string $status = Status::SUBMITTED, string $source = 'Internal'): HrHiringRequest
    {
        $company = HrExternalCompany::where('tenant_id', $user->tenant_id)->findOrFail($data['external_company_id']);

        $request = HrHiringRequest::create([
            ...$data,
            'tenant_id'    => $user->tenant_id,
            'status'       => $status,
            'source'       => $source,
            'submitted_by' => $user->id,
        ]);
        $request->recordAudit('Hiring request created', $user, $request->job_title, ['company' => $company->name, 'client_visible' => true]);

        return $request->load('externalCompany:id,name');
    }

    /** Company submits a Draft request for HR review. */
    public function submitRequest(HrHiringRequest $request, User $user): HrHiringRequest
    {
        if ($request->status !== Status::DRAFT) {
            throw new BusinessException('Only a draft request can be submitted.', 422);
        }
        $request->update(['status' => Status::SUBMITTED, 'submitted_by' => $user->id]);
        $request->recordAudit('Hiring request submitted', $user, $request->job_title, ['client_visible' => true]);

        return $request->fresh(['externalCompany:id,name', 'assignedRecruiter:id,name']);
    }

    public function updateRequest(HrHiringRequest $request, array $data, User $user): HrHiringRequest
    {
        if (in_array($request->status, [Status::CONVERTED, Status::COMPLETED], true)) {
            throw new BusinessException('A converted or completed request can no longer be edited.', 422);
        }
        $request->update($data);
        $request->recordAudit('Hiring request updated', $user, $request->job_title);

        return $request->fresh(['externalCompany:id,name', 'assignedRecruiter:id,name']);
    }

    /** Public submission (no auth) — external company via its company token. */
    public function publicSubmit(HrExternalCompany $company, array $data): HrHiringRequest
    {
        $request = HrHiringRequest::create([
            ...$data,
            'tenant_id'           => $company->tenant_id,
            'external_company_id' => $company->id,
            'status'              => Status::SUBMITTED,
            'source'              => 'Portal',
        ]);
        $request->recordAudit('Hiring request submitted (portal)', null, $request->job_title, ['company' => $company->name]);

        return $request;
    }

    /* ─────────────────────────── Workflow ───────────────────────────────── */

    public function review(HrHiringRequest $request, string $decision, ?string $notes, User $user): HrHiringRequest
    {
        $map = [
            'under-review' => Status::UNDER_REVIEW,
            'approve'      => Status::APPROVED,
            'reject'       => Status::REJECTED,
        ];
        if (! isset($map[$decision])) {
            throw new BusinessException('Invalid review decision.', 422);
        }
        if ($request->status === Status::CONVERTED) {
            throw new BusinessException('A converted request cannot be reviewed again.', 422);
        }

        $request->update([
            'status'           => $map[$decision],
            'reviewed_by'      => $user->id,
            'reviewed_at'      => now(),
            'review_notes'     => $decision === 'reject' ? null : $notes,
            'rejection_reason' => $decision === 'reject' ? $notes : null,
        ]);
        $request->recordAudit('Hiring request '.$map[$decision], $user, $notes);

        return $request->fresh(['externalCompany:id,name', 'assignedRecruiter:id,name', 'reviewer:id,name']);
    }

    public function assignRecruiter(HrHiringRequest $request, ?int $recruiterId, User $user): HrHiringRequest
    {
        if ($recruiterId) {
            // Recruiter must be an assignable user in this tenant (reuses the list).
            $ok = $this->candidateService->assignableRecruiters($request->tenant_id)->contains('id', $recruiterId);
            if (! $ok) {
                throw new BusinessException('Selected recruiter is not assignable in this tenant.', 422);
            }
        }
        $request->update([
            'assigned_recruiter_id' => $recruiterId,
            // Stamp the assignment SLA clock the first time a recruiter is set.
            'assigned_at' => $recruiterId && ! $request->assigned_at ? now() : $request->assigned_at,
        ]);
        $request->recordAudit($recruiterId ? 'Recruiter assigned' : 'Recruiter unassigned', $user);

        return $request->fresh(['externalCompany:id,name', 'assignedRecruiter:id,name']);
    }

    /**
     * Convert an APPROVED hiring request into a Manpower Request.
     *
     * This is the single hand-off point into the existing recruitment flow. It
     * maps the request onto the Manpower payload and calls the SAME
     * ManpowerRequestService::create the rest of the app uses — so the JD → Job
     * Posting → Candidate → Interview → Offer → Onboarding → Employee flow
     * continues untouched and unduplicated.
     */
    public function convertToManpower(HrHiringRequest $request, User $user): HrHiringRequest
    {
        if ($request->status !== Status::APPROVED) {
            throw new BusinessException('Only approved hiring requests can be converted.', 422);
        }
        if ($request->manpower_request_id) {
            throw new BusinessException('This request has already been converted.', 422);
        }

        return DB::transaction(function () use ($request, $user) {
            $request->loadMissing('externalCompany');
            $company = $request->externalCompany;

            // Map hiring-request fields → Manpower Request columns. Required MR
            // fields (department, job_type, priority) are defaulted so the reused
            // service always receives a valid payload.
            $payload = [
                'business_unit'       => $request->business_unit ?: $company?->name,
                'department'          => $request->department ?: 'General',
                'project'             => null,
                'location'            => $request->location,
                'position_title'      => $request->job_title,
                'employee_level'      => null,
                'job_type'            => $request->employment_type ?: 'Full-time',
                'work_mode'           => $request->work_mode,
                'number_of_posts'     => max(1, (int) $request->number_of_positions),
                'experience_required' => $request->experience_required,
                'education'           => $request->education,
                'salary_min'          => $request->salary_min,
                'salary_max'          => $request->salary_max,
                'required_skills'     => $request->required_skills ?? [],
                'job_description'     => $request->job_description,
                'priority'            => $request->priority ?: 'Medium',
                'justification'       => 'Converted from Recruitment Services hiring request #'.$request->id
                    .($company ? ' for external client "'.$company->name.'"' : ''),
            ];
            if ($request->assigned_recruiter_id) {
                $payload['assigned_manager_id'] = $request->assigned_recruiter_id;
            }

            // ── REUSE the existing Manpower service — no duplicated logic ──
            $mr = $this->manpowerRequestService->create($payload, $user);

            $request->update([
                'status'              => Status::CONVERTED,
                'manpower_request_id' => $mr->id,
                'converted_at'        => now(),
            ]);
            $request->recordAudit('Converted to Manpower Request', $user, 'MR-'.$mr->id, ['manpower_request_id' => $mr->id]);

            Log::channel('hr')->info('Hiring request converted to manpower request', [
                'hiring_request_id' => $request->id, 'manpower_request_id' => $mr->id, 'tenant_id' => $request->tenant_id,
            ]);

            return $request->fresh(['externalCompany:id,name', 'manpowerRequest:id,status', 'assignedRecruiter:id,name']);
        });
    }

    /* ─────────────────────────── Dashboard ──────────────────────────────── */

    public function dashboard(int $tenantId): array
    {
        $reqs = HrHiringRequest::where('tenant_id', $tenantId);

        return [
            'companies'        => HrExternalCompany::where('tenant_id', $tenantId)->count(),
            'active_companies' => HrExternalCompany::where('tenant_id', $tenantId)->where('status', 'Active')->count(),
            'total_requests'   => (clone $reqs)->count(),
            'submitted'        => (clone $reqs)->where('status', Status::SUBMITTED)->count(),
            'under_review'     => (clone $reqs)->where('status', Status::UNDER_REVIEW)->count(),
            'approved'         => (clone $reqs)->where('status', Status::APPROVED)->count(),
            'rejected'         => (clone $reqs)->where('status', Status::REJECTED)->count(),
            'converted'        => (clone $reqs)->where('status', Status::CONVERTED)->count(),
            'completed'        => (clone $reqs)->where('status', Status::COMPLETED)->count(),
            'candidates_delivered' => HrCandidateSubmission::where('tenant_id', $tenantId)->count(),
        ];
    }

    /* ═══════════════════════ Phase 2 — Client collaboration ═══════════════════════ */

    /**
     * Job postings that belong to this request's downstream flow.
     * Chain: hiring_request → manpower_request → job_posting(s). Candidates hang
     * off the job posting, so this is how we reach the existing candidate pool
     * without any direct FK or duplicated data.
     */
    private function requestJobPostingIds(HrHiringRequest $request): Collection
    {
        if (! $request->manpower_request_id) {
            return collect();
        }

        return HrJobPosting::where('manpower_request_id', $request->manpower_request_id)->pluck('id');
    }

    /** Existing candidates eligible to be delivered to the client (not yet submitted). */
    public function submittableCandidates(HrHiringRequest $request): Collection
    {
        $jobPostingIds = $this->requestJobPostingIds($request);
        if ($jobPostingIds->isEmpty()) {
            return collect();
        }

        $already = $request->submissions()->pluck('candidate_id');

        return HrCandidate::whereIn('job_posting_id', $jobPostingIds)
            ->when($already->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $already))
            ->orderByDesc('ai_score')
            ->get(['id', 'name', 'stage', 'experience_years', 'current_company', 'location', 'ai_score', 'skills', 'final_decision']);
    }

    /** Recruiter delivers one or more existing candidates to the client. */
    public function submitCandidates(HrHiringRequest $request, array $candidateIds, ?string $note, User $user): array
    {
        if (! $request->manpower_request_id) {
            throw new BusinessException('Convert this request first — candidates originate from the recruitment flow.', 422);
        }

        $jobPostingIds = $this->requestJobPostingIds($request);
        $valid = HrCandidate::whereIn('job_posting_id', $jobPostingIds)->whereIn('id', $candidateIds)->pluck('id');
        if ($valid->isEmpty()) {
            throw new BusinessException('No valid candidates to submit for this request.', 422);
        }

        $created = [];
        DB::transaction(function () use ($request, $valid, $note, $user, &$created) {
            foreach ($valid as $cid) {
                $sub = HrCandidateSubmission::firstOrCreate(
                    ['hiring_request_id' => $request->id, 'candidate_id' => $cid],
                    [
                        'tenant_id'      => $request->tenant_id,
                        'status'         => SubmissionStatus::PENDING,
                        'recruiter_note' => $note,
                        'submitted_by'   => $user->id,
                        'submitted_at'   => now(),
                    ],
                );
                if ($sub->wasRecentlyCreated) {
                    $sub->recordAudit('Submitted to client', $user, $note);
                    $created[] = $sub;
                }
            }
            if (count($created)) {
                // Stamp the first-candidate SLA clock on the first-ever delivery.
                if (! $request->first_submitted_at) {
                    $request->update(['first_submitted_at' => now()]);
                }
                $request->recordAudit(count($created).' candidate(s) submitted to client', $user, $note, ['client_visible' => true]);
            }
        });

        if (count($created)) {
            $this->dispatchNotification($request, 'candidate_submitted', ['count' => count($created)], $user);
        }

        return $created;
    }

    /** Full delivery history for a request (candidate + recruiter + feedback trail). */
    public function submissions(HrHiringRequest $request): Collection
    {
        return $request->submissions()
            ->with([
                'candidate:id,name,stage,experience_years,current_company,location,ai_score,skills,final_decision',
                'submittedBy:id,name',
                'auditLogs.actor:id,name',
            ])
            ->get();
    }

    /** Client records Accept / Reject / Need-More on a delivered candidate (public, no auth). */
    public function recordClientFeedback(HrCandidateSubmission $submission, string $decision, ?string $comment): HrCandidateSubmission
    {
        $map = SubmissionStatus::DECISION_MAP;
        if (! isset($map[$decision])) {
            throw new BusinessException('Invalid feedback decision.', 422);
        }
        // Feedback is only meaningful while the engagement is live.
        if (! in_array($submission->hiringRequest->status, [Status::CONVERTED, Status::COMPLETED], true)) {
            throw new BusinessException('This request is not open for candidate feedback.', 422);
        }
        $status = $map[$decision];

        $submission->update([
            'status'         => $status,
            'client_feedback' => $comment,
            'feedback_at'    => now(),
        ]);
        // History rides audit_logs — on the submission (detail) and the request (client timeline).
        $submission->recordAudit('Client feedback: '.$status, null, $comment, ['client' => true]);
        $request = $submission->hiringRequest;
        $request->recordAudit('Client responded on a candidate', null, $status.($comment ? ' — '.$comment : ''), ['client' => true]);

        // Notify the assigned recruiter through the shared comm/audit pipeline.
        $this->dispatchNotification($request, 'client_feedback', [
            'status'    => $status,
            'candidate' => optional($submission->candidate)->name,
            'comment'   => $comment,
        ], null);

        return $submission->fresh(['candidate:id,name']);
    }

    /** Public, privacy-safe tracking payload for the client portal. */
    public function clientTracking(HrHiringRequest $request): array
    {
        $request->loadMissing(['externalCompany:id,name', 'assignedRecruiter:id,name']);

        // Resume shares keyed by candidate so the portal can offer a download link.
        $shareTokens = $request->resumeShares()->pluck('share_token', 'candidate_id');
        $rating = $this->clientRating($request);

        return [
            'request' => [
                'reference'        => 'HR-'.$request->id,
                'job_title'        => $request->job_title,
                'department'       => $request->department,
                'location'         => $request->location,
                'employment_type'  => $request->employment_type,
                'work_mode'        => $request->work_mode,
                'number_of_positions' => $request->number_of_positions,
                'experience_required' => $request->experience_required,
                'priority'         => $request->priority,
                'status'           => $request->status,
                'created_at'       => $request->created_at,
            ],
            'company_name' => $request->externalCompany?->name,
            'recruiter'    => $request->assignedRecruiter?->name,
            'counts'       => $this->funnelCounts($request),
            'timeline'     => $this->publicTimeline($request),
            'candidates'   => $this->submissions($request)->map(fn ($s) => [
                'id'               => $s->id,
                'name'             => optional($s->candidate)->name,
                'experience_years' => optional($s->candidate)->experience_years,
                'current_company'  => optional($s->candidate)->current_company,
                'location'         => optional($s->candidate)->location,
                'skills'           => optional($s->candidate)->skills ?? [],
                'status'           => $s->status,
                'client_feedback'  => $s->client_feedback,
                'submitted_at'     => $s->submitted_at,
                'resume_share_token' => $shareTokens[$s->candidate_id] ?? null, // present only when a resume was shared
            ])->values(),
            'rating' => $rating ? [
                'overall'           => $rating->overall,
                'communication'     => $rating->communication,
                'candidate_quality' => $rating->candidate_quality,
                'turnaround_time'   => $rating->turnaround_time,
                'feedback'          => $rating->feedback,
            ] : null,
        ];
    }

    /**
     * Recruitment funnel counts for the client — all derived live from the
     * existing candidate/interview data. Nothing is copied or cached.
     */
    public function funnelCounts(HrHiringRequest $request): array
    {
        $delivered = $request->submissions()->count();
        $jobPostingIds = $this->requestJobPostingIds($request);
        if ($jobPostingIds->isEmpty()) {
            return ['applications' => 0, 'shortlisted' => 0, 'interviews' => 0, 'selected' => 0, 'delivered' => $delivered];
        }

        $candIds = HrCandidate::whereIn('job_posting_id', $jobPostingIds)->pluck('id');

        return [
            'applications' => $candIds->count(),
            'shortlisted'  => HrCandidate::whereIn('id', $candIds)
                ->whereIn('stage', ['Screening', 'Assessment', 'Interview', 'Offer', 'Hired'])->count(),
            'interviews'   => HrInterviewRound::whereIn('candidate_id', $candIds)->count(),
            'selected'     => HrCandidate::whereIn('id', $candIds)
                ->where(fn ($q) => $q->whereIn('stage', ['Offer', 'Hired'])->orWhere('final_decision', 'Selected'))->count(),
            'delivered'    => $delivered,
        ];
    }

    /** Recruiter-triggered client notifications for events that originate in the existing modules. */
    public function notifyClient(HrHiringRequest $request, string $event, User $user): array
    {
        $allowed = ['interview_scheduled', 'offer_released', 'project_completed'];
        if (! in_array($event, $allowed, true)) {
            throw new BusinessException('Unknown notification event.', 422);
        }
        if ($event === 'project_completed') {
            if (! $request->manpower_request_id) {
                throw new BusinessException('Only a converted request can be marked completed.', 422);
            }
            $request->update(['status' => Status::COMPLETED, 'closed_at' => $request->closed_at ?? now()]);
            $request->recordAudit('Project marked completed', $user, null, ['client_visible' => true]);
        }

        return $this->dispatchNotification($request, $event, [], $user);
    }

    /**
     * Shared notification pipeline — REUSES the Communication Center primitives
     * (Laravel Mail + the audit trail), rather than building a parallel channel.
     * Every send is recorded on the request's audit trail with the same
     * `comm => true` metadata shape the existing CommunicationService uses, so it
     * also feeds the client timeline.
     */
    private function dispatchNotification(HrHiringRequest $request, string $event, array $ctx, ?User $actor): array
    {
        $request->loadMissing(['externalCompany', 'assignedRecruiter:id,name,email']);
        $company = $request->externalCompany;

        [$to, $audience] = $event === 'client_feedback'
            ? [optional($request->assignedRecruiter)->email, 'recruiter']
            : [$company?->contact_email, 'client'];

        // Respect the company's notification preferences for client-facing emails
        // (opt-out model: no settings on file = everything enabled).
        if ($audience === 'client' && ! $this->companyWantsEmail($company, $event)) {
            $request->recordAudit('Notification: '.$this->notificationTemplate($event, $request, $company, $ctx)['label'], $actor, null, [
                'comm' => true, 'channel' => 'email', 'event' => $event, 'audience' => $audience, 'to' => $to, 'status' => 'disabled',
            ]);

            return ['event' => $event, 'status' => 'disabled', 'to' => $to];
        }

        $tpl = $this->notificationTemplate($event, $request, $company, $ctx);

        $status = 'skipped';
        if ($to) {
            try {
                Mail::html(nl2br(e($tpl['body'])), function ($m) use ($to, $tpl) {
                    $m->to($to)->subject($tpl['subject']);
                });
                $status = 'sent';
            } catch (\Throwable $e) {
                $status = 'failed';
                Log::channel('hr')->warning('Recruitment Services notification failed', [
                    'event' => $event, 'request_id' => $request->id, 'error' => $e->getMessage(),
                ]);
            }
        } else {
            // No recipient on file — make the skip observable in production instead of failing silently.
            Log::channel('hr')->warning('Recruitment Services notification skipped: no recipient', [
                'event' => $event, 'request_id' => $request->id, 'audience' => $audience,
            ]);
        }

        $request->recordAudit('Notification: '.$tpl['label'], $actor, $tpl['subject'], [
            'comm' => true, 'channel' => 'email', 'event' => $event,
            'audience' => $audience, 'to' => $to, 'status' => $status,
        ]);

        return ['event' => $event, 'status' => $status, 'to' => $to];
    }

    /** Whether the company opted to receive this client-facing email (opt-out default). */
    private function companyWantsEmail(?HrExternalCompany $company, string $event): bool
    {
        $s = $company?->notification_settings;
        if (empty($s)) {
            return true; // no preferences set = all enabled
        }
        if (array_key_exists('email', $s) && ! $s['email']) {
            return false; // master email toggle off
        }
        $key = [
            'candidate_submitted' => 'candidate_submission',
            'interview_scheduled' => 'interview_updates',
            'offer_released'      => 'offer_updates',
            'project_completed'   => 'hiring_updates',
        ][$event] ?? null;

        return $key === null ? true : ($s[$key] ?? true);
    }

    /** Client-facing notification copy. */
    private function notificationTemplate(string $event, HrHiringRequest $request, ?HrExternalCompany $company, array $ctx): array
    {
        $role = $request->job_title;
        $client = $company?->name ?? 'Client';

        return match ($event) {
            'candidate_submitted' => [
                'label'   => 'Candidate Submission',
                'subject' => "New candidate(s) submitted for {$role}",
                'body'    => "Hello {$client},\n\n".($ctx['count'] ?? 1)." candidate profile(s) for the role of {$role} have been submitted for your review.\n\nPlease open your tracking portal to review the profiles and share your feedback.\n\n— Recruitment Team",
            ],
            'client_feedback' => [
                'label'   => 'Client Feedback',
                'subject' => "Client feedback received for {$role}",
                'body'    => "A client response was recorded for {$role}".(! empty($ctx['candidate']) ? " (candidate: {$ctx['candidate']})" : '').": ".($ctx['status'] ?? '').(! empty($ctx['comment']) ? "\n\nComment: {$ctx['comment']}" : '')."\n\n— Recruitment Services",
            ],
            'interview_scheduled' => [
                'label'   => 'Interview Scheduled',
                'subject' => "Interview scheduled for {$role}",
                'body'    => "Hello {$client},\n\nAn interview has been scheduled for a candidate submitted for the role of {$role}. You can follow progress on your tracking portal.\n\n— Recruitment Team",
            ],
            'offer_released' => [
                'label'   => 'Offer Released',
                'subject' => "Offer released for {$role}",
                'body'    => "Hello {$client},\n\nAn offer has been released to a selected candidate for the role of {$role}.\n\n— Recruitment Team",
            ],
            'project_completed' => [
                'label'   => 'Project Completion',
                'subject' => "Recruitment completed for {$role}",
                'body'    => "Hello {$client},\n\nWe are pleased to inform you that the recruitment for {$role} has been completed. Thank you for partnering with us.\n\n— Recruitment Team",
            ],
            default => [
                'label'   => 'Notification',
                'subject' => "Update on your hiring request for {$role}",
                'body'    => "There is an update on your hiring request for {$role}.",
            ],
        };
    }

    /**
     * Privacy-safe timeline for the client portal.
     *
     * Only client-facing milestones are exposed — entries explicitly flagged as
     * client communication (`comm`), a client action (`client`), or a
     * client-visible milestone (`client_visible`). Internal entries (review
     * notes, rejection reasons, recruiter assignment, MR ids) are never emitted,
     * so their comments cannot leak to the external client.
     */
    private function publicTimeline(HrHiringRequest $request): array
    {
        return $request->auditLogs()->latest('id')->limit(60)->get()
            ->filter(function ($a) {
                $m = $a->metadata ?? [];

                return ($m['comm'] ?? false) || ($m['client'] ?? false) || ($m['client_visible'] ?? false);
            })
            ->map(fn ($a) => [
                'action' => $a->action,
                'note'   => $a->comment,
                'at'     => $a->created_at,
                'by'     => ($a->metadata['client'] ?? false) ? 'Client' : 'Recruitment Team',
            ])->values()->all();
    }

    /* ═══════════════════ Phase 3 — Recruitment Operations ═══════════════════ */

    /** Existing candidate ids reachable from this request's downstream flow. */
    public function candidateIdsForRequest(HrHiringRequest $request): Collection
    {
        $jobPostingIds = $this->requestJobPostingIds($request);

        return $jobPostingIds->isEmpty()
            ? collect()
            : HrCandidate::whereIn('job_posting_id', $jobPostingIds)->pluck('id');
    }

    /* ── Recruiter workspace + dashboard ── */

    /** The recruiter's own queue: assigned projects, open positions, submission queue, pending feedback. */
    public function recruiterWorkspace(User $user): array
    {
        $requests = HrHiringRequest::where('tenant_id', $user->tenant_id)
            ->where('assigned_recruiter_id', $user->id)
            ->with('externalCompany:id,name')
            ->latest()
            ->get();

        $active = $requests->where('status', Status::CONVERTED);

        $queue = 0;
        foreach ($active as $r) {
            $queue += $this->submittableCandidates($r)->count();
        }

        $projects = $requests->map(fn ($r) => [
            'id'        => $r->id,
            'job_title' => $r->job_title,
            'company'   => $r->externalCompany?->name,
            'status'    => $r->status,
            'positions' => $r->number_of_positions,
            'counts'    => $this->funnelCounts($r),
        ])->values();

        return [
            'assigned_projects' => $projects,
            'open_positions'    => (int) $active->sum('number_of_positions'),
            'submission_queue'  => $queue,
            'pending_feedback'  => HrCandidateSubmission::whereIn('hiring_request_id', $requests->pluck('id'))
                ->where('status', SubmissionStatus::PENDING)->count(),
        ];
    }

    /** Recruiter KPI dashboard, scoped to the recruiter's assigned requests. */
    public function recruiterDashboard(User $user): array
    {
        $requests = HrHiringRequest::where('tenant_id', $user->tenant_id)
            ->where('assigned_recruiter_id', $user->id)
            ->get(['id', 'status', 'manpower_request_id', 'closed_at']);
        $reqIds = $requests->pluck('id');

        $candIds = $requests->flatMap(fn ($r) => $this->candidateIdsForRequest($r))->unique()->values();

        return [
            'total_projects'       => $requests->count(),
            'active_hiring'        => $requests->where('status', Status::CONVERTED)->count(),
            'candidates_submitted' => HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)->count(),
            'interviews'           => $candIds->isEmpty() ? 0 : HrInterviewRound::whereIn('candidate_id', $candIds)->count(),
            'offers'               => $candIds->isEmpty() ? 0 : HrOffer::whereIn('candidate_id', $candIds)->count(),
            // "Closures" = completed engagements (closed_at) — same definition as recruiterPerformance.
            'closures'             => $requests->whereNotNull('closed_at')->count(),
        ];
    }

    /* ── SLA tracking ── */

    /** The three SLA lines for a single request. */
    public function slaLines(HrHiringRequest $request): array
    {
        return [
            'assignment'      => RecruitmentSla::evaluate($request->created_at, $request->assigned_at, RecruitmentSla::ASSIGNMENT_DAYS),
            'first_candidate' => RecruitmentSla::evaluate($request->assigned_at ?? $request->created_at, $request->first_submitted_at, RecruitmentSla::FIRST_CANDIDATE_DAYS),
            'closure'         => RecruitmentSla::evaluate($request->created_at, $request->closed_at, RecruitmentSla::CLOSURE_DAYS),
        ];
    }

    /** SLA overview across requests (optionally filtered to one recruiter). */
    public function slaTracking(int $tenantId, ?int $recruiterId = null): array
    {
        return HrHiringRequest::where('tenant_id', $tenantId)
            ->when($recruiterId, fn ($q) => $q->where('assigned_recruiter_id', $recruiterId))
            ->whereNotIn('status', [Status::REJECTED])
            ->with(['externalCompany:id,name', 'assignedRecruiter:id,name'])
            ->latest()
            ->get()
            ->map(fn ($r) => [
                'id'        => $r->id,
                'job_title' => $r->job_title,
                'company'   => $r->externalCompany?->name,
                'recruiter' => $r->assignedRecruiter?->name,
                'status'    => $r->status,
                'sla'       => $this->slaLines($r),
            ])->all();
    }

    /* ── Recruiter performance ── */

    /** Per-recruiter performance metrics, all derived from existing data. */
    public function recruiterPerformance(int $tenantId, ?int $recruiterId = null): array
    {
        $recruiters = $this->candidateService->assignableRecruiters($tenantId)
            ->when($recruiterId, fn ($c) => $c->where('id', $recruiterId));

        return $recruiters->map(function ($rec) use ($tenantId) {
            $requests = HrHiringRequest::where('tenant_id', $tenantId)
                ->where('assigned_recruiter_id', $rec->id)
                ->get(['id', 'status', 'manpower_request_id', 'created_at', 'closed_at']);
            $reqIds = $requests->pluck('id');

            $completed = $requests->whereNotNull('closed_at');
            $avgDays = $completed->isEmpty() ? null
                : round($completed->avg(fn ($r) => max(0, $r->closed_at->getTimestamp() - $r->created_at->getTimestamp()) / 86400), 1);

            $candIds = $requests->flatMap(fn ($r) => $this->candidateIdsForRequest($r))->unique()->values();
            $applications = $candIds->count();
            $selected = $candIds->isEmpty() ? 0 : HrCandidate::whereIn('id', $candIds)
                ->where(fn ($q) => $q->whereIn('stage', ['Offer', 'Hired'])->orWhere('final_decision', 'Selected'))->count();

            // Delivered candidates are the population for offer/acceptance ratios,
            // so the numerator and denominator cover the same set (ratio ≤ 100%).
            $deliveredIds = HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)->pluck('candidate_id');
            $submitted = $deliveredIds->count();
            $accepted  = HrCandidateSubmission::whereIn('hiring_request_id', $reqIds)
                ->where('status', SubmissionStatus::ACCEPTED)->count();
            $offers = $deliveredIds->isEmpty() ? 0 : HrOffer::whereIn('candidate_id', $deliveredIds)->count();

            $pct = fn ($n, $d) => $d > 0 ? round($n / $d * 100, 1) : 0;

            return [
                'recruiter_id'      => $rec->id,
                'recruiter'         => $rec->name,
                'projects'          => $requests->count(),
                'closures'          => $completed->count(),
                'avg_hiring_days'   => $avgDays,
                'offer_ratio'       => $pct($offers, $submitted),      // offers per candidate delivered
                'selection_ratio'   => $pct($selected, $applications), // selected per applicant
                'client_acceptance' => $pct($accepted, $submitted),    // client-accepted per delivered
            ];
        })->values()->all();
    }

    /* ── Internal recruiter notes ── */

    public function notes(HrHiringRequest $request): Collection
    {
        return $request->recruiterNotes()->with('user:id,name')->get();
    }

    public function addNote(HrHiringRequest $request, string $body, User $user): HrRecruiterNote
    {
        $note = HrRecruiterNote::create([
            'tenant_id'         => $request->tenant_id,
            'hiring_request_id' => $request->id,
            'user_id'           => $user->id,
            'body'              => $body,
        ]);

        return $note->load('user:id,name');
    }

    public function deleteNote(HrRecruiterNote $note): void
    {
        $note->delete();
    }

    /* ── Client rating ── */

    public function submitClientRating(HrHiringRequest $request, array $data): HrClientRating
    {
        // A client can only rate an engagement that has actually started.
        if (! in_array($request->status, [Status::CONVERTED, Status::COMPLETED], true)) {
            throw new BusinessException('This request cannot be rated yet.', 422);
        }

        $rating = HrClientRating::updateOrCreate(
            ['hiring_request_id' => $request->id],
            [
                'tenant_id'           => $request->tenant_id,
                'external_company_id' => $request->external_company_id,
                'recruiter_id'        => $request->assigned_recruiter_id,
                'overall'             => $data['overall'],
                'communication'       => $data['communication'] ?? null,
                'candidate_quality'   => $data['candidate_quality'] ?? null,
                'turnaround_time'     => $data['turnaround_time'] ?? null,
                'feedback'            => $data['feedback'] ?? null,
            ],
        );
        $request->recordAudit('Client submitted a rating', null, 'Overall '.$data['overall'].'/5', ['client' => true]);
        $this->dispatchNotification($request, 'client_feedback', [
            'status'  => 'rated '.$data['overall'].'/5',
            'comment' => $data['feedback'] ?? null,
        ], null);

        return $rating;
    }

    public function clientRating(HrHiringRequest $request): ?HrClientRating
    {
        return $request->clientRating()->first();
    }

    /* ── Resume sharing (reuses ResumeService + hr_resumes disk) ── */

    public function resumeShares(HrHiringRequest $request): Collection
    {
        return $request->resumeShares()->with(['candidate:id,name', 'sharedBy:id,name'])->get();
    }

    public function shareResume(HrHiringRequest $request, int $candidateId, User $user): HrResumeShare
    {
        // The candidate must belong to THIS request's downstream flow — never an
        // arbitrary tenant candidate from another client's request (mirrors submitCandidates).
        $jobPostingIds = $this->requestJobPostingIds($request);
        $candidate = HrCandidate::where('tenant_id', $request->tenant_id)
            ->whereIn('job_posting_id', $jobPostingIds)
            ->find($candidateId);
        if (! $candidate) {
            throw new BusinessException('This candidate is not part of this hiring request.', 422);
        }
        if (! $candidate->resume_path) {
            throw new BusinessException('This candidate has no resume on file to share.', 422);
        }

        $share = HrResumeShare::firstOrCreate(
            ['hiring_request_id' => $request->id, 'candidate_id' => $candidateId],
            ['tenant_id' => $request->tenant_id, 'shared_by' => $user->id, 'shared_at' => now()],
        );
        if ($share->wasRecentlyCreated) {
            $request->recordAudit('Resume shared with client', $user, $candidate->name, ['candidate_id' => $candidateId, 'client_visible' => true]);
        }

        return $share->load(['candidate:id,name', 'sharedBy:id,name']);
    }

    /**
     * Resolve a shared resume for public download (client, no auth).
     * Streams the candidate's EXISTING resume via ResumeService — no copy is made.
     *
     * @return array{path:string,filename:string,mime:string}
     */
    public function downloadSharedResume(string $token): array
    {
        $share = HrResumeShare::where('share_token', $token)->firstOrFail();
        $candidate = $share->candidate;
        abort_if(! $candidate || ! $candidate->resume_path, 404, 'This resume is no longer available.');

        $file = $this->resumeService->resolveDownload($candidate, $share->tenant_id);

        $share->increment('download_count');
        $share->update(['downloaded_at' => now()]);
        $share->hiringRequest?->recordAudit('Client downloaded a resume', null, $candidate->name, ['candidate_id' => $candidate->id, 'client' => true]);

        return $file;
    }
}
