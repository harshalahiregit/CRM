<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseContact;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class PurchaseContactRepository extends BaseRepository
{
    protected string $modelClass = PurchaseContact::class;

    /**
     * Contacts for a single vendor within the tenant. Optional status filter +
     * free-text search across name/designation/email/mobile. Primary first.
     */
    public function filtered(int $tenantId, int $vendorId, array $filters): Collection
    {
        $query = PurchaseContact::forTenant($tenantId)->where('purchase_vendor_id', $vendorId);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', '%'.$search.'%')
                  ->orWhere('last_name', 'like', '%'.$search.'%')
                  ->orWhere('designation', 'like', '%'.$search.'%')
                  ->orWhere('department', 'like', '%'.$search.'%')
                  ->orWhere('email', 'like', '%'.$search.'%')
                  ->orWhere('mobile', 'like', '%'.$search.'%');
            });
        }

        return $query->orderByDesc('is_primary')->latest()->get();
    }
}
