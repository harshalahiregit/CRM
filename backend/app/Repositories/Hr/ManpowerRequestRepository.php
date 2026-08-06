<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Repositories\BaseRepository;
use App\Support\Hr\HiringManagerFilter;
use App\Support\Hr\ManpowerRequestStatus as Status;

class ManpowerRequestRepository extends BaseRepository
{
    protected string $modelClass = HrManpowerRequest::class;

    public function filtered(User $user, array $filters)
    {
        // The list stays lean — the full audit timeline is loaded only when a
        // single request is opened (see controller show()).
        $query = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->with(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'jobPosting', 'projectRef:id,name,status']);

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
        // #3 — "Filter option in every listing. Ex. HIRING MANAGER".
        // Left below the hiring-manager scope above on purpose: a hiring manager
        // filtering the list is still confined to their own requests, so this
        // narrows their view rather than widening it. `null` path = this model
        // owns the column.
        HiringManagerFilter::apply($query, $filters['hiring_manager_id'] ?? null, null);
        // Job Title is drawn from the designation master, so a designation filter
        // matches the stored position_title.
        if (! empty($filters['designation_name'])) {
            $query->where('position_title', $filters['designation_name']);
        }

        return $query->latest()->get();
    }
}
