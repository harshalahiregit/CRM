<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Tenant;
use App\Repositories\Hr\CandidateRepository;
use App\Support\Hr\JobPostingStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Public Career Portal — unauthenticated. The tenant is resolved from the URL
 * slug (there is no logged-in user), and every query is hard-scoped to that
 * tenant, so the portal can never surface or accept data for another tenant.
 * Only jobs that are live AND explicitly on the career portal are exposed.
 */
class CareerPortalService
{
    public function __construct(
        private ResumeService $resumeService,
        private CandidateRepository $candidateRepository,
        private InterviewService $interviewService,
        private OfferService $offerService,
        private CandidateService $candidateService,
    ) {
    }

    /**
     * Resolve the portal's tenant from the URL segment. Accepts the tenant slug
     * OR its subdomain (people naturally use either), matching slug first so the
     * result is always a single, unambiguous tenant.
     */
    public function tenant(string $slug): Tenant
    {
        $tenant = Tenant::where('status', 'active')->where('slug', $slug)->first()
            ?? Tenant::where('status', 'active')->where('subdomain', $slug)->first();

        if (! $tenant) {
            throw new BusinessException('Career portal not found', 404);
        }

        return $tenant;
    }

    public function jobs(Tenant $tenant, array $filters): array
    {
        $query = $this->openJobs($tenant);

        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('department', 'like', "%{$s}%")->orWhere('location', 'like', "%{$s}%"));
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }
        if (! empty($filters['job_type']) && $filters['job_type'] !== 'All') {
            $query->where('job_type', $filters['job_type']);
        }

        return $query->latest('career_published_at')->get()->map(fn ($j) => $this->publicJob($j))->all();
    }

    public function job(Tenant $tenant, int $jobId): array
    {
        $job = $this->openJobs($tenant)->where('id', $jobId)->first();
        if (! $job) {
            throw new BusinessException('Job not found or no longer open', 404);
        }

        return $this->publicJob($job, full: true);
    }

    /**
     * Accept a public application → creates a tenant-scoped HrCandidate in the
     * "Applied" stage, linked to the posting, so it lands in the HR pipeline.
     */
    public function apply(Tenant $tenant, int $jobId, array $data, ?UploadedFile $resume): HrCandidate
    {
        $job = $this->openJobs($tenant)->where('id', $jobId)->first();
        if (! $job) {
            throw new BusinessException('This job is no longer accepting applications', 404);
        }

        // Enterprise ATS: one application per person per job. Match on email OR
        // phone so the same candidate can't re-apply under either identifier.
        if ($this->candidateRepository->existsForJobByEmailOrPhone($data['email'] ?? null, $data['phone'] ?? null, $job->id, $tenant->id)) {
            throw new BusinessException('You have already applied for this position. Track your application status on this page.', 409);
        }

        // Screening gate: every mandatory question must be answered.
        $answers = $this->normalizeAnswers($data['screening_answers'] ?? []);
        foreach ($job->screening_questions ?? [] as $q) {
            if (! empty($q['mandatory'])) {
                $a = collect($answers)->firstWhere('question_id', $q['id'] ?? null);
                $val = $a['value'] ?? null;
                if ($val === null || $val === '' || $val === []) {
                    throw new BusinessException('Please answer the required screening question: '.($q['label'] ?? ''), 422);
                }
            }
        }

        return DB::transaction(function () use ($tenant, $job, $data, $resume, $answers) {
            $candidate = HrCandidate::create([
                'tenant_id'        => $tenant->id,           // hard-scoped to the portal's tenant
                'job_posting_id'   => $job->id,
                'project_id'       => $job->project_id,       // STEP 6: inherit Project from the Job Posting
                'name'             => $data['name'],
                'email'            => $data['email'],
                'phone'            => $data['phone'],
                'location'         => $data['location'] ?? null,
                'current_company'  => $data['current_company'] ?? null,
                'experience_years' => $data['experience_years'] ?? 0,   // column is NOT NULL default 0
                'skills'           => $this->normalizeSkills($data['skills'] ?? null),
                'current_ctc'      => $data['current_ctc'] ?? null,
                'expected_ctc'     => $data['expected_ctc'] ?? null,
                'notice_period'    => $data['notice_period'] ?? null,
                'linkedin_url'     => $data['linkedin_url'] ?? null,
                'notes'            => $data['cover_note'] ?? null,
                'source'           => 'Career Portal',
                'stage'            => 'Applied',
                'final_decision'   => 'Pending',
                'applied_at'       => now(),
            ]);

            if ($resume) {
                $this->resumeService->upload($candidate, $tenant->id, $resume);
            }

            // SPK-1: run the AI application evaluation (resume/JD/question/skill
            // match + explained recommendation). Best-effort — never blocks apply.
            try {
                $this->candidateService->evaluateApplication($candidate->fresh('jobPosting'), $answers);
            } catch (\Throwable $e) {
                Log::channel('hr')->warning('Application AI evaluation failed', ['candidate_id' => $candidate->id, 'error' => $e->getMessage()]);
            }

            // Surface the application on the job's activity timeline (system actor).
            $job->recordAudit('New Application', null, $candidate->name.' applied via Career Portal', ['candidate_id' => $candidate->id]);

            Log::channel('hr')->info('Career portal application', ['tenant_id' => $tenant->id, 'job_posting_id' => $job->id, 'candidate_id' => $candidate->id]);

            return $candidate;
        });
    }

    /**
     * Public application-tracking status for a candidate (matched by email OR
     * phone) on a given job. Returns only portal-safe fields; interview/offer
     * data is derived through the existing InterviewService / candidate relations
     * so behaviour stays consistent with the HR side. Tenant-scoped throughout.
     */
    public function applicationStatus(Tenant $tenant, int $jobId, ?string $email, ?string $phone): array
    {
        $candidate = $this->candidateRepository->findApplicationForJob($email, $phone, $jobId, $tenant->id);

        if (! $candidate) {
            return ['applied' => false];
        }

        return [
            'applied'        => true,
            'reference'      => 'APP-'.$candidate->id,
            'name'           => $candidate->name,
            'stage'          => $candidate->stage,
            'final_decision' => $candidate->final_decision,
            'applied_at'     => optional($candidate->applied_at ?? $candidate->created_at)->toIso8601String(),
            'job_title'      => optional($candidate->jobPosting)->title,
            'interview'      => $this->publicInterview($candidate, $tenant),
            'offer'          => $this->publicOffer($candidate),
        ];
    }

    /** Candidate's next scheduled interview, portal-safe (online link vs venue). */
    private function publicInterview(HrCandidate $candidate, Tenant $tenant): ?array
    {
        $round = $this->interviewService->list($tenant->id, ['candidate_id' => $candidate->id])
            ->where('status', 'Scheduled')
            ->sortBy('scheduled_at')
            ->first();

        if (! $round) {
            return null;
        }

        return array_filter([
            'round_name'   => $round->round_name,
            'scheduled_at' => optional($round->scheduled_at)->toIso8601String(),
            'mode'         => $round->mode ?? 'online',
            'meet_link'    => ($round->mode ?? 'online') === 'online' ? $round->meet_link : null,
            'venue'        => $round->mode === 'offline' ? $round->venue : null,
            'status'       => $round->status,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** Candidate's offer, only once it has actually been sent to them. */
    private function publicOffer(HrCandidate $candidate): ?array
    {
        $offer = $candidate->offer;

        if (! $offer || ! in_array($offer->status, ['Sent', 'Accepted', 'Rejected'], true)) {
            return null;
        }

        return [
            'status'       => $offer->status,
            'position'     => $offer->position,
            'offered_ctc'  => $offer->offered_ctc,
            'joining_date' => optional($offer->joining_date)->toDateString(),
            'can_respond'  => $offer->status === 'Sent',
            'can_download' => ! empty($offer->letter_path),
        ];
    }

    /**
     * Require the offer's out-of-band secure token (48-char access_token from the offer
     * email) for any portal offer action — email alone must never be sufficient.
     * Matched in constant time to avoid timing oracles.
     */
    private function assertOfferToken($offer, ?string $token): void
    {
        if (! $token || ! $offer->access_token || ! hash_equals((string) $offer->access_token, (string) $token)) {
            throw new BusinessException('A valid secure offer link is required. Please use the link that was sent to your email.', 403);
        }
    }

    /**
     * Candidate accepts or declines their offer from the portal. Reuses
     * OfferService::updateStatus so the candidate stage / decision update
     * exactly as they would from the HR side.
     */
    public function respondToOffer(Tenant $tenant, int $jobId, string $email, string $action, ?string $reason, ?string $token = null): array
    {
        $candidate = $this->candidateRepository->findApplicationForJob($email, null, $jobId, $tenant->id);
        $offer     = $candidate?->offer;

        if (! $offer) {
            throw new BusinessException('No offer found for this application.', 404);
        }
        // Security: an email address is a weak secret. Require the secure offer token
        // delivered out-of-band (the offer email / portal link), matched in constant time,
        // so only the real candidate — not anyone who guesses the email — can respond.
        $this->assertOfferToken($offer, $token);
        if ($offer->status !== 'Sent') {
            throw new BusinessException('This offer can no longer be updated.', 422);
        }

        $this->offerService->updateStatus(
            $offer,
            $action === 'accept' ? 'Accepted' : 'Rejected',
            $action === 'decline' ? ($reason ?: 'Declined by candidate') : null
        );

        Log::channel('hr')->info('Career portal offer response', ['tenant_id' => $tenant->id, 'candidate_id' => $candidate->id, 'action' => $action]);

        return $this->applicationStatus($tenant, $jobId, $email, null);
    }

    /** Resolve the candidate's offer letter file for download (portal-safe). */
    public function offerLetter(Tenant $tenant, int $jobId, string $email, ?string $token = null): array
    {
        $candidate = $this->candidateRepository->findApplicationForJob($email, null, $jobId, $tenant->id);
        $offer     = $candidate?->offer;

        if (! $offer || empty($offer->letter_path) || ! in_array($offer->status, ['Sent', 'Accepted', 'Rejected'], true)) {
            throw new BusinessException('Offer letter is not available.', 404);
        }
        // Same out-of-band token requirement as respondToOffer — the letter contains CTC.
        $this->assertOfferToken($offer, $token);

        $disk = Storage::disk('local')->exists($offer->letter_path) ? 'local'
            : (Storage::disk('public')->exists($offer->letter_path) ? 'public' : null);

        if (! $disk) {
            throw new BusinessException('Offer letter is not available.', 404);
        }

        return [
            'path'     => Storage::disk($disk)->path($offer->letter_path),
            'filename' => 'Offer-Letter-'.$candidate->id.'.'.pathinfo($offer->letter_path, PATHINFO_EXTENSION),
        ];
    }

    /** Base query: this tenant's jobs that are live AND on the career portal. */
    private function openJobs(Tenant $tenant)
    {
        return HrJobPosting::where('tenant_id', $tenant->id)
            ->where('on_career_portal', true)
            ->whereIn('status', JobPostingStatus::LIVE);
    }

    /** Curated public payload — never leaks internal counts/notes/relations. */
    private function publicJob(HrJobPosting $job, bool $full = false): array
    {
        return array_filter([
            'id'                 => $job->id,
            'title'              => $job->title,
            'department'         => $job->department,
            'location'           => $job->location,
            'job_type'           => $job->job_type,
            'posting_type'       => $job->posting_type,
            'number_of_openings' => $job->number_of_openings,
            'salary_from'        => $job->salary_from,
            'salary_to'          => $job->salary_to,
            'published_at'       => $job->career_published_at ?? $job->published_at,
            'closing_date'       => $job->closing_date,
            'description'        => $full ? $job->description : null,
            'requirements'       => $full ? $job->requirements : null,
            'screening_questions' => $full ? ($job->screening_questions ?: null) : null,
        ], fn ($v) => $v !== null);
    }

    private function normalizeSkills($skills): ?array
    {
        if (is_array($skills)) {
            return array_values(array_filter($skills));
        }
        if (is_string($skills) && $skills !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $skills))));
        }

        return null;
    }

    /** Normalise screening answers (accepts a JSON string from multipart form). */
    private function normalizeAnswers($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)->map(fn ($a) => [
            'question_id' => $a['question_id'] ?? $a['id'] ?? null,
            'label'       => $a['label'] ?? null,
            'value'       => $a['value'] ?? null,
        ])->filter(fn ($a) => $a['question_id'] !== null)->values()->all();
    }
}
