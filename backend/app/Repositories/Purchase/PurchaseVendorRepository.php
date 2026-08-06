<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseVendor;
use App\Support\Purchase\PurchaseRegistrationType as RegistrationType;
use App\Repositories\BaseRepository;
use App\Support\Purchase\PurchaseVendorStatus as Status;
use Illuminate\Database\Eloquent\Collection;

class PurchaseVendorRepository extends BaseRepository
{
    protected string $modelClass = PurchaseVendor::class;

    /** Tenant-scoped, filtered + searchable listing. */
    public function filtered(int $tenantId, array $filters): Collection
    {
        $query = PurchaseVendor::forTenant($tenantId)->with('accountManager:id,name');

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['vendor_type'])) {
            $query->where('vendor_type', $filters['vendor_type']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(function ($q) use ($s) {
                $q->where('company_name', 'like', "%{$s}%")
                  ->orWhere('legal_name', 'like', "%{$s}%")
                  ->orWhere('purchase_vendor_code', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('registration_number', 'like', "%{$s}%");
            });
        }

        return $query->latest()->get();
    }

    public function findForTenant(int $id, int $tenantId): ?PurchaseVendor
    {
        return PurchaseVendor::forTenant($tenantId)
            ->with(['accountManager:id,name', 'contacts', 'onboarding'])
            ->find($id);
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => PurchaseVendor::forTenant($tenantId);

        return [
            'total'    => $base()->count(),
            'active'   => $base()->where('status', Status::ACTIVE)->count(),
            'pending'  => $base()->where('status', Status::PENDING_APPROVAL)->count(),
            'on_hold'  => $base()->where('status', Status::ON_HOLD)->count(),
            'rejected' => $base()->where('status', Status::REJECTED)->count(),
            'draft'    => $base()->where('status', Status::DRAFT)->count(),

            // Registration type is the source of truth (the stored choice, never
            // re-derived). vendor_type is only a fallback for rows written before
            // it existed — counting on vendor_type alone double-counts the legacy
            // 'Company' value and disagrees with what the vendor's own badge shows.
            'permanent' => $base()->where(fn ($q) => $q
                ->where('registration_type', RegistrationType::STANDARD)
                ->orWhere(fn ($w) => $w->whereNull('registration_type')->where('vendor_type', '!=', 'temporary'))
            )->count(),
            'temporary' => $base()->where(fn ($q) => $q
                ->where('registration_type', RegistrationType::TEMPORARY)
                ->orWhere(fn ($w) => $w->whereNull('registration_type')->where('vendor_type', 'temporary'))
            )->count(),
        ];
    }
}
