<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrCandidate;
use App\Repositories\BaseRepository;
use App\Support\Hr\HiringManagerFilter;

class CandidateRepository extends BaseRepository
{
    protected string $modelClass = HrCandidate::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = HrCandidate::with(['jobPosting', 'assignedRecruiter:id,name,internal_role'])
            ->where('tenant_id', $tenantId);

        if (! empty($filters['stage']) && $filters['stage'] !== 'All') {
            $query->where('stage', $filters['stage']);
        }
        // #3 — a candidate inherits the hiring manager from the job they applied
        // to. One hop: candidate → jobPosting → manpowerRequest.
        HiringManagerFilter::apply($query, $filters['hiring_manager_id'] ?? null, 'jobPosting');
        if (! empty($filters['job_posting_id'])) {
            $query->where('job_posting_id', $filters['job_posting_id']);
        }
        if (! empty($filters['source']) && $filters['source'] !== 'All') {
            $query->where('source', $filters['source']);
        }
        // A candidate has no designation of its own — it inherits one from the job
        // posting it applied to, so the filter reaches through that relation.
        if (! empty($filters['designation_name'])) {
            $designation = $filters['designation_name'];
            $query->whereHas('jobPosting', fn ($q) => $q->where('title', $designation));
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                  ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }

    public function emailExistsForJobPosting(string $email, int $jobPostingId, int $tenantId): bool
    {
        return HrCandidate::where('email', $email)
            ->where('job_posting_id', $jobPostingId)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Has this person already applied to this job (by email OR phone)? Used to
     * block duplicate Career Portal applications. Tenant-scoped.
     */
    public function existsForJobByEmailOrPhone(?string $email, ?string $phone, int $jobPostingId, int $tenantId): bool
    {
        return HrCandidate::where('tenant_id', $tenantId)
            ->where('job_posting_id', $jobPostingId)
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->where('email', $email);
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->exists();
    }

    /** Find this person's application for a job (email OR phone), tenant-scoped. */
    public function findApplicationForJob(?string $email, ?string $phone, int $jobPostingId, int $tenantId): ?HrCandidate
    {
        return HrCandidate::where('tenant_id', $tenantId)
            ->where('job_posting_id', $jobPostingId)
            ->where(function ($q) use ($email, $phone) {
                if ($email) {
                    $q->where('email', $email);
                }
                if ($phone) {
                    $q->orWhere('phone', $phone);
                }
            })
            ->latest('id')
            ->first();
    }
}
