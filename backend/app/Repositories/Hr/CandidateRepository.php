<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrCandidate;
use App\Repositories\BaseRepository;

class CandidateRepository extends BaseRepository
{
    protected string $modelClass = HrCandidate::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = HrCandidate::with('jobPosting')->where('tenant_id', $tenantId);

        if (! empty($filters['stage']) && $filters['stage'] !== 'All') {
            $query->where('stage', $filters['stage']);
        }
        if (! empty($filters['job_posting_id'])) {
            $query->where('job_posting_id', $filters['job_posting_id']);
        }
        if (! empty($filters['source']) && $filters['source'] !== 'All') {
            $query->where('source', $filters['source']);
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
}
