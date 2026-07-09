<?php

namespace App\Services\Hr;

use App\Models\HrJobPosting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class JobPostingService
{
    public function list(int $tenantId, array $filters): Collection
    {
        $query = HrJobPosting::where('tenant_id', $tenantId);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->get();
    }

    public function create(array $data): HrJobPosting
    {
        $job = HrJobPosting::create([...$data, 'status' => $data['status'] ?? 'Active']);

        Log::channel('hr')->info('Job posting created', ['job_posting_id' => $job->id, 'tenant_id' => $job->tenant_id]);

        return $job;
    }

    public function update(HrJobPosting $jobPosting, array $data): HrJobPosting
    {
        $jobPosting->update($data);

        Log::channel('hr')->info('Job posting updated', ['job_posting_id' => $jobPosting->id, 'tenant_id' => $jobPosting->tenant_id]);

        return $jobPosting;
    }

    public function updateStatus(HrJobPosting $jobPosting, string $status): HrJobPosting
    {
        $jobPosting->update(['status' => $status]);

        Log::channel('hr')->info('Job posting status updated', ['job_posting_id' => $jobPosting->id, 'tenant_id' => $jobPosting->tenant_id, 'status' => $status]);

        return $jobPosting;
    }

    public function updateExternalId(HrJobPosting $jobPosting, string $platform, string $externalId): array
    {
        $externalIds = $jobPosting->external_job_ids ?? [];
        $externalIds[$platform] = $externalId;

        $jobPosting->update(['external_job_ids' => $externalIds]);

        Log::channel('hr')->info('Job posting external id updated', ['job_posting_id' => $jobPosting->id, 'tenant_id' => $jobPosting->tenant_id, 'platform' => $platform]);

        return $externalIds;
    }

    public function destroy(HrJobPosting $jobPosting): void
    {
        $jobPosting->delete();

        Log::channel('hr')->info('Job posting deleted', ['job_posting_id' => $jobPosting->id, 'tenant_id' => $jobPosting->tenant_id]);
    }
}
