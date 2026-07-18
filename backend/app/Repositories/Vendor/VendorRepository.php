<?php

namespace App\Repositories\Vendor;

use App\Models\Vendor\Vendor;
use App\Repositories\BaseRepository;

class VendorRepository extends BaseRepository
{
    protected string $modelClass = Vendor::class;

    public function filtered(int $tenantId, array $filters)
    {
        $query = Vendor::forTenant($tenantId)->with(['primaryContact', 'accountManager:id,name']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['vendor_type']) && $filters['vendor_type'] !== 'All') {
            $query->where('vendor_type', $filters['vendor_type']);
        }
        if (! empty($filters['category']) && $filters['category'] !== 'All') {
            $query->where('category', $filters['category']);
        }
        // 'purchase' | 'tpv' — which module's vendor list is being viewed.
        if (! empty($filters['engagement'])) {
            $query->forEngagement($filters['engagement']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', '%'.$search.'%')
                  ->orWhere('vendor_code', 'like', '%'.$search.'%')
                  ->orWhere('email', 'like', '%'.$search.'%')
                  ->orWhere('gst_number', 'like', '%'.$search.'%')
                  ->orWhere('pan_number', 'like', '%'.$search.'%');
            });
        }

        return $query->latest()->get();
    }
}
