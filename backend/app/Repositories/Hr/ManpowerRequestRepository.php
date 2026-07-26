<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Repositories\BaseRepository;
use App\Support\Hr\ManpowerRequestStatus as Status;

class ManpowerRequestRepository extends BaseRepository
{
    protected string $modelClass = HrManpowerRequest::class;

    public function filtered(User $user, array $filters)
    {
        // The list stays lean — the full audit timeline is loaded only when a
        // single request is opened (see controller show()).
        $query = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->with(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'jobPosting']);

        if ($user->isHiringManager()) {
            $query->where('assigned_manager_id', $user->id);
        }

        // HR queue: only requests that have cleared both approvals.
        if (($filters['scope'] ?? null) === 'hr_queue') {
            $query->whereIn('status', Status::HR_QUEUE);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }

        return $query->latest()->get();
    }
}
