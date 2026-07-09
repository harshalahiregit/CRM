<?php

namespace App\Repositories\Hr;

use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Repositories\BaseRepository;

class ManpowerRequestRepository extends BaseRepository
{
    protected string $modelClass = HrManpowerRequest::class;

    public function filtered(User $user, array $filters)
    {
        $query = HrManpowerRequest::where('tenant_id', $user->tenant_id)
            ->with(['requester', 'assignedManager', 'l1Approver', 'l2Approver', 'approvalHistory.actor']);

        if ($user->isHiringManager()) {
            $query->where('assigned_manager_id', $user->id);
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
