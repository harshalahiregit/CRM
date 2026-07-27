<?php

namespace App\Services\Purchase;

use App\Exceptions\BusinessException;
use App\Models\Purchase\PurchaseVendor;
use App\Models\User;
use App\Repositories\Purchase\PurchaseVendorRepository;
use App\Support\Purchase\PurchaseVendorStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The Purchase Vendor master engine — CRUD, search, statistics, activation and
 * status transitions for the Purchase module's OWN vendor entity
 * (purchase_vendors). Completely independent of the shared VendorService / TPV.
 * Reuses only generic infra (BaseRepository, Auditable trait, logging).
 */
class PurchaseVendorService
{
    public function __construct(
        private PurchaseVendorRepository $repo,
        private PurchaseVendorPortalAuthService $portalAuth,
    ) {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        return $this->repo->filtered($tenantId, $filters);
    }

    public function stats(int $tenantId): array
    {
        return $this->repo->stats($tenantId);
    }

    public function find(int $id, int $tenantId): PurchaseVendor
    {
        $vendor = $this->repo->findForTenant($id, $tenantId);
        if (! $vendor) {
            throw new BusinessException('Purchase vendor not found.', 404);
        }

        return $vendor;
    }

    public function create(array $data, User $actor): PurchaseVendor
    {
        $tenantId = $actor->tenant_id;

        $vendor = PurchaseVendor::create([
            ...$data,
            'tenant_id'            => $tenantId,
            'purchase_vendor_code' => $data['purchase_vendor_code'] ?? $this->generateCode($tenantId),
            'status'               => $data['status'] ?? Status::DRAFT,
        ]);

        $vendor->recordAudit('Purchase Vendor Created', $actor, null, ['company_name' => $vendor->company_name]);
        Log::channel('purchase')->info('Purchase vendor created', [
            'purchase_vendor_id' => $vendor->id, 'tenant_id' => $tenantId,
        ]);

        return $vendor->fresh();
    }

    public function update(PurchaseVendor $vendor, array $data, User $actor): PurchaseVendor
    {
        // The code is immutable once assigned; status changes go through updateStatus/approve.
        unset($data['purchase_vendor_code'], $data['status']);

        $vendor->update($data);
        $vendor->recordAudit('Purchase Vendor Updated', $actor, null, ['company_name' => $vendor->company_name]);

        return $vendor->fresh();
    }

    /** Activate a vendor for procurement (Draft/Pending → Active). */
    public function approve(PurchaseVendor $vendor, User $actor): PurchaseVendor
    {
        if ($vendor->status === Status::ACTIVE) {
            throw new BusinessException('This vendor is already active.');
        }

        $vendor->update([
            'status'      => Status::ACTIVE,
            'approved_at' => now(),
            'approved_by' => $actor->id,
        ]);
        $vendor->recordAudit('Purchase Vendor Activated', $actor, null, ['to' => Status::ACTIVE]);
        Log::channel('purchase')->info('Purchase vendor activated', [
            'purchase_vendor_id' => $vendor->id, 'actor_id' => $actor->id,
        ]);

        // User provisioning: activate the portal account + send credentials (audited).
        $this->portalAuth->provision($vendor, $actor);

        return $vendor->fresh();
    }

    public function updateStatus(PurchaseVendor $vendor, string $status, User $actor, ?string $remarks = null): PurchaseVendor
    {
        if (! Status::isValid($status)) {
            throw new BusinessException("Invalid vendor status: {$status}");
        }

        $vendor->update(['status' => $status, 'notes' => $remarks ?? $vendor->notes]);
        $vendor->recordAudit('Purchase Vendor Status Changed', $actor, $remarks, ['to' => $status]);

        return $vendor->fresh();
    }

    public function delete(PurchaseVendor $vendor, User $actor): void
    {
        $vendor->recordAudit('Purchase Vendor Deleted', $actor, null, ['company_name' => $vendor->company_name]);
        $vendor->delete();
    }

    /** Next PV-#### code for the tenant. */
    private function generateCode(int $tenantId): string
    {
        $seq = PurchaseVendor::withTrashed()->where('tenant_id', $tenantId)->count() + 1;

        return 'PV-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
