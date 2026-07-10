<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrCandidate;
use App\Models\Hr\HrJobPosting;
use App\Models\Tenant;
use App\Support\Hr\JobPostingStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public Career Portal — unauthenticated. The tenant is resolved from the URL
 * slug (there is no logged-in user), and every query is hard-scoped to that
 * tenant, so the portal can never surface or accept data for another tenant.
 * Only jobs that are live AND explicitly on the career portal are exposed.
 */
class CareerPortalService
{
    public function __construct(private ResumeService $resumeService)
    {
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

        return DB::transaction(function () use ($tenant, $job, $data, $resume) {
            $candidate = HrCandidate::create([
                'tenant_id'        => $tenant->id,           // hard-scoped to the portal's tenant
                'job_posting_id'   => $job->id,
                'name'             => $data['name'],
                'email'            => $data['email'],
                'phone'            => $data['phone'],
                'location'         => $data['location'] ?? null,
                'current_company'  => $data['current_company'] ?? null,
                'experience_years' => $data['experience_years'] ?? null,
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

            // Surface the application on the job's activity timeline (system actor).
            $job->recordAudit('New Application', null, $candidate->name.' applied via Career Portal', ['candidate_id' => $candidate->id]);

            Log::channel('hr')->info('Career portal application', ['tenant_id' => $tenant->id, 'job_posting_id' => $job->id, 'candidate_id' => $candidate->id]);

            return $candidate;
        });
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
}
