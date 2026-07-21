<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseRequest;
use App\Repositories\BaseRepository;

class PurchaseRequestRepository extends BaseRepository
{
    protected string $modelClass = PurchaseRequest::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = PurchaseRequest::forTenant($tenantId)
            ->with(['items', 'vendor:id,vendor_code,company_name', 'requester:id,name']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department']) && $filters['department'] !== 'All') {
            $query->where('department', $filters['department']);
        }
        if (! empty($filters['priority']) && $filters['priority'] !== 'All') {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }
        if (! empty($filters['required_by'])) {
            $query->whereDate('required_by', '<=', $filters['required_by']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', '%'.$search.'%')
                  ->orWhere('pr_number', 'like', '%'.$search.'%')
                  ->orWhere('department', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }
}
