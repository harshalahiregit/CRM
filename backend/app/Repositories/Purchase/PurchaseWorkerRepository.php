<?php

namespace App\Repositories\Purchase;

use App\Models\Purchase\PurchaseWorker;
use App\Repositories\BaseRepository;

/** Purchase-owned worker repository — always vendor-scoped. */
class PurchaseWorkerRepository extends BaseRepository
{
    protected string $modelClass = PurchaseWorker::class;

    /** All workers for one Purchase vendor, newest first, with sub-record eager loads. */
    public function listForVendor(int $tenantId, int $vendorId, array $filters = [])
    {
        $query = PurchaseWorker::forTenant($tenantId)
            ->where('purchase_vendor_id', $vendorId)
            ->with(['latestMedical', 'latestInduction'])
            ->withCount(['documents', 'trainings']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('full_name', 'like', "%{$s}%")->orWhere('worker_code', 'like', "%{$s}%"));
        }

        return $query->orderByDesc('id')->get();
    }

    /** A single worker owned by the vendor (null if not owned). */
    public function findForVendor(int $id, int $tenantId, int $vendorId): ?PurchaseWorker
    {
        return PurchaseWorker::forTenant($tenantId)
            ->where('purchase_vendor_id', $vendorId)
            ->with(['documents', 'medicals', 'trainings', 'inductions', 'latestMedical', 'latestInduction'])
            ->find($id);
    }
}
