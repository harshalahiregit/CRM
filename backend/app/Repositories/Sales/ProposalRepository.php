<?php

namespace App\Repositories\Sales;

use App\Models\Sales\Proposal;
use App\Repositories\BaseRepository;

class ProposalRepository extends BaseRepository
{
    protected string $modelClass = Proposal::class;

    public function filtered(int $tenantId, ?string $status, ?string $search)
    {
        $query = Proposal::forTenant($tenantId)->with(['lineItems', 'assignedUser']);

        if ($status && $status !== 'All') {
            $query->ofStatus($status);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', '%'.$search.'%')
                  ->orWhere('proposal_to', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }
}
