<?php

namespace App\Repositories\Tpv;

use App\Models\Tpv\TpvContact;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class TpvContactRepository extends BaseRepository
{
    protected string $modelClass = TpvContact::class;

    /**
     * Contacts for a single vendor within the tenant, newest first. Optional
     * status filter + free-text search across name/designation/email/mobile.
     */
    public function filtered(int $tenantId, int $vendorId, array $filters): Collection
    {
        $query = TpvContact::forTenant($tenantId)->where('vendor_id', $vendorId);

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

        // Primary first, then newest — a stable, sensible default order.
        return $query->orderByDesc('is_primary')->latest()->get();
    }
}
